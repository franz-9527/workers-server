<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 无人成交自动取消的单订单事务。
 *
 * 生产扫描、退款提交/查单仍由 SchedulerService 负责；
 * testing scoped 入口只创建当前实例的取消/TEST_INTERNAL 退款事实，不调用微信退款。
 */
final class AutoCancelProcessor
{
    private const JOB='auto_cancel_unaccepted';

    private const TEST_SCENARIOS=[
        'ordinary.construction.multi_quote.waiting.zero_quotes',
        'ordinary.construction.multi_quote.waiting.one_quote',
        'ordinary.construction.multi_quote.waiting.three_quotes',
        'ordinary.construction.multi_quote.waiting.far_distance',
        'ordinary.construction.multi_quote.waiting.pending_worker',
        'ordinary.construction.single_grab.waiting.open',
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    public function cancelProduction(int $orderId,int $candidateVersion,int $schedulerRunId): bool
    {
        if($orderId<1||$candidateVersion<1||$schedulerRunId<1)return false;

        return$this->db->transaction(function(Database $db)use($orderId,$candidateVersion,$schedulerRunId):bool{
            $order=$db->fetch(
                "SELECT * FROM orders
                 WHERE id=:id
                   AND COALESCE(is_demo,0)=0
                   AND lifecycle_status='active'
                   AND published_at IS NOT NULL
                   AND COALESCE(quote_deadline_at,DATE_ADD(published_at,INTERVAL 7 DAY))<=UTC_TIMESTAMP()
                 FOR UPDATE",
                ['id'=>$orderId]
            );
            if(!$order
                ||(int)$order['version']!==$candidateVersion
                ||$this->hasAcceptedQuoteOrWorkerAt($db,$order,time())){
                return false;
            }

            $version=max(1,(int)$order['version']);
            if(!$this->claim($db,$schedulerRunId,$orderId,$version))return false;

            $result=$this->applyLocked($db,$order);
            $payload=[
                'message'=>$result['reason'],
                'refund_status'=>$result['refund_status'],
                'refund_amount_cents'=>$result['refund_amount_cents'],
            ];
            $this->recordProductionEvent($db,$orderId,$version,$payload);
            $this->createNotification(
                $db,
                (int)$order['user_id'],
                $result['outstanding_refund_cents']>0
                    ?'订单已自动取消，系统正在原路退还预约费，微信确认成功前不会标记为已退款。'
                    :'订单公开满 7 天仍未接受报价，系统已自动取消。',
                $orderId
            );
            $this->completeClaim(
                $db,
                $orderId,
                $version,
                ['refund_pending_review'=>$result['outstanding_refund_cents']>0]
            );
            return true;
        });
    }

    /** @return array<string,mixed> */
    public function cancelTestInstance(int $instanceId,bool $advanceClock=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('自动取消实例执行只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        return$this->db->transaction(function(Database $db)use($instanceId,$advanceClock):array{
            $instance=$db->fetch('SELECT * FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            $order=$instance&&!empty($instance['order_id'])
                ?$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>(int)$instance['order_id']])
                :null;

            if(!$instance
                ||!in_array((string)($instance['scenario_key']??''),self::TEST_SCENARIOS,true)
                ||(string)$instance['build_status']!=='built'
                ||!empty($instance['archived_at'])
                ||!empty($instance['deleted_at'])
                ||!$order
                ||(int)$order['is_demo']!==1
                ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
                ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
                throw new RuntimeException('自动取消测试实例边界失败');
            }

            if((string)($order['order_source_type']??'consumer')!=='consumer'
                ||(string)($order['workflow_profile']??'construction')!=='construction'
                ||(string)($order['lifecycle_status']??'')!=='active'
                ||(string)($order['publish_status']??'')!=='published'
                ||!in_array((string)($order['quote_status']??''),['waiting','quoting'],true)
                ||(string)($order['assignment_status']??'')!=='unassigned'
                ||empty($order['published_at'])){
                throw new RuntimeException('订单尚未处于可自动取消的公开未成交状态');
            }

            $deadline=$this->deadline($order);
            $deadlineTs=strtotime($deadline.' UTC');
            if($deadlineTs===false)throw new RuntimeException('自动取消截止时间无效');
            $asOf=$advanceClock?max(time(),$deadlineTs+1):time();
            if($asOf<$deadlineTs)throw new RuntimeException('当前测试时钟尚未到自动取消期限');
            if($this->hasAcceptedQuoteOrWorkerAt($db,$order,$asOf)){
                throw new RuntimeException('订单已有有效成交/抢单占位，禁止自动取消');
            }

            $this->assertOwnedTestingPayment($db,$instance,$order);
            $result=$this->applyLocked($db,$order);

            $version=max(1,(int)$order['version']);
            $payload=[
                'message'=>$result['reason'],
                'refund_status'=>$result['refund_status'],
                'refund_amount_cents'=>$result['refund_amount_cents'],
                'test_instance_id'=>$instanceId,
                'clock_fixture'=>$advanceClock,
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
                'external_refund_executed'=>false,
            ];
            $eventKey=substr(
                'test-auto-cancel:'.$instanceId.':'.self::JOB.':v'.$version,
                0,
                128
            );
            $eventId=$db->insert(
                "INSERT INTO order_events(
                    order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at
                 ) VALUES(
                    :order_id,'auto_cancelled_7d',:event_key,'system',NULL,:payload,UTC_TIMESTAMP()
                 )",
                [
                    'order_id'=>(int)$order['id'],
                    'event_key'=>$eventKey,
                    'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
            OrderNotificationService::queuePersistedEvent(
                $db,
                (int)$order['id'],
                $eventId,
                'auto_cancelled_7d',
                $payload
            );
            $this->createNotification(
                $db,
                (int)$order['user_id'],
                $result['outstanding_refund_cents']>0
                    ?'订单已自动取消；测试环境只登记内部退款事实，不会向微信提交退款。'
                    :'订单公开满 7 天仍未接受报价，系统已自动取消。',
                (int)$order['id']
            );

            return[
                'cancelled'=>true,
                'scope'=>'test_instance',
                'test_instance_id'=>$instanceId,
                'order_id'=>(int)$order['id'],
                'deadline_at'=>$deadline,
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
                'clock_fixture'=>$advanceClock,
                'reason_code'=>$result['reason_code'],
                'refund_id'=>$result['refund_id'],
                'refund_amount_cents'=>$result['refund_amount_cents'],
                'refund_status'=>$result['refund_status'],
                'lifecycle_status'=>$result['lifecycle_status'],
                'external_refund_executed'=>false,
                'global_scheduler_scan_verified'=>false,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function applyLocked(Database $db,array $order): array
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge($db,true))->schedule((int)$order['id']);
        }
        $orderId=(int)$order['id'];
        $validQuoteCount=(int)$db->fetchValue(
            "SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",
            ['id'=>$orderId]
        );
        $reasonCode=$validQuoteCount===0?'seven_days_no_valid_quote':'seven_days_unaccepted_quote';
        $reason=$validQuoteCount===0
            ?'订单公开满 7 天仍无有效报价，系统自动取消。'
            :'订单公开满 7 天用户仍未接受报价，系统自动取消。';

        $payment=$db->fetch(
            "SELECT id,amount_cents
             FROM payment_orders
             WHERE order_id=:id
               AND business_type IN ('order_booking','booking_fee')
               AND status='paid'
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE",
            ['id'=>$orderId]
        );
        $bookingCap=max(0,(int)$order['booking_fee_cents']);
        if($payment&&$bookingCap===0)$bookingCap=(int)$payment['amount_cents'];
        $bookingCap=$payment?min((int)$payment['amount_cents'],$bookingCap):0;

        $existingRefundCents=0;
        $outstandingRefundCents=0;
        $succeededRefundCents=0;
        if($payment){
            $refundRows=$db->fetchAll(
                "SELECT id,amount_cents,status
                 FROM refunds
                 WHERE payment_order_id=:payment_id
                   AND status NOT IN ('failed','closed','cancelled','rejected')
                 FOR UPDATE",
                ['payment_id'=>$payment['id']]
            );
            foreach($refundRows as$refundRow){
                $amount=max(0,(int)$refundRow['amount_cents']);
                $existingRefundCents+=$amount;
                if((string)$refundRow['status']==='succeeded')$succeededRefundCents+=$amount;
                else$outstandingRefundCents+=$amount;
            }
        }

        $runnerFeeCents=(int)$db->fetchValue(
            "SELECT COALESCE(SUM(l.amount_cents),0)
             FROM worker_balance_ledger l
             INNER JOIN disputes d ON d.id=l.business_id
             WHERE l.business_type='dispute'
               AND l.entry_type='run_empty_fee'
               AND l.status='effective'
               AND d.order_id=:order_id",
            ['order_id'=>$orderId]
        );
        $refundAmount=max(0,$bookingCap-$existingRefundCents-$runnerFeeCents);
        $outstandingRefundCents+=$refundAmount;
        $lifecycle=$outstandingRefundCents>0?'cancel_approved_pending_refund':'cancelled';

        if($refundAmount>0){
            $refundStatus='pending_review';
        }elseif($outstandingRefundCents>0){
            $refundStatus=in_array((string)$order['refund_status'],['none','failed'],true)
                ?'pending_review'
                :(string)$order['refund_status'];
        }elseif($succeededRefundCents>0){
            $refundStatus='succeeded';
        }else{
            $refundStatus='none';
        }

        $requestId=$db->insert(
            "INSERT INTO order_cancel_requests(
                public_id,order_id,applicant_type,applicant_id,request_type,
                order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,
                requested_at,closed_at,created_at,updated_at
             ) VALUES(
                :public_id,:order_id,'system',NULL,'auto_timeout_cancel',
                :snapshot,:reason_code,:reason,'approved',:refund,
                UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_id'=>\public_id(),
                'order_id'=>$orderId,
                'snapshot'=>json_encode([
                    'audit_status'=>$order['audit_status'],
                    'publish_status'=>$order['publish_status'],
                    'quote_status'=>$order['quote_status'],
                    'assignment_status'=>$order['assignment_status'],
                    'fulfillment_status'=>$order['fulfillment_status'],
                    'payment_status'=>$order['payment_status'],
                    'refund_status'=>$order['refund_status'],
                    'lifecycle_status'=>$order['lifecycle_status'],
                    'version'=>(int)$order['version'],
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'reason_code'=>$reasonCode,
                'reason'=>$reason,
                'refund'=>$refundAmount,
            ]
        );

        $refundId=null;
        if($payment&&$refundAmount>0){
            $refundId=$db->insert(
                "INSERT INTO refunds(
                    public_no,payment_order_id,order_id,cancel_request_id,amount_cents,
                    reason,status,requested_by_admin_id,requested_at,created_at,updated_at
                 ) VALUES(
                    :public_no,:payment_id,:order_id,:request_id,:amount,
                    :reason,'pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_no'=>\business_no('RF'),
                    'payment_id'=>(int)$payment['id'],
                    'order_id'=>$orderId,
                    'request_id'=>$requestId,
                    'amount'=>$refundAmount,
                    'reason'=>$reason,
                ]
            );
        }

        $db->execute(
            "INSERT INTO order_cancel_decisions(
                cancel_request_id,order_id,decision,responsibility,reason,
                refund_amount_cents,run_empty_fee_cents,refund_id,decided_by,decided_at,created_at
             ) VALUES(
                :request_id,:order_id,'approved','timeout_unaccepted',:reason,
                :refund,0,:refund_id,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'request_id'=>$requestId,
                'order_id'=>$orderId,
                'reason'=>$reason,
                'refund'=>$refundAmount,
                'refund_id'=>$refundId,
            ]
        );

        $db->execute(
            "UPDATE orders
             SET lifecycle_status=:lifecycle,
                 publish_status='hidden',
                 quote_status='expired',
                 assignment_status=IF(assignment_status='unassigned','unassigned','cancelled'),
                 refund_status=:refund_status,
                 cancelled_at=UTC_TIMESTAMP(),
                 closed_at=IF(:close_now=1,COALESCE(closed_at,UTC_TIMESTAMP()),closed_at),
                 version=version+1,
                 updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND lifecycle_status='active'",
            [
                'lifecycle'=>$lifecycle,
                'refund_status'=>$refundStatus,
                'close_now'=>$outstandingRefundCents>0?0:1,
                'id'=>$orderId,
            ]
        );
        $db->execute(
            "UPDATE quotes SET status='expired',updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id AND status='active'",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE quote_selections
             SET status='released',released_at=COALESCE(released_at,UTC_TIMESTAMP())
             WHERE order_id=:id AND status='pending_payment'",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE assignments
             SET status='cancelled',
                 cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
                 updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id AND status='offered'",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE invite_tokens SET status='revoked'
             WHERE order_id=:id AND status='active'",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE order_role_grants
             SET status='revoked',
                 revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())
             WHERE order_id=:id AND status='active'",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE order_deadlines
             SET status=IF(deadline_type IN ('quote_expiry','auto_cancel_unaccepted'),'completed','cancelled'),
                 processed_at=UTC_TIMESTAMP(),
                 updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id
               AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        );

        return[
            'request_id'=>$requestId,
            'refund_id'=>$refundId,
            'valid_quote_count'=>$validQuoteCount,
            'reason_code'=>$reasonCode,
            'reason'=>$reason,
            'refund_amount_cents'=>$refundAmount,
            'outstanding_refund_cents'=>$outstandingRefundCents,
            'refund_status'=>$refundStatus,
            'lifecycle_status'=>$lifecycle,
        ];
    }

    private function assertOwnedTestingPayment(Database $db,array $instance,array $order): void
    {
        $payment=$db->fetch(
            "SELECT p.*,tf.id test_fact_id,tf.instance_id test_instance_id,tf.simulated_provider
             FROM payment_orders p
             LEFT JOIN test_payment_facts tf
               ON tf.payment_order_id=p.id
              AND tf.instance_id=:instance
             WHERE p.order_id=:order_id
               AND p.business_type IN ('order_booking','booking_fee')
               AND p.status='paid'
             ORDER BY p.id DESC
             LIMIT 1
             FOR UPDATE",
            ['instance'=>(int)$instance['id'],'order_id'=>(int)$order['id']]
        );
        if(!$payment){
            if((int)($order['booking_fee_cents']??0)>0){
                throw new RuntimeException('测试订单缺少可证明归属的已支付预约费');
            }
            return;
        }

        $owned=(int)($payment['test_fact_id']??0)>0
            &&(int)($payment['test_instance_id']??0)===(int)$instance['id']
            &&(string)($payment['simulated_provider']??'')==='internal_test'
            &&(string)($payment['app_id']??'')==='TEST_INTERNAL'
            &&(string)($payment['mch_id']??'')==='TEST_INTERNAL'
            &&strpos((string)($payment['payer_openid']??''),'TEST_INTERNAL:')===0
            &&empty($payment['wechat_prepay_id'])
            &&empty($payment['wechat_transaction_id']);
        if(!$owned)throw new RuntimeException('预约费不是当前实例拥有的 TEST_INTERNAL 支付，禁止自动取消测试');
    }

    private function hasAcceptedQuoteOrWorkerAt(Database $db,array $order,int $asOf): bool
    {
        $rows=$db->fetchAll(
            "SELECT assignment_type,status,accept_deadline_at
             FROM assignments
             WHERE order_id=:id
               AND status IN ('accepted','completed','offered')",
            ['id'=>(int)$order['id']]
        );
        foreach($rows as$row){
            $status=(string)$row['status'];
            if(in_array($status,['accepted','completed'],true))return true;
            if($status==='offered'&&(string)$row['assignment_type']==='single_grab'){
                if(empty($row['accept_deadline_at']))return true;
                $deadline=strtotime((string)$row['accept_deadline_at'].' UTC');
                if($deadline!==false&&$deadline>$asOf)return true;
            }
        }
        return false;
    }

    private function deadline(array $order): string
    {
        if(!empty($order['quote_deadline_at']))return(string)$order['quote_deadline_at'];
        $published=strtotime((string)($order['published_at']??'').' UTC');
        if($published===false)throw new RuntimeException('订单发布时间无效');
        return gmdate('Y-m-d H:i:s',$published+7*86400);
    }

    private function claim(Database $db,int $runId,int $orderId,int $version): bool
    {
        return$db->execute(
            "INSERT IGNORE INTO scheduled_job_claims(
                job_name,business_key,business_version,scheduler_run_id,status,claimed_at
             ) VALUES(
                :job,:business_key,:version,:run_id,'claimed',UTC_TIMESTAMP()
             )",
            [
                'job'=>self::JOB,
                'business_key'=>'order:'.$orderId,
                'version'=>max(1,$version),
                'run_id'=>$runId,
            ]
        )===1;
    }

    /** @param array<string,mixed> $payload */
    private function completeClaim(Database $db,int $orderId,int $version,array $payload): void
    {
        $params=[
            'job'=>self::JOB,
            'business_key'=>'order:'.$orderId,
            'version'=>max(1,$version),
        ];
        $db->execute(
            "UPDATE scheduled_job_claims
             SET status='completed',completed_at=UTC_TIMESTAMP(),result_json=:result
             WHERE job_name=:job
               AND business_key=:business_key
               AND business_version=:version
               AND status='claimed'",
            $params+[
                'result'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        $db->execute(
            "UPDATE scheduled_job_failures
             SET resolved_at=UTC_TIMESTAMP()
             WHERE job_name=:job
               AND business_key=:business_key
               AND business_version=:version
               AND resolved_at IS NULL",
            $params
        );
    }

    /** @param array<string,mixed> $payload */
    private function recordProductionEvent(Database $db,int $orderId,int $version,array $payload): void
    {
        $eventKey=substr(
            'scheduler:'.self::JOB.':order:'.$orderId.':v'.max(1,$version),
            0,
            128
        );
        $db->execute(
            "INSERT IGNORE INTO order_events(
                order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at
             ) VALUES(
                :order_id,'auto_cancelled_7d',:event_key,'system',NULL,:payload,UTC_TIMESTAMP()
             )",
            [
                'order_id'=>$orderId,
                'event_key'=>$eventKey,
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue($db,$orderId,$eventKey,'auto_cancelled_7d',$payload);
    }

    private function createNotification(Database $db,int $userId,string $content,int $orderId): void
    {
        if($userId<1)return;
        $db->execute(
            "INSERT INTO notifications(
                public_id,recipient_type,recipient_id,channel,template_key,title,content,
                data_json,status,scheduled_at,created_at
             ) VALUES(
                :public_id,'user',:recipient_id,'in_app','order_auto_cancelled',
                '订单已自动取消',:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_id'=>\public_id(),
                'recipient_id'=>$userId,
                'content'=>$content,
                'data'=>json_encode(['order_id'=>$orderId],JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}

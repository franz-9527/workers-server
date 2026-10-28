<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 补资料期限逐笔处理器。
 *
 * 生产扫描器只负责发现候选与失败退避；本类负责单 deadline 的临期提醒/超时取消。
 * testing 入口只处理当前 test_instance，不提交或查询微信退款。
 */
final class SupplementTimeoutProcessor
{
    private const JOB='supplement_timeout';
    private const TEST_SCENARIO='ordinary.construction.proxy.draft';

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db=$db;
    }

    /**
     * @return string|null reminded / expired / null
     */
    public function processProduction(int $deadlineId,int $candidateVersion,int $schedulerRunId): ?string
    {
        if($deadlineId<1||$candidateVersion<1||$schedulerRunId<1)return null;

        return$this->db->transaction(function(Database $db)use($deadlineId,$candidateVersion,$schedulerRunId):?string{
            $deadline=$db->fetch(
                "SELECT * FROM order_deadlines
                 WHERE id=:id
                   AND deadline_type='audit_supplement_timeout'
                   AND status='pending'
                 FOR UPDATE",
                ['id'=>$deadlineId]
            );
            if(!$deadline)return null;
            $order=$db->fetch(
                "SELECT * FROM orders
                 WHERE id=:id
                   AND COALESCE(is_demo,0)=0
                 FOR UPDATE",
                ['id'=>(int)$deadline['order_id']]
            );
            if(!$order
                ||(int)$order['version']!==$candidateVersion
                ||(string)$order['lifecycle_status']!=='active'
                ||(string)$order['audit_status']!=='supplement_required'){
                return null;
            }

            $due=$this->timestamp((string)$deadline['due_at']);
            if($due>time()){
                if(!empty($deadline['reminded_at']))return null;
                $changed=$db->execute(
                    "UPDATE order_deadlines
                     SET reminded_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='pending' AND reminded_at IS NULL",
                    ['id'=>$deadlineId]
                );
                if($changed!==1)return null;
                $this->recordEvent(
                    $db,(int)$order['id'],'supplement_deadline_reminder',
                    'reminder',(int)$order['version'],(int)$deadline['id'],
                    ['message'=>'订单补资料期限即将到期，请尽快补充资料，否则订单将取消并退款。','due_at'=>$deadline['due_at']],
                    false
                );
                $this->resolveFailures($db,(int)$order['id'],max(1,(int)$order['version']));
                return'reminded';
            }

            $version=max(1,(int)$order['version']);
            if(!$this->claim($db,$schedulerRunId,(int)$order['id'],$version))return null;
            $result=$this->expireLocked($db,$order,$deadline);
            $this->recordEvent(
                $db,(int)$order['id'],'supplement_timeout_refunded',
                'expired',$version,(int)$deadline['id'],
                [
                    'refund_amount_cents'=>$result['refund_amount_cents'],
                    'message'=>'补充资料超时，订单已自动取消并进入退款流程。',
                ],
                false
            );
            $this->completeClaim($db,(int)$order['id'],$version,[
                'cancel_request_id'=>$result['cancel_request_id'],
                'refund_id'=>$result['refund_id'],
                'refund_amount_cents'=>$result['refund_amount_cents'],
            ]);
            return'expired';
        });
    }

    /** @return array<string,mixed>|null */
    public function inspectTestInstance(int $instanceId): ?array
    {
        if($instanceId<1)return null;
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$instanceId]);
        if(!$instance
            ||(string)($instance['scenario_key']??'')!==self::TEST_SCENARIO
            ||(string)($instance['build_status']??'')!=='built'
            ||!empty($instance['archived_at'])
            ||!empty($instance['deleted_at'])
            ||empty($instance['order_id'])){
            return null;
        }
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        if(!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0
            ||(string)($order['order_source_type']??'consumer')!=='consumer'
            ||(string)($order['workflow_profile']??'construction')!=='construction'
            ||(string)$order['lifecycle_status']!=='active'
            ||(string)$order['audit_status']!=='supplement_required'){
            return null;
        }
        $deadline=$this->db->fetch(
            "SELECT * FROM order_deadlines
             WHERE order_id=:order
               AND deadline_type='audit_supplement_timeout'
               AND status='pending'
             ORDER BY id DESC LIMIT 1",
            ['order'=>(int)$order['id']]
        );
        if(!$deadline)return null;
        try{$this->assertTestingPaymentOwnership($instance,$order);}
        catch(RuntimeException $exception){return null;}
        return[
            'deadline_id'=>(int)$deadline['id'],
            'due_at'=>(string)$deadline['due_at'],
            'reminded_at'=>$deadline['reminded_at']??null,
            'order_version'=>(int)$order['version'],
        ];
    }

    /** @return array<string,mixed> */
    public function expireTestInstance(int $instanceId,bool $advanceClock=true): array
    {
        if(!TestingEnvironmentPolicy::active())throw new RuntimeException('补资料超时实例执行只允许 testing 环境');
        if($instanceId<1)throw new RuntimeException('测试实例无效');

        return$this->db->transaction(function(Database $db)use($instanceId,$advanceClock):array{
            $instance=$db->fetch('SELECT * FROM test_instances WHERE id=:id FOR UPDATE',['id'=>$instanceId]);
            $order=$instance&&!empty($instance['order_id'])
                ?$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>(int)$instance['order_id']])
                :null;
            if(!$instance
                ||(string)($instance['scenario_key']??'')!==self::TEST_SCENARIO
                ||(string)($instance['build_status']??'')!=='built'
                ||!empty($instance['archived_at'])
                ||!empty($instance['deleted_at'])
                ||!$order
                ||(int)($order['is_demo']??0)!==1
                ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
                ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
                throw new RuntimeException('补资料超时测试实例边界失败');
            }
            if((string)($order['order_source_type']??'consumer')!=='consumer'
                ||(string)($order['workflow_profile']??'construction')!=='construction'
                ||(string)$order['lifecycle_status']!=='active'
                ||(string)$order['audit_status']!=='supplement_required'){
                throw new RuntimeException('订单尚未处于待补资料状态');
            }

            $deadline=$db->fetch(
                "SELECT * FROM order_deadlines
                 WHERE order_id=:order
                   AND deadline_type='audit_supplement_timeout'
                   AND status='pending'
                 ORDER BY id DESC LIMIT 1
                 FOR UPDATE",
                ['order'=>(int)$order['id']]
            );
            if(!$deadline)throw new RuntimeException('当前订单缺少待处理的补资料期限');

            $due=$this->timestamp((string)$deadline['due_at']);
            $asOf=$advanceClock?max(time(),$due+1):time();
            if($asOf<$due)throw new RuntimeException('当前测试时钟尚未到补资料截止时间');
            $this->assertTestingPaymentOwnership($instance,$order);

            $version=max(1,(int)$order['version']);
            $result=$this->expireLocked($db,$order,$deadline);
            $payload=[
                'refund_amount_cents'=>$result['refund_amount_cents'],
                'test_instance_id'=>$instanceId,
                'clock_fixture'=>$advanceClock,
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
                'external_refund_executed'=>false,
                'message'=>'补充资料超时，测试订单已自动取消并登记内部退款事实。',
            ];
            $eventId=$this->recordEvent(
                $db,(int)$order['id'],'supplement_timeout_refunded',
                'test-expired',$version,(int)$deadline['id'],$payload,true,$instanceId
            );

            return[
                'expired'=>true,
                'scope'=>'test_instance',
                'test_instance_id'=>$instanceId,
                'order_id'=>(int)$order['id'],
                'deadline_id'=>(int)$deadline['id'],
                'deadline_at'=>(string)$deadline['due_at'],
                'as_of'=>gmdate('Y-m-d H:i:s',$asOf),
                'clock_fixture'=>$advanceClock,
                'cancel_request_id'=>$result['cancel_request_id'],
                'cancel_decision_id'=>$result['cancel_decision_id'],
                'refund_id'=>$result['refund_id'],
                'refund_amount_cents'=>$result['refund_amount_cents'],
                'refund_status'=>$result['refund_status'],
                'lifecycle_status'=>$result['lifecycle_status'],
                'event_id'=>$eventId,
                'external_refund_executed'=>false,
                'global_scheduler_scan_verified'=>false,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function expireLocked(Database $db,array $order,array $deadline): array
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge($db,true))->schedule((int)$order['id']);
        }
        $orderId=(int)$order['id'];
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
        $refundAmount=$payment?(int)$payment['amount_cents']:0;
        if($payment){
            $allocated=(int)$db->fetchValue(
                "SELECT COALESCE(SUM(amount_cents),0)
                 FROM refunds
                 WHERE payment_order_id=:id
                   AND status NOT IN ('failed','closed','cancelled','rejected')",
                ['id'=>(int)$payment['id']]
            );
            $refundAmount=max(0,$refundAmount-$allocated);
        }

        $lifecycle=$refundAmount>0?'cancel_approved_pending_refund':'cancelled';
        $refundStatus=$refundAmount>0?'pending_review':'none';
        $db->execute(
            "UPDATE orders
             SET audit_status='rejected',
                 audit_note='补充资料超时，订单自动取消并退款',
                 supplement_due_at=NULL,
                 lifecycle_status=:lifecycle,
                 publish_status='hidden',
                 quote_status='cancelled',
                 assignment_status='cancelled',
                 fulfillment_status='cancelled',
                 refund_status=:refund_status,
                 cancelled_at=UTC_TIMESTAMP(),
                 closed_at=IF(:close_now=1,COALESCE(closed_at,UTC_TIMESTAMP()),closed_at),
                 updated_at=UTC_TIMESTAMP(),
                 version=version+1
             WHERE id=:id",
            [
                'lifecycle'=>$lifecycle,
                'refund_status'=>$refundStatus,
                'close_now'=>$refundAmount>0?0:1,
                'id'=>$orderId,
            ]
        );
        $db->execute(
            "UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id AND status IN ('active','selected')",
            ['id'=>$orderId]
        );
        $db->execute(
            "UPDATE assignments
             SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id AND status IN ('offered','accepted')",
            ['id'=>$orderId]
        );
        if($db->tableExists('invite_tokens')){
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'",['id'=>$orderId]);
        }
        if($db->tableExists('order_role_grants')){
            $db->execute(
                "UPDATE order_role_grants
                 SET status='revoked',revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())
                 WHERE order_id=:id AND status='active'",
                ['id'=>$orderId]
            );
        }
        $db->execute(
            "UPDATE order_deadlines
             SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        );

        $requestId=$db->insert(
            "INSERT INTO order_cancel_requests(
                public_id,order_id,applicant_type,applicant_id,request_type,
                order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,
                requested_at,closed_at,created_at,updated_at
             ) VALUES(
                :public_id,:order_id,'system',NULL,'supplement_timeout',
                :snapshot,'supplement_timeout','补充资料超时，系统自动取消','approved',:refund,
                UTC_TIMESTAMP(),IF(:close_now=1,UTC_TIMESTAMP(),NULL),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_id'=>\public_id(),
                'order_id'=>$orderId,
                'snapshot'=>json_encode($order,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'refund'=>$refundAmount,
                'close_now'=>$refundAmount>0?0:1,
            ]
        );

        $refundId=null;
        if($payment&&$refundAmount>0){
            $refundId=$db->insert(
                "INSERT INTO refunds(
                    public_no,payment_order_id,order_id,cancel_request_id,amount_cents,
                    reason,status,requested_at,created_at,updated_at
                 ) VALUES(
                    :public_id,:payment_id,:order_id,:request_id,:amount,
                    '补充资料超时自动退款','pending_review',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_id'=>\business_no('RF'),
                    'payment_id'=>(int)$payment['id'],
                    'order_id'=>$orderId,
                    'request_id'=>$requestId,
                    'amount'=>$refundAmount,
                ]
            );
        }

        $decisionId=$db->insert(
            "INSERT INTO order_cancel_decisions(
                cancel_request_id,order_id,decision,responsibility,reason,
                refund_amount_cents,run_empty_fee_cents,refund_id,decided_at,created_at
             ) VALUES(
                :request_id,:order_id,'approved','platform','补充资料超时，系统自动取消',
                :refund,0,:refund_id,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'request_id'=>$requestId,
                'order_id'=>$orderId,
                'refund'=>$refundAmount,
                'refund_id'=>$refundId,
            ]
        );

        return[
            'cancel_request_id'=>$requestId,
            'cancel_decision_id'=>$decisionId,
            'refund_id'=>$refundId,
            'refund_amount_cents'=>$refundAmount,
            'refund_status'=>$refundStatus,
            'lifecycle_status'=>$lifecycle,
        ];
    }

    private function assertTestingPaymentOwnership(array $instance,array $order): void
    {
        $payments=$this->db->fetchAll(
            "SELECT p.*,tf.id test_fact_id,tf.instance_id test_instance_id,tf.simulated_provider
             FROM payment_orders p
             LEFT JOIN test_payment_facts tf
               ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE p.order_id=:order
               AND p.business_type IN ('order_booking','booking_fee')
               AND p.status='paid'
             ORDER BY p.id",
            ['instance'=>(int)$instance['id'],'order'=>(int)$order['id']]
        );
        if(count($payments)!==1)throw new RuntimeException('补资料超时测试必须且只能存在一笔已支付预约费');
        $payment=$payments[0];
        $owned=(int)($payment['test_fact_id']??0)>0
            &&(int)($payment['test_instance_id']??0)===(int)$instance['id']
            &&(string)($payment['simulated_provider']??'')==='internal_test'
            &&(string)($payment['app_id']??'')==='TEST_INTERNAL'
            &&(string)($payment['mch_id']??'')==='TEST_INTERNAL'
            &&strpos((string)($payment['payer_openid']??''),'TEST_INTERNAL:')===0
            &&empty($payment['wechat_prepay_id'])
            &&empty($payment['wechat_transaction_id']);
        if(!$owned)throw new RuntimeException('预约费不是当前实例拥有的 TEST_INTERNAL 支付');
    }

    private function timestamp(string $value): int
    {
        $ts=strtotime($value.' UTC');
        if($ts===false)throw new RuntimeException('补资料期限时间无效');
        return$ts;
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
                'version'=>$version,
                'run_id'=>$runId,
            ]
        )===1;
    }

    private function resolveFailures(Database $db,int $orderId,int $version): void
    {
        $db->execute(
            "UPDATE scheduled_job_failures
             SET resolved_at=UTC_TIMESTAMP()
             WHERE job_name=:job
               AND business_key=:business_key
               AND business_version=:version
               AND resolved_at IS NULL",
            ['job'=>self::JOB,'business_key'=>'order:'.$orderId,'version'=>$version]
        );
    }

    /** @param array<string,mixed> $result */
    private function completeClaim(Database $db,int $orderId,int $version,array $result): void
    {
        $params=['job'=>self::JOB,'business_key'=>'order:'.$orderId,'version'=>$version];
        $db->execute(
            "UPDATE scheduled_job_claims
             SET status='completed',completed_at=UTC_TIMESTAMP(),result_json=:result
             WHERE job_name=:job AND business_key=:business_key AND business_version=:version AND status='claimed'",
            $params+['result'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        );
        $db->execute(
            "UPDATE scheduled_job_failures
             SET resolved_at=UTC_TIMESTAMP()
             WHERE job_name=:job AND business_key=:business_key AND business_version=:version AND resolved_at IS NULL",
            $params
        );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function recordEvent(
        Database $db,
        int $orderId,
        string $eventType,
        string $phase,
        int $version,
        int $deadlineId,
        array $payload,
        bool $persisted,
        int $instanceId=0
    ): int {
        $eventKey=$persisted
            ?'test-supplement-timeout:'.$instanceId.':'.$phase.':v'.$version.':d'.$deadlineId
            :'scheduler:'.self::JOB.':'.$phase.':order:'.$orderId.':v'.$version.':d'.$deadlineId;
        $eventId=$db->insert(
            "INSERT IGNORE INTO order_events(
                order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at
             ) VALUES(
                :order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP()
             )",
            [
                'order_id'=>$orderId,
                'event_type'=>$eventType,
                'event_key'=>substr($eventKey,0,128),
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        if($eventId>0){
            if($persisted){
                OrderNotificationService::queuePersistedEvent($db,$orderId,$eventId,$eventType,$payload);
            }else{
                OrderNotificationService::queue($db,$orderId,substr($eventKey,0,128),$eventType,$payload);
            }
        }
        return$eventId;
    }
}

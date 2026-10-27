<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** scoped auto_cancel 的跨场景公共后置检查。 */
final class DatabaseTestCenterAutoCancelVerificationRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
    }

    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return$this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function cleanupPreview(string $publicId): array{return$this->inner->cleanupPreview($publicId);}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array{return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function verify(string $publicId,int $adminId): array
    {
        $instance=$this->db->fetch(
            'SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',
            ['id'=>trim($publicId)]
        );
        if(!$instance||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);

        $latest=$this->db->fetch(
            "SELECT id,public_id,action_key,actor_role,operation_mode
             FROM test_runs
             WHERE instance_id=:instance
               AND action_key='system_auto_cancel'
               AND result='passed'
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException(
                '自动取消检查只允许 testing 环境。',
                'TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',
                403
            );
        }

        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        if(!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new TestCenterException('目标订单不属于自动取消测试实例。','TEST_AUTO_CANCEL_VERIFY_BOUNDARY',409);
        }

        $orderId=(int)$order['id'];
        $request=$this->db->fetch(
            'SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',
            ['id'=>$orderId]
        );
        $decisions=$this->db->fetchAll(
            'SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id',
            ['id'=>$orderId]
        );
        $refunds=$this->db->fetchAll(
            "SELECT r.*,p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id,
                    tf.instance_id test_instance_id,tf.simulated_provider
             FROM refunds r
             JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN test_payment_facts tf
               ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE r.order_id=:order
             ORDER BY r.id",
            ['instance'=>(int)$instance['id'],'order'=>$orderId]
        );
        $decision=count($decisions)===1?$decisions[0]:null;
        $refund=count($refunds)===1?$refunds[0]:null;
        $requested=max(0,(int)($request['requested_refund_cents']??0));
        $quoteCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM quotes WHERE order_id=:id',['id'=>$orderId]);
        $activeQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",['id'=>$orderId]);
        $pendingSelections=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quote_selections WHERE order_id=:id AND status='pending_payment'",['id'=>$orderId]);
        $offeredAssignments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='offered'",['id'=>$orderId]);
        $activeInvites=$this->db->tableExists('invite_tokens')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM invite_tokens WHERE order_id=:id AND status='active'",['id'=>$orderId]):0;
        $activeGrants=$this->db->tableExists('order_role_grants')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_role_grants WHERE order_id=:id AND status='active'",['id'=>$orderId]):0;
        $pendingDeadlines=$this->db->tableExists('order_deadlines')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_deadlines
             WHERE order_id=:id AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        ):0;
        $eventCount=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_events
             WHERE order_id=:id
               AND event_type='auto_cancelled_7d'
               AND event_key LIKE :event_key",
            [
                'id'=>$orderId,
                'event_key'=>'test-auto-cancel:'.(int)$instance['id'].':auto_cancel_unaccepted:v%',
            ]
        );
        $claimCount=$this->db->tableExists('scheduled_job_claims')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM scheduled_job_claims
             WHERE job_name='auto_cancel_unaccepted' AND business_key=:business",
            ['business'=>'order:'.$orderId]
        ):0;
        $notificationCount=$this->db->tableExists('notifications')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM notifications
             WHERE template_key='order_auto_cancelled'
               AND recipient_type='user'
               AND recipient_id=:user
               AND data_json LIKE :order_json",
            ['user'=>(int)$order['user_id'],'order_json'=>'%"order_id":'.$orderId.'%']
        ):0;
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);

        $expectedReason=$quoteCount===0?'seven_days_no_valid_quote':'seven_days_unaccepted_quote';
        $refundShape=$requested>0
            ?($refund
                &&count($refunds)===1
                &&(int)$refund['cancel_request_id']===(int)($request['id']??0)
                &&(int)$refund['amount_cents']===$requested
                &&(string)$refund['status']==='pending_review'
                &&(int)($refund['test_instance_id']??0)===(int)$instance['id']
                &&(string)($refund['simulated_provider']??'')==='internal_test'
                &&(string)$refund['app_id']==='TEST_INTERNAL'
                &&(string)$refund['mch_id']==='TEST_INTERNAL'
                &&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0
                &&empty($refund['wechat_refund_id'])
                &&empty($refund['wechat_prepay_id'])
                &&empty($refund['wechat_transaction_id']))
            :count($refunds)===0;

        $checks=[
            ['structure','auto_cancel_test_domain','当前实例精确绑定 demo 且非 BXDEMOHALL',
                (int)$order['is_demo']===1
                &&(string)$order['public_no']===(string)$instance['order_no_snapshot']
                &&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,
                'order='.(string)$order['public_no']],
            ['audit','auto_cancel_system_action','动作以 system / real_service 记录',
                (string)$latest['actor_role']==='system'
                &&(string)$latest['operation_mode']==='real_service',
                'role='.(string)$latest['actor_role'].'; mode='.(string)$latest['operation_mode']],
            ['cancel','single_system_request','只有一条系统超时取消请求且原因与报价事实一致',
                $request
                &&(string)$request['applicant_type']==='system'
                &&empty($request['applicant_id'])
                &&(string)$request['request_type']==='auto_timeout_cancel'
                &&(string)$request['reason_code']===$expectedReason
                &&(string)$request['status']==='approved'
                &&!empty($request['closed_at']),
                $request?'reason='.(string)$request['reason_code'].'; status='.(string)$request['status']:'missing'],
            ['cancel','single_timeout_decision','系统取消只存在一条 timeout_unaccepted approved 决定',
                $decision
                &&count($decisions)===1
                &&(int)$decision['cancel_request_id']===(int)($request['id']??0)
                &&(string)$decision['decision']==='approved'
                &&(string)$decision['responsibility']==='timeout_unaccepted'
                &&empty($decision['decided_by'])
                &&(int)$decision['refund_amount_cents']===$requested
                &&($requested===0?empty($decision['refund_id']):($refund&&(int)$decision['refund_id']===(int)$refund['id'])),
                'decisions='.count($decisions).'; refund='.(int)($decision['refund_amount_cents']??0)],
            ['funds','owned_internal_refund','有退款时只能登记当前实例 TEST_INTERNAL pending_review，不能执行微信退款',
                $refundShape,
                'requested='.$requested.'; refunds='.count($refunds).'; status='.(string)($refund['status']??'none')],
            ['state','auto_cancel_terminal_state','订单已隐藏、报价过期并进入取消/待退款终态',
                in_array((string)$order['lifecycle_status'],['cancel_approved_pending_refund','cancelled'],true)
                &&(string)$order['publish_status']==='hidden'
                &&(string)$order['quote_status']==='expired'
                &&!empty($order['cancelled_at'])
                &&($requested>0?(string)$order['refund_status']==='pending_review':true),
                'lifecycle='.(string)$order['lifecycle_status'].'; publish='.(string)$order['publish_status'].'; quote='.(string)$order['quote_status'].'; refund='.(string)$order['refund_status']],
            ['state','quotes_and_reservations_closed','活动报价、待付款报价预占和 offered assignment 均已收口',
                $activeQuotes===0&&$pendingSelections===0&&$offeredAssignments===0,
                'active_quotes='.$activeQuotes.'; pending_selections='.$pendingSelections.'; offered_assignments='.$offeredAssignments],
            ['security','access_grants_revoked','邀请和订单角色授权均无 active 残留',
                $activeInvites===0&&$activeGrants===0,
                'invites='.$activeInvites.'; grants='.$activeGrants],
            ['deadline','auto_cancel_deadlines_closed','自动取消后没有 pending/blocked/claimed 期限',
                $pendingDeadlines===0,
                'pending='.$pendingDeadlines],
            ['event','auto_cancel_instance_event','只存在当前实例 keyed 的自动取消事件',
                $eventCount===1,
                'events='.$eventCount],
            ['safety','no_global_auto_cancel_claim','scoped 自动取消不伪造全局 scheduler claim',
                $claimCount===0,
                'claims='.$claimCount],
            ['notification','auto_cancel_in_app_notice','用户站内自动取消通知已建立',
                $notificationCount>=1,
                'notifications='.$notificationCount],
            ['safety','no_external_outbox','demo 自动取消不得产生真实外部消息 outbox',
                $outbox===0,
                'outbox='.$outbox],
        ];

        return$this->store(
            (int)$instance['id'],
            (int)$latest['id'],
            (string)$latest['public_id'],
            $checks,
            $adminId
        );
    }

    private function outboxCount(int $orderId,string $publicNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM message_outbox
             WHERE payload_json LIKE :id
                OR payload_json LIKE :no
                OR business_key LIKE :business",
            [
                'id'=>'%"order_id":'.$orderId.'%',
                'no'=>'%'.$publicNo.'%',
                'business'=>'%'.$publicNo.'%',
            ]
        );
    }

    /** @param array<int,array<int,mixed>> $checks */
    private function store(int $instanceId,int $sourceRunId,string $sourceRunPublic,array $checks,int $adminId): array
    {
        $public=\public_id();
        $started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(
                public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,
                request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at
             ) VALUES(
                :public,:instance,'verify_auto_cancel','admin','inspect',:idem,
                :digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public'=>$public,
                'instance'=>$instanceId,
                'idem'=>'verify-auto-cancel:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$sourceRunPublic.'|system_auto_cancel'),
                'request'=>\request_id(),
                'before'=>json_encode([
                    'source_run_id'=>$sourceRunId,
                    'source_run_public'=>$sourceRunPublic,
                    'latest_action'=>'system_auto_cancel',
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'admin'=>$adminId,
            ]
        );

        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);
        $failed=0;
        foreach($checks as$check){
            [$group,$key,$expected,$passed,$actual]=$check;
            if(!$passed)$failed++;
            $this->db->insert(
                "INSERT INTO test_assertion_results(
                    instance_id,run_id,assertion_group,assertion_key,status,
                    expected_text,actual_text,details_json,created_at
                 ) VALUES(
                    :instance,:run,:group_name,:assertion_key,:status,
                    :expected,:actual,:details,UTC_TIMESTAMP()
                 )",
                [
                    'instance'=>$instanceId,
                    'run'=>$runId,
                    'group_name'=>$group,
                    'assertion_key'=>$key,
                    'status'=>$passed?'passed':'failed',
                    'expected'=>$expected,
                    'actual'=>$actual,
                    'details'=>json_encode(['latest_action'=>'system_auto_cancel'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
        }

        $status=$failed===0?'passed':'failed';
        $this->db->execute(
            "UPDATE test_instances
             SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1
             WHERE id=:id",
            ['status'=>$status,'id'=>$instanceId]
        );
        $this->db->execute(
            "UPDATE test_runs
             SET result=:status,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'status'=>$status,
                'summary'=>json_encode([
                    'source_run_id'=>$sourceRunId,
                    'latest_action'=>'system_auto_cancel',
                    'assertions'=>count($checks),
                    'failed'=>$failed,
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'duration'=>(int)round((microtime(true)-$started)*1000),
                'id'=>$runId,
            ]
        );

        return[
            'status'=>$status,
            'total'=>count($checks),
            'failed'=>$failed,
            'run_public_id'=>$public,
            'mode'=>'auto_cancel',
            'latest_real_action'=>'system_auto_cancel',
        ];
    }
}

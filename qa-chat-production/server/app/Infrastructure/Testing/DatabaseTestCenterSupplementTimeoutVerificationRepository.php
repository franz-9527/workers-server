<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** system_supplement_timeout 后置条件检查。 */
final class DatabaseTestCenterSupplementTimeoutVerificationRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner)
    {
        $this->db=$db;$this->inner=$inner;
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
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);
        if(!$instance||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);
        $run=$this->db->fetch(
            "SELECT * FROM test_runs
             WHERE instance_id=:instance
               AND action_key='system_supplement_timeout'
               AND result='passed'
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$run)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('补资料超时检查只允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);

        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        if(!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new TestCenterException('目标订单不属于补资料超时测试实例。','TEST_SUPPLEMENT_TIMEOUT_VERIFY_BOUNDARY',409);
        }
        $orderId=(int)$order['id'];
        $requests=$this->db->fetchAll("SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id",['id'=>$orderId]);
        $request=count($requests)===1?$requests[0]:null;
        $decisions=$this->db->fetchAll("SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id",['id'=>$orderId]);
        $decision=count($decisions)===1?$decisions[0]:null;
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
        $refund=count($refunds)===1?$refunds[0]:null;
        $requested=max(0,(int)($request['requested_refund_cents']??0));
        $pendingDeadlines=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_deadlines
             WHERE order_id=:id AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        );
        $eventCount=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_events
             WHERE order_id=:id
               AND event_type='supplement_timeout_refunded'
               AND event_key LIKE :event_key",
            ['id'=>$orderId,'event_key'=>'test-supplement-timeout:'.(int)$instance['id'].':test-expired:v%']
        );
        $claimCount=$this->db->tableExists('scheduled_job_claims')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM scheduled_job_claims
             WHERE job_name='supplement_timeout' AND business_key=:key",
            ['key'=>'order:'.$orderId]
        ):0;
        $outbox=$this->db->tableExists('message_outbox')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM message_outbox
             WHERE topic='wechat.refund.request'
               AND (business_key=:key OR payload_json LIKE :order_no)",
            ['key'=>'refund:'.(int)($refund['id']??0),'order_no'=>'%'.(string)$order['public_no'].'%']
        ):0;

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
            ['structure','supplement_timeout_test_domain','当前实例精确绑定 demo 且非 BXDEMOHALL',
                (int)$order['is_demo']===1&&(string)$order['public_no']===(string)$instance['order_no_snapshot']&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,
                'order='.(string)$order['public_no']],
            ['audit','supplement_timeout_system_action','超时动作以 system / real_service 记录',
                (string)$run['actor_role']==='system'&&(string)$run['operation_mode']==='real_service',
                'role='.(string)$run['actor_role'].'; mode='.(string)$run['operation_mode']],
            ['cancel','supplement_timeout_request','存在唯一系统补资料超时取消请求',
                $request
                &&count($requests)===1
                &&(string)$request['applicant_type']==='system'
                &&empty($request['applicant_id'])
                &&(string)$request['request_type']==='supplement_timeout'
                &&(string)$request['reason_code']==='supplement_timeout'
                &&(string)$request['status']==='approved'
                &&($requested>0?empty($request['closed_at']):!empty($request['closed_at'])),
                $request?'status='.(string)$request['status'].'; refund='.$requested:'missing'],
            ['cancel','supplement_timeout_decision','存在唯一 platform approved 系统决定',
                $decision
                &&count($decisions)===1
                &&(int)$decision['cancel_request_id']===(int)($request['id']??0)
                &&(string)$decision['decision']==='approved'
                &&(string)$decision['responsibility']==='platform'
                &&empty($decision['decided_by'])
                &&(int)$decision['refund_amount_cents']===$requested
                &&($requested===0?empty($decision['refund_id']):($refund&&(int)$decision['refund_id']===(int)$refund['id'])),
                'decisions='.count($decisions)],
            ['funds','supplement_timeout_internal_refund','有退款时只登记当前实例 TEST_INTERNAL pending_review',
                $refundShape,
                'requested='.$requested.'; refunds='.count($refunds)],
            ['state','supplement_timeout_order_closed','订单审核驳回且履约、发布、报价均已收口',
                (string)$order['audit_status']==='rejected'
                &&in_array((string)$order['lifecycle_status'],['cancel_approved_pending_refund','cancelled'],true)
                &&(string)$order['publish_status']==='hidden'
                &&(string)$order['quote_status']==='cancelled'
                &&(string)$order['assignment_status']==='cancelled'
                &&(string)$order['fulfillment_status']==='cancelled'
                &&empty($order['supplement_due_at'])
                &&!empty($order['cancelled_at'])
                &&($requested>0?(string)$order['refund_status']==='pending_review':(string)$order['refund_status']==='none'),
                'audit='.(string)$order['audit_status'].'; life='.(string)$order['lifecycle_status'].'; refund='.(string)$order['refund_status']],
            ['deadline','supplement_timeout_deadlines_closed','订单没有 pending/blocked/claimed deadline',
                $pendingDeadlines===0,'pending='.$pendingDeadlines],
            ['event','supplement_timeout_instance_event','存在且只存在一条当前实例超时事件',
                $eventCount===1,'events='.$eventCount],
            ['safety','supplement_timeout_no_global_claim','scoped 动作不伪造全局 scheduler claim',
                $claimCount===0,'claims='.$claimCount],
            ['safety','supplement_timeout_no_external_refund','scoped 动作不写真实微信退款 outbox',
                $outbox===0,'outbox='.$outbox],
        ];

        return$this->store((int)$instance['id'],(int)$run['id'],(string)$run['public_id'],$checks,$adminId);
    }

    /** @param array<int,array<int,mixed>> $checks */
    private function store(int $instanceId,int $sourceRunId,string $sourceRunPublic,array $checks,int $adminId): array
    {
        $public=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(
                public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,
                request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at
             ) VALUES(
                :public,:instance,'verify_supplement_timeout','admin','inspect',:idem,
                :digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public'=>$public,'instance'=>$instanceId,
                'idem'=>'verify-supplement-timeout:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$sourceRunPublic.'|system_supplement_timeout'),
                'request'=>\request_id(),
                'before'=>json_encode(['source_run_id'=>$sourceRunId,'source_run_public'=>$sourceRunPublic],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
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
                    'instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,
                    'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,
                    'details'=>json_encode(['latest_action'=>'system_supplement_timeout'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
        }
        $status=$failed===0?'passed':'failed';
        $this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);
        $this->db->execute(
            "UPDATE test_runs
             SET result=:status,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'status'=>$status,
                'summary'=>json_encode(['source_run_id'=>$sourceRunId,'latest_action'=>'system_supplement_timeout','assertions'=>count($checks),'failed'=>$failed],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId,
            ]
        );
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$public,'mode'=>'supplement_timeout','latest_real_action'=>'system_supplement_timeout'];
    }
}

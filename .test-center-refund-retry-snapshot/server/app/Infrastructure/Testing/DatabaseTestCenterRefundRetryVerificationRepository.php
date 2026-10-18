<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** system_refund_retry_probe 的公共后置检查。 */
final class DatabaseTestCenterRefundRetryVerificationRepository implements TestCenterRepository
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

        $run=$this->db->fetch(
            "SELECT * FROM test_runs
             WHERE instance_id=:instance
               AND action_key='system_refund_retry_probe'
               AND result='passed'
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$run)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active()){
            throw new TestCenterException('退款重试探针检查只允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        }

        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        if(!$order
            ||(int)($order['is_demo']??0)!==1
            ||(string)$order['public_no']!==(string)$instance['order_no_snapshot']
            ||strpos((string)$order['public_no'],'BXDEMOHALL')===0){
            throw new TestCenterException('目标订单不属于退款重试测试实例。','TEST_REFUND_RETRY_VERIFY_BOUNDARY',409);
        }

        $after=json_decode((string)($run['after_summary_json']??''),true);
        $business=is_array($after['business']??null)?$after['business']:[];
        $refundId=(int)($business['refund_id']??0);
        $refund=$refundId>0?$this->db->fetch(
            "SELECT r.*,p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id,
                    tf.instance_id test_instance_id,tf.simulated_provider
             FROM refunds r
             JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN test_payment_facts tf
               ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE r.id=:refund AND r.order_id=:order",
            [
                'instance'=>(int)$instance['id'],
                'refund'=>$refundId,
                'order'=>(int)$order['id'],
            ]
        ):null;
        $externalOutbox=$this->db->tableExists('message_outbox')?(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM message_outbox
             WHERE topic='wechat.refund.request'
               AND (business_key=:key OR payload_json LIKE :refund_no)",
            [
                'key'=>'refund:'.$refundId,
                'refund_no'=>'%'.(string)($business['out_refund_no']??'').'%',
            ]
        ):0;

        $checks=[
            ['structure','refund_retry_test_domain','实例精确绑定 demo 且非 BXDEMOHALL',
                (int)$order['is_demo']===1
                &&(string)$order['public_no']===(string)$instance['order_no_snapshot']
                &&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,
                'order='.(string)$order['public_no']],
            ['audit','refund_retry_system_probe','动作以 system / simulated_external 记录',
                (string)$run['actor_role']==='system'
                &&(string)$run['operation_mode']==='simulated_external',
                'role='.(string)$run['actor_role'].'; mode='.(string)$run['operation_mode']],
            ['retry','refund_retry_business_result','探针记录 provider 受理前网络失败且保持可重试',
                (string)($business['provider_simulation']??'')==='network_timeout_before_acceptance'
                &&!empty($business['retryable'])
                &&empty($business['external_refund_executed'])
                &&empty($business['global_scan_executed']),
                'simulation='.(string)($business['provider_simulation']??'').'; retryable='.(int)!empty($business['retryable'])],
            ['funds','refund_retry_internal_payment','退款原支付仍属于当前 TEST_INTERNAL 实例',
                $refund
                &&(int)($refund['test_instance_id']??0)===(int)$instance['id']
                &&(string)($refund['simulated_provider']??'')==='internal_test'
                &&(string)$refund['app_id']==='TEST_INTERNAL'
                &&(string)$refund['mch_id']==='TEST_INTERNAL'
                &&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0
                &&empty($refund['wechat_prepay_id'])
                &&empty($refund['wechat_transaction_id']),
                $refund?'refund='.(int)$refund['id']:'missing'],
            ['state','refund_retry_status_restored','模拟 provider 失败后退款状态和退款号恢复原值',
                $refund
                &&(string)$refund['public_no']===(string)($business['out_refund_no']??'')
                &&(string)$refund['status']===(string)($business['original_status']??'')
                &&(string)($business['final_status']??'')===(string)($business['original_status']??''),
                $refund?'status='.(string)$refund['status'].'; no='.(string)$refund['public_no']:'missing'],
            ['safety','refund_retry_no_wechat_id','探针不生成微信退款标识',
                $refund&&empty($refund['wechat_refund_id'])&&empty($business['wechat_refund_id']),
                'wechat_refund_id='.(string)($refund['wechat_refund_id']??'')],
            ['safety','refund_retry_no_external_outbox','探针不写真实微信退款 outbox',
                $externalOutbox===0,
                'outbox='.$externalOutbox],
            ['state','refund_retry_order_not_faked_processing','订单不被模拟动作伪装成微信 processing',
                (string)$order['refund_status']===(string)($business['order_refund_status']??''),
                'order_refund_status='.(string)$order['refund_status']],
        ];

        return$this->store((int)$instance['id'],(int)$run['id'],(string)$run['public_id'],$checks,$adminId);
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
                :public,:instance,'verify_refund_retry','admin','inspect',:idem,
                :digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public'=>$public,
                'instance'=>$instanceId,
                'idem'=>'verify-refund-retry:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$sourceRunPublic.'|system_refund_retry_probe'),
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
                    'details'=>json_encode(['latest_action'=>'system_refund_retry_probe'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
        }
        $status=$failed===0?'passed':'failed';
        $this->db->execute(
            "UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
            ['status'=>$status,'id'=>$instanceId]
        );
        $this->db->execute(
            "UPDATE test_runs
             SET result=:status,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'status'=>$status,
                'summary'=>json_encode(['source_run_id'=>$sourceRunId,'latest_action'=>'system_refund_retry_probe','assertions'=>count($checks),'failed'=>$failed],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'duration'=>(int)round((microtime(true)-$started)*1000),
                'id'=>$runId,
            ]
        );
        return[
            'status'=>$status,
            'total'=>count($checks),
            'failed'=>$failed,
            'run_public_id'=>$public,
            'mode'=>'refund_retry_probe',
            'latest_real_action'=>'system_refund_retry_probe',
        ];
    }
}

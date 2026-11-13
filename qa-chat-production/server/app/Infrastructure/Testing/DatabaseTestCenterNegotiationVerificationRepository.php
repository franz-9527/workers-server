<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第三段：加价协商、拒绝和平台介入的后置条件验证。 */
final class DatabaseTestCenterNegotiationVerificationRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return$this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function cleanupPreview(string $publicId): array{return$this->inner->cleanupPreview($publicId);}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array{return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function verify(string $publicId,int $adminId): array
    {
        $publicId=trim($publicId);
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
        if(!$instance||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);
        $latest=$this->db->fetch(
            "SELECT action_key,public_id FROM test_runs
             WHERE instance_id=:instance AND operation_mode='real_service' AND result='passed'
               AND action_key IN ('worker_change_request','customer_reject_change','customer_escalate_change')
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('协商推进检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);

        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        if((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于可执行协商检查的数据域。','TEST_VERIFY_ORDER_BOUNDARY',409);

        $started=microtime(true);$runPublic=\public_id();
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify_negotiation','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-negotiation:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId.'|'.(string)$latest['action_key']),'request_id'=>\request_id(),'before_json'=>$this->json(['latest_real_action'=>(string)$latest['action_key'],'latest_real_run'=>(string)$latest['public_id']]),'admin'=>$adminId]
        );

        $selectedQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='selected'",['id'=>$orderId]);
        $acceptedAssignments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='accepted'",['id'=>$orderId]);
        $change=$this->db->fetch('SELECT * FROM change_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
        $changeCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM change_requests WHERE order_id=:id',['id'=>$orderId]);
        $confirmation=$change?$this->db->fetch('SELECT * FROM change_confirmations WHERE change_request_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$change['id']]):null;
        $confirmationCount=$change?(int)$this->db->fetchValue('SELECT COUNT(*) FROM change_confirmations WHERE change_request_id=:id',['id'=>(int)$change['id']]):0;
        $dispute=$this->db->fetch("SELECT * FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        $activeDisputeCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' AND status IN ('negotiating','open','pending','processing')",['id'=>$orderId]);
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);

        $checks=[
            ['structure','test_domain','is_demo=1 且非审核样例',(int)$order['is_demo']===1&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,'is_demo='.(int)$order['is_demo'].'; order='.(string)$order['public_no']],
            ['structure','ordinary_construction','consumer / construction / multi_quote',(string)$order['order_source_type']==='consumer'&&(string)$order['workflow_profile']==='construction'&&(string)$order['dispatch_mode']==='multi_quote',implode('/',[(string)$order['order_source_type'],(string)$order['workflow_profile'],(string)$order['dispatch_mode']])],
            ['progress','deal_preserved','成交报价和 accepted assignment 保持唯一',$selectedQuotes===1&&$acceptedAssignments===1,'selected_quotes='.$selectedQuotes.'; accepted_assignments='.$acceptedAssignments],
            ['progress','assignment_bound','selected_quote / assigned_worker / current_assignment 均已绑定',(int)($order['selected_quote_id']??0)>0&&(int)($order['assigned_worker_id']??0)>0&&(int)($order['current_assignment_id']??0)>0,'quote='.(int)($order['selected_quote_id']??0).'; worker='.(int)($order['assigned_worker_id']??0).'; assignment='.(int)($order['current_assignment_id']??0)],
            ['safety','no_external_outbox','演示协商不得产生短信/订阅/企微外部发件箱',$outbox===0,'outbox='.$outbox],
            ['negotiation','single_change','当前第三段只允许 1 个加价事项',$changeCount===1,'change_count='.$changeCount],
            ['negotiation','change_owned_worker','加价事项属于当前成交师傅',$change&&(int)$change['worker_id']===(int)$order['assigned_worker_id'],'change_worker='.(int)($change['worker_id']??0).'; assigned='.(int)($order['assigned_worker_id']??0)],
        ];

        $action=(string)$latest['action_key'];
        if($action==='worker_change_request'){
            $checks[]=['negotiation','change_pending','加价申请 status=pending 且金额有效',$change&&(string)$change['status']==='pending'&&(int)$change['added_amount_cents']>0,'status='.(string)($change['status']??'missing').'; added='.(int)($change['added_amount_cents']??0)];
            $checks[]=['negotiation','no_confirmation_yet','客户尚未响应',$confirmationCount===0,'confirmations='.$confirmationCount];
            $checks[]=['intervention','not_opened_early','协商未拒绝前不得生成平台争议',$activeDisputeCount===0&&in_array((string)($order['dispute_status']??'none'),['','none','resolved'],true),'active_disputes='.$activeDisputeCount.'; order_dispute='.(string)($order['dispute_status']??'none')];
        }elseif($action==='customer_reject_change'){
            $checks[]=['negotiation','change_rejected','客户拒绝后 change status=rejected',$change&&(string)$change['status']==='rejected','status='.(string)($change['status']??'missing')];
            $checks[]=['negotiation','rejection_confirmation','恰有 1 条当前客户 rejected 确认',$confirmationCount===1&&$confirmation&&(string)$confirmation['decision']==='rejected'&&(int)$confirmation['user_id']===(int)$order['user_id'],'confirmations='.$confirmationCount.'; decision='.(string)($confirmation['decision']??'').'; user='.(int)($confirmation['user_id']??0)];
            $checks[]=['intervention','rejection_does_not_auto_escalate','拒绝只开放介入，不自动生成争议',$activeDisputeCount===0&&in_array((string)($order['dispute_status']??'none'),['','none','resolved'],true),'active_disputes='.$activeDisputeCount.'; order_dispute='.(string)($order['dispute_status']??'none')];
        }else{
            $checks[]=['negotiation','rejected_change_preserved','升级介入后原加价仍保持 rejected',$change&&(string)$change['status']==='rejected','status='.(string)($change['status']??'missing')];
            $checks[]=['intervention','fee_dispute_opened','恰有 1 条 open 的 fee_negotiation 争议',$activeDisputeCount===1&&$dispute&&(string)$dispute['status']==='open'&&(string)$dispute['dispute_type']==='fee_negotiation','active='.$activeDisputeCount.'; status='.(string)($dispute['status']??'').'; type='.(string)($dispute['dispute_type']??'')];
            $checks[]=['intervention','raised_by_customer','平台介入由当前订单客户发起',$dispute&&(string)$dispute['raised_by_type']==='user'&&(int)$dispute['raised_by_id']===(int)$order['user_id'],'raised_by='.(string)($dispute['raised_by_type']??'').':'.(int)($dispute['raised_by_id']??0)];
            $checks[]=['intervention','change_reference_preserved','争议描述保留原加价事项引用',$dispute&&$change&&strpos((string)$dispute['description'],(string)$change['public_id'])!==false,'change='.(string)($change['public_id']??'').'; description='.(string)($dispute['description']??'')];
            $checks[]=['intervention','order_intervention_state','订单 dispute_status=open 且从公开流隐藏',(string)($order['dispute_status']??'')==='open'&&(string)($order['publish_status']??'')==='hidden','dispute='.(string)($order['dispute_status']??'').'; publish='.(string)($order['publish_status']??'')];
        }
        return$this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,$action);
    }

    /** @param array<int,array<int,mixed>> $checks @return array<string,mixed> */
    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started,string $latestAction): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as $check){
            [$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;
            $this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",[
                'instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['latest_real_action'=>$latestAction]),
            ]);
        }
        $status=$failed===0?'passed':'failed';$duration=(int)round((microtime(true)-$started)*1000);
        $this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['latest_real_action'=>$latestAction,'assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'negotiation_progress','latest_real_action'=>$latestAction];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',[
            'id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%',
        ]);
    }

    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** single_grab 成交唯一性与竞争失败后置条件。 */
final class DatabaseTestCenterSingleGrabVerificationRepository implements TestCenterRepository
{
    private const SCENARIO='ordinary.construction.single_grab.waiting.open';
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
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);
        if(!$instance||(string)($instance['scenario_key']??'')!==self::SCENARIO||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);
        $latest=$this->db->fetch("SELECT action_key,public_id,error_code FROM test_runs WHERE instance_id=:instance AND result='passed' AND action_key IN ('worker_grab','worker_grab_competitor') ORDER BY id DESC LIMIT 1",['instance'=>(int)$instance['id']]);
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('单抢推进检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order||(int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于可验证单抢测试数据。','TEST_VERIFY_ORDER_BOUNDARY',409);

        $started=microtime(true);$runPublic=\public_id();
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify_single_grab','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-single-grab:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId.'|'.(string)$latest['action_key']),'request_id'=>\request_id(),'before_json'=>$this->json(['latest_real_action'=>(string)$latest['action_key'],'latest_real_run'=>(string)$latest['public_id']]),'admin'=>$adminId]
        );

        $payment=$this->db->fetch("SELECT p.*,tf.simulated_provider FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND tf.business_type='order_booking' AND p.order_id=:order_id LIMIT 1",['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
        $selectedQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='selected'",['id'=>$orderId]);
        $activeQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='active'",['id'=>$orderId]);
        $versionCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quote_versions qv JOIN quotes q ON q.id=qv.quote_id AND qv.version_no=q.current_version WHERE q.order_id=:id AND q.status='selected'",['id'=>$orderId]);
        $assignments=(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>$orderId]);
        $accepted=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND assignment_type='single_grab' AND status='accepted'",['id'=>$orderId]);
        $history=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignment_history ah JOIN assignments a ON a.id=ah.assignment_id WHERE a.order_id=:id AND ah.action='accepted'",['id'=>$orderId]);
        $rules=(int)$this->db->fetchValue('SELECT COUNT(*) FROM commission_rule_versions WHERE order_id=:id',['id'=>$orderId]);
        $winner=(int)($order['assigned_worker_id']??0);
        $winnerOwned=$winner>0?(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:instance AND tp.worker_id=:worker AND tp.expected_status='approved' AND w.verification_status='approved' AND w.cloud_record_mode='demo_local'",['instance'=>(int)$instance['id'],'worker'=>$winner]):0;
        $selected=$this->db->fetch("SELECT q.amount_cents,a.final_amount_cents_snapshot,a.worker_id,a.assignment_type FROM quotes q JOIN assignments a ON a.quote_id=q.id AND a.order_id=q.order_id WHERE q.order_id=:id AND q.status='selected' AND a.status='accepted' LIMIT 1",['id'=>$orderId]);
        $competitorPass=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_runs WHERE instance_id=:instance AND action_key='worker_grab_competitor' AND result='passed' AND error_code='ORDER_ALREADY_GRABBED'",['instance'=>(int)$instance['id']]);
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);

        $checks=[
            ['structure','single_grab_shape','consumer / construction / single_grab',(string)$order['order_source_type']==='consumer'&&(string)$order['workflow_profile']==='construction'&&(string)$order['dispatch_mode']==='single_grab',implode('/',[(string)$order['order_source_type'],(string)$order['workflow_profile'],(string)$order['dispatch_mode']])],
            ['funds','booking_payment_owned','testing 内部预约费属于当前实例且 paid',$payment&&$payment['status']==='paid'&&(string)($payment['simulated_provider']??'')==='internal_test',$payment?'status='.(string)$payment['status'].'; provider='.(string)$payment['simulated_provider']:'missing'],
            ['funds','no_wechat_ids','内部支付不得出现微信标识',$payment&&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']),'prepay='.(string)($payment['wechat_prepay_id']??'').'; tx='.(string)($payment['wechat_transaction_id']??'')],
            ['state','winner_bound','抢单后 selected/assigned/current assignment 均绑定',$winner>0&&(int)($order['selected_quote_id']??0)>0&&(int)($order['current_assignment_id']??0)>0,'worker='.$winner.'; quote='.(int)($order['selected_quote_id']??0).'; assignment='.(int)($order['current_assignment_id']??0)],
            ['state','accepted_hidden','抢单即成交：selected/accepted/hidden',(string)$order['quote_status']==='selected'&&(string)$order['assignment_status']==='accepted'&&(string)$order['publish_status']==='hidden',implode('/',[(string)$order['quote_status'],(string)$order['assignment_status'],(string)$order['publish_status']])],
            ['race','single_assignment','全单只能有 1 个 assignment 且为 accepted single_grab',$assignments===1&&$accepted===1,'assignments='.$assignments.'; accepted='.$accepted],
            ['race','single_selected_quote','全单只能有 1 个 selected quote 且无 active quote',$selectedQuotes===1&&$activeQuotes===0,'selected='.$selectedQuotes.'; active='.$activeQuotes],
            ['race','quote_version','成交 quote 恰有 current quote_version',$versionCount===1,'matched='.$versionCount],
            ['race','winner_owned','胜出师傅必须是当前实例 approved testing participant',$winnerOwned===1,'owned='.$winnerOwned.'; worker='.$winner],
            ['funds','fixed_total_preserved',$selected&&(int)$selected['amount_cents']===(int)$order['expected_fee_cents']&&(int)$selected['final_amount_cents_snapshot']===(int)$order['expected_fee_cents']&&(int)$order['final_amount_cents']===(int)$order['expected_fee_cents'],'quote='.(int)($selected['amount_cents']??0).'; assignment='.(int)($selected['final_amount_cents_snapshot']??0).'; order='.(int)$order['final_amount_cents'].'; expected='.(int)$order['expected_fee_cents']],
            ['progress','accepted_history','抢单即成交写入 accepted assignment_history',$history===1,'accepted_history='.$history],
            ['progress','commission_rule_snapshot','抢单成交锁定 1 个服务费规则快照',$rules===1&&(int)($order['commission_rule_version_id']??0)>0,'rules='.$rules.'; current='.(int)($order['commission_rule_version_id']??0)],
            ['safety','no_external_outbox','演示测试订单不得产生外部消息 outbox',$outbox===0,'outbox='.$outbox],
        ];
        if((string)$latest['action_key']==='worker_grab_competitor')$checks[]=['race','competitor_rejected','第二师傅正式返回 ORDER_ALREADY_GRABBED 且未制造第二 assignment',$competitorPass>=1&&$assignments===1,'probe='.$competitorPass.'; assignments='.$assignments.'; latest_code='.(string)($latest['error_code']??'')];
        return$this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,(string)$latest['action_key']);
    }

    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started,string $latestAction): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as $check){[$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['latest_real_action'=>$latestAction])]);}
        $status=$failed===0?'passed':'failed';
        $this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['latest_real_action'=>$latestAction,'assertions'=>count($checks),'failed'=>$failed]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'single_grab','latest_real_action'=>$latestAction];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']);
    }
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 流程推进后的状态验证层。
 *
 * 没有执行正向 real_service 动作时继续使用内层 M1 基线验证；执行过真实报价或
 * 真实选报价后，改为验证生产状态机应产生的后置事实，避免把正确的已成交状态
 * 误判成“不再是待报价基线”。
 */
final class DatabaseTestCenterProgressVerificationRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db, TestCenterRepository $inner)
    {
        $this->db=$db;$this->inner=$inner;
    }

    public function environmentDiagnostics(): array{return $this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return $this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return $this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return $this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function cleanupPreview(string $publicId): array{return $this->inner->cleanupPreview($publicId);}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array{return $this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);}
    public function rebuildSource(string $publicId): array{return $this->inner->rebuildSource($publicId);}

    public function verify(string $publicId,int $adminId): array
    {
        $publicId=trim($publicId);
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
        if(!$instance||empty($instance['order_id']))return $this->inner->verify($publicId,$adminId);
        $latest=$this->db->fetch(
            "SELECT action_key,public_id FROM test_runs
             WHERE instance_id=:instance AND operation_mode='real_service' AND result='passed'
               AND action_key IN ('worker_quote','customer_select_quote')
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return $this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('流程推进检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);

        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        if((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于可执行流程检查的数据域。','TEST_VERIFY_ORDER_BOUNDARY',409);

        $started=microtime(true);$runPublic=\public_id();
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify_progress','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-progress:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$publicId.'|'.(string)$latest['action_key']), 'request_id'=>\request_id(),
                'before_json'=>$this->json(['latest_real_action'=>(string)$latest['action_key'],'latest_real_run'=>(string)$latest['public_id']]),'admin'=>$adminId,
            ]
        );

        $payment=$this->db->fetch(
            "SELECT p.*,tf.simulated_provider FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id
             WHERE tf.instance_id=:instance AND tf.business_type='order_booking' AND p.order_id=:order_id LIMIT 1",
            ['instance'=>(int)$instance['id'],'order_id'=>$orderId]
        );
        $activeQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='active'",['id'=>$orderId]);
        $activeVersions=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes q JOIN quote_versions qv ON qv.quote_id=q.id AND qv.version_no=q.current_version WHERE q.order_id=:id AND q.quote_type='worker' AND q.status='active'",['id'=>$orderId]);
        $selectedQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='selected'",['id'=>$orderId]);
        $confirmedSelections=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quote_selections WHERE order_id=:id AND status='confirmed'",['id'=>$orderId]);
        $acceptedAssignments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='accepted'",['id'=>$orderId]);
        $acceptedHistory=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignment_history ah JOIN assignments a ON a.id=ah.assignment_id WHERE a.order_id=:id AND ah.action='accepted'",['id'=>$orderId]);
        $ruleCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM commission_rule_versions WHERE order_id=:id',['id'=>$orderId]);
        $participantCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_participants WHERE instance_id=:id',['id'=>(int)$instance['id']]);
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);
        $schemaId=(int)($order['form_schema_version_id']??0);$mediaReady=OrderFormMediaRules::readiness($this->db,$orderId,$schemaId,(string)$order['workflow_profile']);
        $ownedMedia=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_media_facts WHERE instance_id=:id AND storage_key_snapshot LIKE 'test/%'",['id'=>(int)$instance['id']]);
        $allMedia=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_media_facts WHERE instance_id=:id',['id'=>(int)$instance['id']]);

        $checks=[
            ['structure','test_domain','is_demo=1 且非审核样例',(int)$order['is_demo']===1&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,'is_demo='.(int)$order['is_demo'].'; order='.(string)$order['public_no']],
            ['structure','ordinary_construction','consumer / construction / multi_quote',(string)$order['order_source_type']==='consumer'&&(string)$order['workflow_profile']==='construction'&&(string)$order['dispatch_mode']==='multi_quote',implode('/',[(string)$order['order_source_type'],(string)$order['workflow_profile'],(string)$order['dispatch_mode']])],
            ['funds','booking_payment_owned','testing 内部预约费归属当前实例且 status=paid',$payment&&$payment['status']==='paid'&&(string)($payment['simulated_provider']??'')==='internal_test',$payment?'status='.(string)$payment['status'].'; provider='.(string)$payment['simulated_provider']:'missing'],
            ['funds','no_wechat_ids','内部支付不得出现微信 prepay/transaction id',$payment&&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']),'prepay='.(string)($payment['wechat_prepay_id']??'').'; transaction='.(string)($payment['wechat_transaction_id']??'')],
            ['state','lifecycle_active','订单仍为 active',(string)$order['lifecycle_status']==='active',(string)$order['lifecycle_status']],
            ['participants','fixture_pool','测试参与者槽位至少 4 个',$participantCount>=4,'slots='.$participantCount],
            ['media','form_media_ready','当前正式表单图片规则仍满足',!empty($mediaReady['ready']),(string)($mediaReady['reason']??'ready')],
            ['media','owned_test_media','所有测试媒体仍位于 test/',$allMedia===$ownedMedia,'owned='.$ownedMedia.'/'.$allMedia],
            ['safety','no_external_outbox','演示测试订单不得产生外部消息 outbox',$outbox===0,'outbox='.$outbox],
        ];

        if((string)$latest['action_key']==='worker_quote'){
            $checks[]=['progress','real_worker_quote','真实报价后至少 1 个 active worker quote',$activeQuotes>=1,'active='.$activeQuotes];
            $checks[]=['progress','real_worker_quote_versions','每个 active quote 都有 current quote_version',$activeVersions===$activeQuotes,'matched='.$activeVersions.'/'.$activeQuotes];
            $checks[]=['progress','still_waiting_selection','报价后仍未选择师傅，assignment=unassigned',empty($order['selected_quote_id'])&&empty($order['current_assignment_id'])&&(string)$order['assignment_status']==='unassigned','selected='.(string)($order['selected_quote_id']??'').'; assignment='.(string)($order['current_assignment_id']??'').'; status='.(string)$order['assignment_status']];
        }else{
            $checks[]=['progress','quote_selected','成交后 quote_status=selected 且恰有 1 个 selected worker quote',(string)$order['quote_status']==='selected'&&$selectedQuotes===1,(string)$order['quote_status'].'; selected_quotes='.$selectedQuotes];
            $checks[]=['progress','assignment_accepted','成交后存在且仅有 1 个 accepted assignment，订单 assignment_status=accepted',(string)$order['assignment_status']==='accepted'&&$acceptedAssignments===1,(string)$order['assignment_status'].'; accepted='.$acceptedAssignments];
            $checks[]=['progress','assignment_bound','selected_quote / assigned_worker / current_assignment 均已绑定',(int)($order['selected_quote_id']??0)>0&&(int)($order['assigned_worker_id']??0)>0&&(int)($order['current_assignment_id']??0)>0,'quote='.(int)($order['selected_quote_id']??0).'; worker='.(int)($order['assigned_worker_id']??0).'; assignment='.(int)($order['current_assignment_id']??0)];
            $checks[]=['progress','selection_confirmed','恰有 1 个 confirmed quote_selection',$confirmedSelections===1,'confirmed='.$confirmedSelections];
            $checks[]=['progress','assignment_history','自动接单产生 accepted assignment_history',$acceptedHistory>=1,'accepted_history='.$acceptedHistory];
            $checks[]=['progress','commission_rule_snapshot','成交后存在 commission_rule_versions 且订单已绑定规则',$ruleCount>=1&&(int)($order['commission_rule_version_id']??0)>0,'rules='.$ruleCount.'; current='.(int)($order['commission_rule_version_id']??0)];
            $checks[]=['progress','no_active_quotes_after_selection','成交后不再有 active quote',$activeQuotes===0,'active='.$activeQuotes];
        }
        return $this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,(string)$latest['action_key']);
    }

    /** @param array<int,array<int,mixed>> $checks @return array<string,mixed> */
    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started,string $latestAction): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as $check){
            [$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;
            $this->db->insert(
                "INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at)
                 VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",
                ['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['latest_real_action'=>$latestAction])]
            );
        }
        $status=$failed===0?'passed':'failed';$duration=(int)round((microtime(true)-$started)*1000);
        $this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);
        $this->db->execute(
            "UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",
            ['result'=>$status,'summary'=>$this->json(['latest_real_action'=>$latestAction,'assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]
        );
        return ['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'progress','latest_real_action'=>$latestAction];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',
            ['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']
        );
    }

    /** @param mixed $value */
    private function json($value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json)?$json:'{}';
    }
}

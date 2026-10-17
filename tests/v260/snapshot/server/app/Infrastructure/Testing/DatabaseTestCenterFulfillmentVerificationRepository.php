<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第二段履约推进后的后置条件验证。 */
final class DatabaseTestCenterFulfillmentVerificationRepository implements TestCenterRepository
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
               AND action_key IN ('worker_appointment','worker_complete','customer_accept_completion')
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('履约推进检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);

        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        if((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于可执行履约检查的数据域。','TEST_VERIFY_ORDER_BOUNDARY',409);

        $started=microtime(true);$runPublic=\public_id();
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify_fulfillment','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-fulfillment:'.bin2hex(random_bytes(8)),
                'digest'=>hash('sha256',$publicId.'|'.(string)$latest['action_key']),'request_id'=>\request_id(),
                'before_json'=>$this->json(['latest_real_action'=>(string)$latest['action_key'],'latest_real_run'=>(string)$latest['public_id']]),'admin'=>$adminId,
            ]
        );

        $payment=$this->db->fetch(
            "SELECT p.*,tf.simulated_provider FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id
             WHERE tf.instance_id=:instance AND tf.business_type='order_booking' AND p.order_id=:order_id LIMIT 1",
            ['instance'=>(int)$instance['id'],'order_id'=>$orderId]
        );
        $selectedQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='selected'",['id'=>$orderId]);
        $acceptedAssignments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='accepted'",['id'=>$orderId]);
        $appointments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='service' AND appointment_at IS NOT NULL AND wait_customer_notice=0",['id'=>$orderId]);
        $submittedCompletions=(int)$this->db->fetchValue("SELECT COUNT(*) FROM completion_submissions WHERE order_id=:id AND status='submitted'",['id'=>$orderId]);
        $acceptedCompletions=(int)$this->db->fetchValue("SELECT COUNT(*) FROM completion_submissions WHERE order_id=:id AND status='accepted'",['id'=>$orderId]);
        $pendingAutoAccept=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=:id AND deadline_type='auto_accept_completion' AND status='pending'",['id'=>$orderId]);
        $pendingAutoReview=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=:id AND deadline_type IN ('auto_review','auto_review_order') AND status='pending'",['id'=>$orderId]);
        $receivable=$this->db->fetch('SELECT * FROM commission_receivables WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
        $commissionLedger=$receivable?(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='commission' AND business_id=:id",['id'=>(int)$receivable['id']]):0;
        $completionMedia=$this->ownedMediaCount((int)$instance['id'],$orderId,'completion');
        $receiptMedia=$this->ownedMediaCount((int)$instance['id'],$orderId,'collection_receipt');
        $allMedia=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_media_facts WHERE instance_id=:id',['id'=>(int)$instance['id']]);
        $ownedMedia=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_media_facts WHERE instance_id=:id AND storage_key_snapshot LIKE 'test/%'",['id'=>(int)$instance['id']]);
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);
        $assignedWorker=(int)($order['assigned_worker_id']??0);
        $workerCompleted=$assignedWorker>0?(int)$this->db->fetchValue('SELECT completed_orders FROM worker_profiles WHERE id=:id',['id'=>$assignedWorker]):0;
        $derivedCompleted=$assignedWorker>0?(int)$this->db->fetchValue('SELECT COUNT(*) FROM orders WHERE assigned_worker_id=:worker AND accepted_at IS NOT NULL',['worker'=>$assignedWorker]):0;

        $checks=[
            ['structure','test_domain','is_demo=1 且非审核样例',(int)$order['is_demo']===1&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,'is_demo='.(int)$order['is_demo'].'; order='.(string)$order['public_no']],
            ['structure','ordinary_construction','consumer / construction / multi_quote',(string)$order['order_source_type']==='consumer'&&(string)$order['workflow_profile']==='construction'&&(string)$order['dispatch_mode']==='multi_quote',implode('/',[(string)$order['order_source_type'],(string)$order['workflow_profile'],(string)$order['dispatch_mode']])],
            ['funds','booking_payment_owned','testing 内部预约费属于当前实例且 status=paid',$payment&&$payment['status']==='paid'&&(string)($payment['simulated_provider']??'')==='internal_test',$payment?'status='.(string)$payment['status'].'; provider='.(string)$payment['simulated_provider']:'missing'],
            ['funds','no_wechat_ids','内部支付不得出现微信 prepay/transaction id',$payment&&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']),'prepay='.(string)($payment['wechat_prepay_id']??'').'; transaction='.(string)($payment['wechat_transaction_id']??'')],
            ['progress','deal_preserved','成交报价和 accepted assignment 保持唯一',$selectedQuotes===1&&$acceptedAssignments===1,'selected_quotes='.$selectedQuotes.'; accepted_assignments='.$acceptedAssignments],
            ['progress','assignment_bound','selected_quote / assigned_worker / current_assignment 均已绑定',(int)($order['selected_quote_id']??0)>0&&$assignedWorker>0&&(int)($order['current_assignment_id']??0)>0,'quote='.(int)($order['selected_quote_id']??0).'; worker='.$assignedWorker.'; assignment='.(int)($order['current_assignment_id']??0)],
            ['media','owned_test_media','所有测试媒体仍位于 test/',$allMedia===$ownedMedia,'owned='.$ownedMedia.'/'.$allMedia],
            ['safety','no_external_outbox','演示测试订单不得产生外部消息 outbox',$outbox===0,'outbox='.$outbox],
        ];

        $action=(string)$latest['action_key'];
        if($action==='worker_appointment'){
            $checks[]=['fulfillment','service_appointment','存在正式施工预约且为明确时间',$appointments>=1,'appointments='.$appointments];
            $checks[]=['fulfillment','scheduled','预约后 fulfillment_status=scheduled 且 lifecycle=active',(string)$order['fulfillment_status']==='scheduled'&&(string)$order['lifecycle_status']==='active',(string)$order['fulfillment_status'].'/'.(string)$order['lifecycle_status']];
            $checks[]=['fulfillment','not_completed_yet','预约后尚未提交完工',$submittedCompletions===0&&empty($order['completion_submitted_at']),'submitted='.$submittedCompletions.'; at='.(string)($order['completion_submitted_at']??'')];
        }elseif($action==='worker_complete'){
            $checks[]=['fulfillment','service_appointment','完工前正式施工预约仍存在',$appointments>=1,'appointments='.$appointments];
            $checks[]=['fulfillment','completion_submitted','订单进入 submitted 且仍 active',(string)$order['fulfillment_status']==='submitted'&&(string)$order['lifecycle_status']==='active'&&!empty($order['completion_submitted_at']),'status='.(string)$order['fulfillment_status'].'; lifecycle='.(string)$order['lifecycle_status'].'; submitted_at='.(string)($order['completion_submitted_at']??'')];
            $checks[]=['fulfillment','completion_submission_row','恰有 1 个 submitted completion_submissions',$submittedCompletions===1,'submitted='.$submittedCompletions];
            $checks[]=['media','completion_fixture','存在当前实例拥有的 testing 完工图片',$completionMedia>=1,'completion_media='.$completionMedia];
            $checks[]=['deadline','auto_accept_completion','存在待执行自动验收期限',$pendingAutoAccept===1,'pending_auto_accept='.$pendingAutoAccept];
            $checks[]=['funds','commission_estimate','普通单完工后存在 provisional 服务费预计应收',$receivable&&(int)$receivable['is_provisional']===1,'receivable='.(int)($receivable['id']??0).'; provisional='.(int)($receivable['is_provisional']??-1).'; ledger='.$commissionLedger];
            $checks[]=['fulfillment','not_accepted_yet','完工后尚未验收',empty($order['accepted_at']),'accepted_at='.(string)($order['accepted_at']??'')];
        }else{
            $checks[]=['fulfillment','completion_accepted','订单 fulfillment=accepted 且 lifecycle=completed',(string)$order['fulfillment_status']==='accepted'&&(string)$order['lifecycle_status']==='completed'&&!empty($order['accepted_at']),'status='.(string)$order['fulfillment_status'].'; lifecycle='.(string)$order['lifecycle_status'].'; accepted_at='.(string)($order['accepted_at']??'')];
            $checks[]=['fulfillment','completion_submission_accepted','完工提交已转 accepted',$acceptedCompletions===1&&$submittedCompletions===0,'accepted='.$acceptedCompletions.'; submitted='.$submittedCompletions];
            $checks[]=['media','completion_fixture','testing 完工图片仍存在',$completionMedia>=1,'completion_media='.$completionMedia];
            $checks[]=['media','collection_receipt_fixture','存在当前实例拥有的 testing 收款凭证',$receiptMedia>=1,'receipt_media='.$receiptMedia];
            $checks[]=['funds','commission_finalized','服务费应收已从 provisional 正式化',$receivable&&(int)$receivable['is_provisional']===0&&!empty($receivable['finalized_at']),'receivable='.(int)($receivable['id']??0).'; provisional='.(int)($receivable['is_provisional']??-1).'; finalized='.(string)($receivable['finalized_at']??'').'; ledger='.$commissionLedger];
            $checks[]=['deadline','auto_accept_closed','人工验收后不得残留待执行自动验收期限',$pendingAutoAccept===0,'pending_auto_accept='.$pendingAutoAccept];
            $checks[]=['deadline','auto_review','验收后存在待评价期限',$pendingAutoReview>=1,'pending_auto_review='.$pendingAutoReview];
            $checks[]=['derived','worker_completed_orders','测试师傅完工单数与订单事实重算一致',$assignedWorker>0&&$workerCompleted===$derivedCompleted,'worker='.$assignedWorker.'; stored='.$workerCompleted.'; derived='.$derivedCompleted];
        }
        return$this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,$action);
    }

    private function ownedMediaCount(int $instanceId,int $orderId,string $purpose): int
    {
        return(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM test_media_facts tm JOIN media_links ml ON ml.media_file_id=tm.media_file_id
             JOIN media_files mf ON mf.id=tm.media_file_id AND mf.status='active'
             WHERE tm.instance_id=:instance AND tm.purpose=:purpose_fact AND ml.entity_type='order' AND ml.entity_id=:order_id AND ml.purpose=:purpose_link",
            ['instance'=>$instanceId,'purpose_fact'=>$purpose,'order_id'=>$orderId,'purpose_link'=>$purpose]
        );
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
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'fulfillment_progress','latest_real_action'=>$latestAction];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',
            ['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']
        );
    }

    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

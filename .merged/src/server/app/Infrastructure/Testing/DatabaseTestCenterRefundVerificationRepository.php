<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第四段：退款协商和内部退款登记的后置条件验证。 */
final class DatabaseTestCenterRefundVerificationRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;
    private TestingRefundBoundaryGuard $funds;

    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;$this->funds=new TestingRefundBoundaryGuard($db);}
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
        $latest=$this->db->fetch(
            "SELECT action_key,public_id FROM test_runs WHERE instance_id=:instance AND operation_mode='real_service' AND result='passed'
             AND action_key IN ('customer_refund_request','worker_accept_refund','worker_reject_refund','customer_repropose_refund','customer_escalate_refund')
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('退款协商检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        if((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于退款测试数据域。','TEST_REFUND_VERIFY_BOUNDARY',409);

        $started=microtime(true);$runPublic=\public_id();$action=(string)$latest['action_key'];
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify_refund','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-refund:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId.'|'.$action),'request_id'=>\request_id(),'before_json'=>$this->json(['latest_real_action'=>$action,'latest_real_run'=>(string)$latest['public_id']]),'admin'=>$adminId]
        );

        $request=$this->db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
        $requests=(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_cancel_requests WHERE order_id=:id',['id'=>$orderId]);
        $refunds=$this->db->fetchAll('SELECT * FROM refunds WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
        $decisions=$this->db->fetchAll('SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);
        $riskScores=$this->db->tableExists('order_cancellation_risk_scores')?(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_cancellation_risk_scores WHERE order_id=:id',['id'=>$orderId]):0;
        $selectedCancelled=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND id=:quote AND status='cancelled'",['id'=>$orderId,'quote'=>(int)($order['selected_quote_id']??0)]);
        $assignmentCancelled=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND id=:assignment AND status='cancelled'",['id'=>$orderId,'assignment'=>(int)($order['current_assignment_id']??0)]);
        $funds=$this->funds->inspect((int)$instance['id'],$order,false);
        $paymentStatus=(string)$this->db->fetchValue('SELECT status FROM payment_orders WHERE id=:id',['id'=>(int)$funds['payment_id']]);

        $checks=[
            ['structure','test_domain','is_demo=1 且非 BXDEMOHALL',(int)$order['is_demo']===1&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,'is_demo='.(int)$order['is_demo'].'; order='.(string)$order['public_no']],
            ['funds','owned_internal_payment','退款基础支付属于当前实例 internal_test',(int)$funds['test_fact_id']>0&&(string)$funds['simulated_provider']==='internal_test','payment='.(string)$funds['payment_no'].'; provider='.(string)$funds['simulated_provider']],
            ['funds','external_refund_disabled','测试支付永不允许作为真实微信退款来源',empty($funds['external_refund_allowed']),'external_refund_allowed='.(int)!empty($funds['external_refund_allowed'])],
            ['safety','no_external_outbox','演示退款协商不得产生短信/订阅/企微外部发件箱',$outbox===0,'outbox='.$outbox],
            ['safety','no_cancellation_risk','demo 测试取消不得污染正式用户风控',$riskScores===0,'risk_scores='.$riskScores],
            ['negotiation','single_refund_request','当前阶段恰有 1 个取消/退款协商事项',$requests===1,'requests='.$requests],
            ['negotiation','request_owned_customer',$request&&(string)$request['applicant_type']==='user'&&(int)$request['applicant_id']===(int)$order['user_id'],'applicant='.(string)($request['applicant_type']??'').':'.(int)($request['applicant_id']??0)],
        ];

        if($action==='customer_refund_request'){
            $checks[]=['negotiation','refund_pending','初始方案 pending / bilateral_refund_pending',$request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_pending','status='.(string)($request['status']??'').'; reason='.(string)($request['reason_code']??'')];
            $checks[]=['funds','proposal_amount','方案金额等于当前内部支付可退金额',$request&&(int)$request['requested_refund_cents']===(int)$funds['available_cents'],'requested='.(int)($request['requested_refund_cents']??0).'; available='.(int)$funds['available_cents']];
            $checks[]=['funds','no_refund_before_agreement','双方同意前不得创建 refunds',count($refunds)===0,'refunds='.count($refunds)];
            $checks[]=['state','agreement_still_active','协商期间订单仍 active，仅隐藏公开入口',(string)$order['lifecycle_status']==='active'&&(string)$order['refund_status']==='pending_review'&&(string)$order['publish_status']==='hidden',implode('/',[(string)$order['lifecycle_status'],(string)$order['refund_status'],(string)$order['publish_status']])];
        }elseif($action==='worker_reject_refund'){
            $checks[]=['negotiation','refund_rejected','拒绝后保留 pending 请求并标记 bilateral_refund_rejected',$request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_rejected','status='.(string)($request['status']??'').'; reason='.(string)($request['reason_code']??'')];
            $checks[]=['funds','rejection_no_refund','拒绝不能创建资金退款记录',count($refunds)===0,'refunds='.count($refunds)];
            $checks[]=['intervention','rejection_not_auto_escalated','拒绝只开放介入，不自动介入',(string)($request['reason_code']??'')!=='bilateral_refund_escalated',(string)($request['reason_code']??'missing')];
            $checks[]=['state','rejection_keeps_order_active','拒绝后订单仍可继续协商',(string)$order['lifecycle_status']==='active','lifecycle='.(string)$order['lifecycle_status']];
        }elseif($action==='customer_repropose_refund'){
            $checks[]=['negotiation','refund_reproposed','重新方案回到 bilateral_refund_pending',$request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_pending','status='.(string)($request['status']??'').'; reason='.(string)($request['reason_code']??'')];
            $checks[]=['funds','reproposal_amount','重新方案仍使用当前可退内部支付金额',$request&&(int)$request['requested_refund_cents']===(int)$funds['available_cents'],'requested='.(int)($request['requested_refund_cents']??0).'; available='.(int)$funds['available_cents']];
            $checks[]=['funds','reproposal_no_refund','重新协商仍不创建退款单',count($refunds)===0,'refunds='.count($refunds)];
        }elseif($action==='customer_escalate_refund'){
            $checks[]=['intervention','refund_escalated','拒绝后正式进入 bilateral_refund_escalated',$request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_escalated','status='.(string)($request['status']??'').'; reason='.(string)($request['reason_code']??'')];
            $checks[]=['funds','escalation_no_refund','平台介入前不创建退款资金记录',count($refunds)===0,'refunds='.count($refunds)];
            $checks[]=['state','escalation_before_adjudication','本阶段不伪造管理员裁决',(string)$order['lifecycle_status']==='active'&&count($decisions)===0,'lifecycle='.(string)$order['lifecycle_status'].'; decisions='.count($decisions)];
        }else{
            $refund=count($refunds)===1?$refunds[0]:null;$decision=count($decisions)===1?$decisions[0]:null;
            $checks[]=['negotiation','refund_agreement_accepted','双方接受后请求 approved / bilateral_refund_accepted',$request&&(string)$request['status']==='approved'&&(string)$request['reason_code']==='bilateral_refund_accepted','status='.(string)($request['status']??'').'; reason='.(string)($request['reason_code']??'')];
            $checks[]=['funds','single_internal_refund','恰有 1 个退款单且关联当前请求和内部支付',$refund&&(int)$refund['payment_order_id']===(int)$funds['payment_id']&&(int)$refund['cancel_request_id']===(int)$request['id']&&(int)$refund['amount_cents']===(int)$request['requested_refund_cents'],'refunds='.count($refunds).'; payment='.(int)($refund['payment_order_id']??0).'; request='.(int)($refund['cancel_request_id']??0)];
            $checks[]=['funds','refund_pending_review','内部退款只登记 pending_review，wechat_refund_id 必须为空',$refund&&(string)$refund['status']==='pending_review'&&empty($refund['wechat_refund_id']),'status='.(string)($refund['status']??'missing').'; wechat='.(string)($refund['wechat_refund_id']??'')];
            $checks[]=['funds','payment_not_faked_refunded','未收到微信成功事实前原支付仍保持 paid',$paymentStatus==='paid','payment_status='.$paymentStatus];
            $checks[]=['funds','external_refund_not_executed','本阶段没有微信退款 ID，也不允许把退款标记 processing/succeeded',$refund&&empty($refund['wechat_refund_id'])&&!in_array((string)$refund['status'],['processing','succeeded'],true),'status='.(string)($refund['status']??'missing')];
            $checks[]=['funds','cancel_decision_registered','正式取消领域登记唯一 approved 决定',$decision&&(string)$decision['decision']==='approved'&&(int)$decision['cancel_request_id']===(int)$request['id']&&(int)$decision['refund_id']===(int)$refund['id'],'decisions='.count($decisions)];
            $checks[]=['state','fulfillment_stopped','接受退款后订单停止履约并等待退款',(string)$order['lifecycle_status']==='cancel_approved_pending_refund'&&(string)$order['refund_status']==='pending_review'&&(string)$order['publish_status']==='hidden','lifecycle='.(string)$order['lifecycle_status'].'; refund='.(string)$order['refund_status'].'; publish='.(string)$order['publish_status']];
            $checks[]=['state','deal_cancelled','原成交报价和 assignment 已取消',$selectedCancelled===1&&$assignmentCancelled===1,'quote_cancelled='.$selectedCancelled.'; assignment_cancelled='.$assignmentCancelled];
        }
        return$this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,$action);
    }

    /** @param array<int,array<int,mixed>> $checks @return array<string,mixed> */
    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started,string $latestAction): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as $check){[$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['latest_real_action'=>$latestAction])]);}
        $status=$failed===0?'passed':'failed';$duration=(int)round((microtime(true)-$started)*1000);
        $this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['latest_real_action'=>$latestAction,'assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'refund_progress','latest_real_action'=>$latestAction];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']);
    }
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

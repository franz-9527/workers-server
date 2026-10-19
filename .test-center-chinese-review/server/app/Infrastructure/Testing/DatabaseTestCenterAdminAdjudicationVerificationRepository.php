<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第六段：平台管理员裁决后的后置条件验证。 */
final class DatabaseTestCenterAdminAdjudicationVerificationRepository implements TestCenterRepository
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
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);
        if(!$instance||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);
        $latest=$this->db->fetch("SELECT action_key,public_id,operation_mode,created_by_admin_id FROM test_runs WHERE instance_id=:instance AND result='passed' AND action_key IN ('admin_refund_approve','admin_refund_reject','admin_dispute_resolve','admin_repair_continue','admin_repair_resolved','admin_repair_closed') ORDER BY id DESC LIMIT 1",['instance'=>(int)$instance['id']]);
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('管理员裁决检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        if((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于管理员裁决测试数据域。','TEST_ADMIN_ADJUDICATION_VERIFY_BOUNDARY',409);

        $started=microtime(true);$action=(string)$latest['action_key'];$runPublic=\public_id();$runId=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public_id,:instance,'verify_admin_adjudication','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-admin:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId.'|'.$action),'request_id'=>\request_id(),'before_json'=>$this->json(['latest_action'=>$action,'latest_run'=>$latest['public_id'],'operation_mode'=>$latest['operation_mode']]),'admin'=>$adminId]);
        $orderId=(int)$order['id'];$outbox=$this->outboxCount($orderId,(string)$order['public_no']);$checks=[
            ['structure','test_domain','is_demo=1 且非 BXDEMOHALL',(int)$order['is_demo']===1&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,'order='.(string)$order['public_no']],
            ['audit','real_admin_action','裁决动作必须以 admin / real_service 记录',(string)$latest['operation_mode']==='real_service'&&(int)$latest['created_by_admin_id']>0,'mode='.(string)$latest['operation_mode'].'; admin='.(int)$latest['created_by_admin_id']],
            ['safety','no_external_outbox','demo 管理员裁决不得产生短信/订阅/企微外部发件箱',$outbox===0,'outbox='.$outbox],
        ];

        if($action==='admin_refund_approve'||$action==='admin_refund_reject'){
            $request=$this->db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
            $decisions=$this->db->fetchAll('SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
            $refunds=$this->db->fetchAll("SELECT r.*,p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id,tf.instance_id test_instance_id,tf.simulated_provider FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id LEFT JOIN test_payment_facts tf ON tf.payment_order_id=p.id AND tf.instance_id=:instance WHERE r.order_id=:order_id ORDER BY r.id",['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
            $decision=count($decisions)===1?$decisions[0]:null;
            $checks[]=['refund','escalated_request_preserved','管理员裁决仍关联原 bilateral_refund_escalated 请求',$request&&(string)$request['reason_code']==='bilateral_refund_escalated','reason='.(string)($request['reason_code']??'missing')];
            if($action==='admin_refund_reject'){
                $checks[]=['refund','admin_refund_rejected','正式审核拒绝后 request=rejected 且只有一条 rejected decision',$request&&(string)$request['status']==='rejected'&&count($decisions)===1&&$decision&&(string)$decision['decision']==='rejected','request='.(string)($request['status']??'missing').'; decisions='.count($decisions)];
                $checks[]=['funds','reject_creates_no_refund','拒绝退款不能创建退款资金事实',count($refunds)===0,'refunds='.count($refunds)];
                $checks[]=['state','reject_keeps_order_active','拒绝退款不应取消原订单',(string)$order['lifecycle_status']==='active','lifecycle='.(string)$order['lifecycle_status']];
            }else{
                $refund=count($refunds)===1?$refunds[0]:null;
                $checks[]=['refund','admin_refund_approved','正式审核通过后 request=processing 且只有一条 approved decision',$request&&(string)$request['status']==='processing'&&count($decisions)===1&&$decision&&(string)$decision['decision']==='approved','request='.(string)($request['status']??'missing').'; decisions='.count($decisions)];
                $checks[]=['funds','single_internal_refund','只允许一条属于当前实例的 internal_test pending_review 退款',$refund&&count($refunds)===1&&(int)$refund['test_instance_id']===(int)$instance['id']&&(string)$refund['simulated_provider']==='internal_test'&&(string)$refund['status']==='pending_review'&&(string)$refund['app_id']==='TEST_INTERNAL'&&(string)$refund['mch_id']==='TEST_INTERNAL'&&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0,'refunds='.count($refunds).'; status='.(string)($refund['status']??'missing')];
                $checks[]=['funds','wechat_refund_not_executed','管理员通过只登记退款，不得出现微信退款/支付标识',$refund&&empty($refund['wechat_refund_id'])&&empty($refund['wechat_prepay_id'])&&empty($refund['wechat_transaction_id']),'wechat_refund_id='.(string)($refund['wechat_refund_id']??'')];
                $checks[]=['state','approval_stops_fulfillment','退款通过后订单停在待退款终止态',(string)$order['lifecycle_status']==='cancel_approved_pending_refund'&&(string)$order['refund_status']==='pending_review'&&(string)$order['assignment_status']==='cancelled','lifecycle='.(string)$order['lifecycle_status'].'; refund='.(string)$order['refund_status'].'; assignment='.(string)$order['assignment_status']];
            }
        }elseif($action==='admin_dispute_resolve'){
            $dispute=$this->db->fetch("SELECT * FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
            $checks[]=['dispute','admin_dispute_resolved','费用争议由正式管理员入口结案',$dispute&&(string)$dispute['status']==='resolved'&&!empty($dispute['resolved_at'])&&(int)$dispute['handled_by']>0,'status='.(string)($dispute['status']??'missing').'; handled_by='.(int)($dispute['handled_by']??0)];
            $checks[]=['funds','nonfinancial_dispute','本阶段争议结案固定 0 元退款 / 0 元跑空费',$dispute&&(int)$dispute['refund_amount_cents']===0&&(int)$dispute['run_empty_fee_cents']===0,'refund='.(int)($dispute['refund_amount_cents']??-1).'; runner='.(int)($dispute['run_empty_fee_cents']??-1)];
            $checks[]=['state','order_dispute_resolved','订单争议状态同步 resolved',(string)$order['dispute_status']==='resolved','dispute_status='.(string)$order['dispute_status']];
            $checks[]=['funds','no_financial_side_effects','0 元争议结案不得新增退款或跑空费账本',(int)$this->db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=:id',['id'=>$orderId])===0&&(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger l JOIN disputes d ON d.id=l.business_id WHERE d.order_id=:id AND l.business_type='dispute' AND l.entry_type='run_empty_fee'",['id'=>$orderId])===0,'financial side effects checked'];
        }else{
            $repair=$this->db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);$resolution=(string)($repair['resolution']??'');
            $checks[]=['state','main_order_stays_completed','维修平台裁决不倒退或取消原已验收订单',(string)$order['lifecycle_status']==='completed'&&(string)$order['fulfillment_status']==='accepted','lifecycle='.(string)$order['lifecycle_status'].'; fulfillment='.(string)$order['fulfillment_status']];
            if($action==='admin_repair_continue'){
                $checks[]=['repair','platform_continue','要求继续维修后售后仍 processing 且未关闭',$repair&&(string)$repair['status']==='processing'&&strpos($resolution,'平台介入：要求继续维修：')===0&&empty($repair['closed_at']),'status='.(string)($repair['status']??'missing').'; resolution='.$resolution];
            }elseif($action==='admin_repair_resolved'){
                $checks[]=['repair','platform_resolved','平台确认解决后售后 resolved 并关闭',$repair&&(string)$repair['status']==='resolved'&&strpos($resolution,'平台裁定：问题已解决：')===0&&!empty($repair['closed_at']),'status='.(string)($repair['status']??'missing').'; closed='.(string)($repair['closed_at']??'')];
            }else{
                $checks[]=['repair','platform_closed','平台关闭后售后 closed 并关闭',$repair&&(string)$repair['status']==='closed'&&strpos($resolution,'平台裁定：关闭售后：')===0&&!empty($repair['closed_at']),'status='.(string)($repair['status']??'missing').'; closed='.(string)($repair['closed_at']??'')];
            }
        }
        return$this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,$action);
    }

    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started,string $latestAction): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as$check){[$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['latest_action'=>$latestAction])]);}
        $status=$failed===0?'passed':'failed';$duration=(int)round((microtime(true)-$started)*1000);$this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);$this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['latest_action'=>$latestAction,'assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]);return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'admin_adjudication','latest_action'=>$latestAction];
    }
    private function outboxCount(int $orderId,string $orderNo): int{if(!$this->db->tableExists('message_outbox'))return 0;return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',['id_needle'=>'%\"order_id\":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']);}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

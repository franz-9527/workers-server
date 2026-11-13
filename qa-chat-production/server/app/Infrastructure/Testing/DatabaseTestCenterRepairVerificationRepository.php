<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\RepairInterventionPolicy;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第五段：维修售后各节点的后置条件验证。 */
final class DatabaseTestCenterRepairVerificationRepository implements TestCenterRepository
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
        $latest=$this->db->fetch(
            "SELECT action_key,public_id,operation_mode FROM test_runs WHERE instance_id=:instance AND result='passed'
             AND action_key IN ('prepare_repair_warranty','customer_repair_request','worker_accept_repair','worker_reject_repair','worker_repair_appointment','worker_complete_repair','repair_advance_timeout','customer_escalate_repair')
             ORDER BY id DESC LIMIT 1",
            ['instance'=>(int)$instance['id']]
        );
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('维修售后检查仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        if((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('目标订单不属于维修测试数据域。','TEST_REPAIR_VERIFY_BOUNDARY',409);

        $started=microtime(true);$action=(string)$latest['action_key'];$runPublic=\public_id();
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,'verify_repair','admin','inspect',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'idem'=>'verify-repair:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$publicId.'|'.$action),'request_id'=>\request_id(),'before_json'=>$this->json(['latest_action'=>$action,'latest_run'=>(string)$latest['public_id'],'operation_mode'=>(string)$latest['operation_mode']]),'admin'=>$adminId]
        );

        $orderId=(int)$order['id'];
        $repair=$this->db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        $repairCount=(int)$this->db->fetchValue("SELECT COUNT(*) FROM after_sales WHERE order_id=:id AND type='warranty_repair'",['id'=>$orderId]);
        $repairAppointments=$repair?(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:order_id AND context_type='repair' AND after_sale_id=:after_sale",['order_id'=>$orderId,'after_sale'=>(int)$repair['id']]):0;
        $autoReviews=(int)$this->db->fetchValue("SELECT COUNT(*) FROM reviews WHERE order_id=:id AND is_auto=1 AND content='系统自动评价'",['id'=>$orderId]);
        $outbox=$this->outboxCount($orderId,(string)$order['public_no']);
        $resolution=trim((string)($repair['resolution']??''));
        $policy=$repair?RepairInterventionPolicy::evaluate((string)$repair['status'],$resolution,$repair['opened_at']??null,'UTC'):['eligible'=>false,'reason'=>'missing'];

        $checks=[
            ['structure','test_domain','is_demo=1 且非 BXDEMOHALL',(int)$order['is_demo']===1&&strpos((string)$order['public_no'],'BXDEMOHALL')!==0,'order='.(string)$order['public_no']],
            ['state','main_order_stays_completed','维修售后不能倒退原订单验收状态',(string)$order['lifecycle_status']==='completed'&&(string)$order['fulfillment_status']==='accepted','lifecycle='.(string)$order['lifecycle_status'].'; fulfillment='.(string)$order['fulfillment_status']],
            ['safety','no_external_outbox','demo 维修流程不得产生短信/订阅/企微外部发件箱',$outbox===0,'outbox='.$outbox],
        ];

        if($action==='prepare_repair_warranty'){
            $checks[]=['fixture','warranty_ready','testing 保修夹具为 12 个月且未过期',(int)$order['warranty_months']===12&&!empty($order['warranty_expires_at'])&&strtotime((string)$order['warranty_expires_at'].' UTC')>time(),'months='.(int)$order['warranty_months'].'; expires='.(string)($order['warranty_expires_at']??'')];
            $checks[]=['fixture','no_repair_yet','准备保修条件不能提前创建售后',$repairCount===0,'repairs='.$repairCount];
        }else{
            $checks[]=['repair','single_repair','当前实例只允许 1 个保修维修事项',$repairCount===1,'repairs='.$repairCount];
            $checks[]=['repair','owned_customer','售后归属订单客户',$repair&&(int)$repair['user_id']===(int)$order['user_id'],'repair_user='.(int)($repair['user_id']??0)];
            $checks[]=['review','auto_review_owned','进入维修时仅生成测试订单自己的系统自动评价',$autoReviews===1,'auto_reviews='.$autoReviews];
        }

        if($action==='customer_repair_request'){
            $checks[]=['repair','repair_processing','新维修申请为 processing 且尚无师傅响应',$repair&&(string)$repair['status']==='processing'&&$resolution==='','status='.(string)($repair['status']??'missing').'; resolution='.$resolution];
            $checks[]=['repair','no_repair_appointment_early','师傅接受前不能已有维修预约',$repairAppointments===0,'appointments='.$repairAppointments];
        }elseif($action==='worker_accept_repair'){
            $checks[]=['repair','worker_accepted','师傅接受后保持 processing 并记录接受语义',$repair&&(string)$repair['status']==='processing'&&strpos($resolution,'师傅接受维修')===0,'status='.(string)($repair['status']??'missing').'; resolution='.$resolution];
            $checks[]=['repair','accept_does_not_finish','接受维修不能直接关闭售后',empty($repair['closed_at']),'closed_at='.(string)($repair['closed_at']??'')];
        }elseif($action==='worker_repair_appointment'){
            $appointment=$repair?$this->db->fetch("SELECT * FROM appointment_records WHERE order_id=:order_id AND context_type='repair' AND after_sale_id=:after_sale ORDER BY id DESC LIMIT 1",['order_id'=>$orderId,'after_sale'=>(int)$repair['id']]):null;
            $checks[]=['repair','single_repair_appointment','维修预约唯一且关联当前 after_sale',$repairAppointments===1&&$appointment&&(int)$appointment['worker_id']===(int)$order['assigned_worker_id'],'appointments='.$repairAppointments.'; worker='.(int)($appointment['worker_id']??0)];
            $checks[]=['repair','appointment_keeps_processing',$repair&&(string)$repair['status']==='processing'&&strpos($resolution,'师傅接受维修')===0,'status='.(string)($repair['status']??'missing')];
        }elseif($action==='worker_complete_repair'){
            $checks[]=['repair','repair_resolved','完成维修后售后 resolved 且有 closed_at',$repair&&(string)$repair['status']==='resolved'&&strpos($resolution,'师傅已完成维修')===0&&!empty($repair['closed_at']),'status='.(string)($repair['status']??'missing').'; closed='.(string)($repair['closed_at']??'')];
            $checks[]=['repair','appointment_preserved','维修完成后保留唯一预约事实',$repairAppointments===1,'appointments='.$repairAppointments];
        }elseif($action==='worker_reject_repair'){
            $checks[]=['repair','worker_rejected','拒绝后售后仍 processing，保留师傅拒绝语义',$repair&&(string)$repair['status']==='processing'&&strpos($resolution,'师傅拒绝维修')===0,'status='.(string)($repair['status']??'missing').'; resolution='.$resolution];
            $checks[]=['intervention','rejection_is_eligible','RepairInterventionPolicy 将明确拒绝判定为可介入',!empty($policy['eligible'])&&(string)$policy['reason']==='worker_rejected','eligible='.(int)!empty($policy['eligible']).'; reason='.(string)$policy['reason']];
        }elseif($action==='repair_advance_timeout'){
            $checks[]=['fixture','timeout_fixture_only_changes_age','超时夹具保持维修未响应且仍 processing',$repair&&(string)$repair['status']==='processing'&&$resolution==='','status='.(string)($repair['status']??'missing').'; resolution='.$resolution];
            $checks[]=['intervention','timeout_is_eligible','49 小时夹具必须被正式策略判定 response_timeout',!empty($policy['eligible'])&&(string)$policy['reason']==='response_timeout','eligible='.(int)!empty($policy['eligible']).'; reason='.(string)$policy['reason']];
        }elseif($action==='customer_escalate_repair'){
            $checks[]=['intervention','repair_escalated','客户介入后仍 processing，但 resolution 进入平台介入语义',$repair&&(string)$repair['status']==='processing'&&strpos($resolution,'用户申请平台介入')===0,'status='.(string)($repair['status']??'missing').'; resolution='.$resolution];
            $checks[]=['intervention','not_double_eligible','已介入后正式策略拒绝重复申请',empty($policy['eligible'])&&(string)$policy['reason']==='already_escalated','eligible='.(int)!empty($policy['eligible']).'; reason='.(string)$policy['reason']];
            $checks[]=['intervention','before_admin_adjudication','本阶段不伪造平台维修裁决',empty($repair['closed_at']),'closed_at='.(string)($repair['closed_at']??'')];
        }
        return$this->storeChecks((int)$instance['id'],$runId,$runPublic,$checks,$started,$action);
    }

    /** @param array<int,array<int,mixed>> $checks @return array<string,mixed> */
    private function storeChecks(int $instanceId,int $runId,string $runPublic,array $checks,float $started,string $latestAction): array
    {
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as $check){[$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,:details,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual,'details'=>$this->json(['latest_action'=>$latestAction])]);}
        $status=$failed===0?'passed':'failed';$duration=(int)round((microtime(true)-$started)*1000);
        $this->db->execute("UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['latest_action'=>$latestAction,'assertions'=>count($checks),'failed'=>$failed]),'duration'=>$duration,'id'=>$runId]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'repair_progress','latest_action'=>$latestAction];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']);
    }
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

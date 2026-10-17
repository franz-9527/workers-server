<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第四段清理：仅删除可证明属于当前实例的取消协商、退款和取消决定。 */
final class DatabaseTestCenterRefundCleanupRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return$this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function verify(string $publicId,int $adminId): array{return$this->inner->verify($publicId,$adminId);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function cleanupPreview(string $publicId): array
    {
        $base=$this->inner->cleanupPreview($publicId);if(empty($base['plan_digest']))return$base;
        $detail=$this->inner->instanceDetail($publicId);$order=is_array($detail['order']??null)?$detail['order']:null;$instance=(array)($detail['instance']??[]);
        if(!$this->adaptable($instance,$order))return$base;if(!empty($base['allowed']))return$base;
        $blocking=(array)($base['blocking_relations']??[]);$recognized=[];
        foreach(['refunds','order_cancel_requests','order_cancel_decisions'] as $key){if(isset($blocking[$key])){$recognized[$key]=(int)$blocking[$key];unset($blocking[$key]);}}
        if(!$recognized)return$base;

        $orderId=(int)$order['id'];$instanceId=(int)$instance['id'];$counts=$this->stageCounts($orderId);$ownership=[];
        $requests=$this->db->fetchAll('SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
        $request=count($requests)===1?$requests[0]:null;
        if(count($requests)!==1)$ownership['cancel_requests_count']=count($requests);
        if($request){
            $allowedReasons=['bilateral_refund_pending','bilateral_refund_rejected','bilateral_refund_escalated','bilateral_refund_accepted','bilateral_refund_execution_required'];
            $valid=(string)$request['request_type']==='cancel_refund'&&(string)$request['applicant_type']==='user'&&(int)$request['applicant_id']===(int)$order['user_id']
                &&in_array((string)$request['status'],['pending','approved'],true)&&in_array((string)$request['reason_code'],$allowedReasons,true);
            if(!$valid)$ownership['cancel_request_unowned']=1;
        }

        $refunds=$this->db->fetchAll(
            "SELECT r.*,tf.id test_fact_id,tf.instance_id test_instance_id,tf.simulated_provider,p.app_id,p.mch_id,p.payer_openid,p.wechat_prepay_id,p.wechat_transaction_id
             FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id
             LEFT JOIN test_payment_facts tf ON tf.payment_order_id=p.id AND tf.instance_id=:instance
             WHERE r.order_id=:order_id ORDER BY r.id",
            ['instance'=>$instanceId,'order_id'=>$orderId]
        );
        if(count($refunds)>1)$ownership['refunds_multiple']=count($refunds);
        foreach($refunds as $refund){
            $valid=$request&&(int)$refund['cancel_request_id']===(int)$request['id']&&(int)$refund['test_fact_id']>0&&(int)$refund['test_instance_id']===$instanceId
                &&(string)$refund['simulated_provider']==='internal_test'&&(string)$refund['status']==='pending_review'&&empty($refund['wechat_refund_id'])
                &&(string)$refund['app_id']==='TEST_INTERNAL'&&(string)$refund['mch_id']==='TEST_INTERNAL'&&strpos((string)$refund['payer_openid'],'TEST_INTERNAL:')===0
                &&empty($refund['wechat_prepay_id'])&&empty($refund['wechat_transaction_id'])&&(int)$refund['amount_cents']===(int)$request['requested_refund_cents'];
            if(!$valid)$ownership['refund_unowned']=($ownership['refund_unowned']??0)+1;
        }
        if($request&&(string)$request['reason_code']==='bilateral_refund_accepted'&&(count($refunds)!==1||(string)$request['status']!=='approved'))$ownership['accepted_refund_shape_invalid']=1;
        if($request&&(string)$request['reason_code']!=='bilateral_refund_accepted'&&count($refunds)!==0)$ownership['premature_refund_rows']=count($refunds);

        $decisions=$this->db->fetchAll('SELECT * FROM order_cancel_decisions WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
        if(count($decisions)>1)$ownership['cancel_decisions_multiple']=count($decisions);
        foreach($decisions as $decision){
            $refund=count($refunds)===1?$refunds[0]:null;
            $valid=$request&&(int)$decision['cancel_request_id']===(int)$request['id']&&(string)$decision['decision']==='approved'
                &&(int)$decision['refund_amount_cents']===(int)$request['requested_refund_cents']
                &&(($refund&&(int)$decision['refund_id']===(int)$refund['id'])||(!$refund&&empty($decision['refund_id'])));
            if(!$valid)$ownership['cancel_decision_unowned']=($ownership['cancel_decision_unowned']??0)+1;
        }
        if($request&&(string)$request['reason_code']==='bilateral_refund_accepted'&&count($decisions)!==1)$ownership['accepted_decision_missing']=count($decisions);
        if($request&&(string)$request['reason_code']!=='bilateral_refund_accepted'&&count($decisions)!==0)$ownership['premature_cancel_decision']=count($decisions);

        if($this->db->tableExists('order_cancellation_risk_scores')){
            $risk=(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_cancellation_risk_scores WHERE order_id=:id',['id'=>$orderId]);
            if($risk>0)$ownership['cancellation_risk_unexpected']=$risk;
        }
        foreach($ownership as $key=>$count)$blocking[$key]=$count;
        $allowed=empty($blocking);$plan=['inner_plan_digest'=>(string)$base['plan_digest'],'instance_id'=>$instanceId,'instance_version'=>(int)($instance['version']??0),'order_id'=>$orderId,'recognized'=>$recognized,'stage_counts'=>$counts,'blocking'=>$blocking];
        $base['allowed']=$allowed;$base['blocking_relations']=$blocking;$base['stage4_counts']=$counts;$base['counts']=array_merge((array)($base['counts']??[]),$counts);$base['inner_plan_digest']=(string)$base['plan_digest'];$base['plan_digest']=$this->digest($plan);
        $base['reason']=$allowed?'取消协商、内部退款单和取消决定均已证明属于当前 testing 实例，可与前序业务事实在同一事务中清理。':'退款阶段存在真实微信标识、额外记录或无法证明所有权的数据，清理器拒绝盲删。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('退款测试清理仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $detail=$this->inner->instanceDetail($publicId);$order=is_array($detail['order']??null)?$detail['order']:null;$instance=(array)($detail['instance']??[]);
        if(!$this->adaptable($instance,$order))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        $preview=$this->cleanupPreview($publicId);if(empty($preview['stage4_counts']))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        if(empty($preview['allowed']))throw new TestCenterException((string)($preview['reason']??'退款清理已被阻断。'),'TEST_REFUND_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('退款清理范围在预览后发生变化，请重新预览。','TEST_REFUND_CLEANUP_PREVIEW_STALE',409,$preview);

        return$this->db->transaction(function(Database $db)use($publicId,$adminId,$expectedPlanDigest):array{
            $fresh=$this->cleanupPreview($publicId);if(empty($fresh['allowed'])||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest))throw new TestCenterException('退款清理范围在进入事务后发生变化，请重新预览。','TEST_REFUND_CLEANUP_PREVIEW_STALE',409,$fresh);
            $detail=$this->inner->instanceDetail($publicId);$order=(array)$detail['order'];$instance=(array)$detail['instance'];$orderId=(int)$order['id'];
            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>(int)$instance['id']]);
            $locked=$db->fetch('SELECT id,public_no,is_demo,order_source_type FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            if(!$locked||(int)$locked['is_demo']!==1||(string)$locked['order_source_type']!=='consumer'||strpos((string)$locked['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('退款清理最终边界校验失败。','TEST_REFUND_CLEANUP_BOUNDARY',409);
            if($db->tableExists('order_cancel_decisions'))$db->execute('DELETE FROM order_cancel_decisions WHERE order_id=:id',['id'=>$orderId]);
            if($db->tableExists('refunds'))$db->execute('DELETE FROM refunds WHERE order_id=:id',['id'=>$orderId]);
            if($db->tableExists('order_cancel_requests'))$db->execute('DELETE FROM order_cancel_requests WHERE order_id=:id',['id'=>$orderId]);
            $inner=$this->inner->cleanupPreview($publicId);if(empty($inner['allowed'])||empty($inner['plan_digest']))throw new TestCenterException('退款事实清理后，基础清理计划仍未通过；已整体回滚。','TEST_REFUND_BASE_CLEANUP_BLOCKED',409,$inner);
            $result=$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);$result['refund_cleanup']=(array)($fresh['stage4_counts']??[]);
            \audit('test_center.refund_cleanup','test_instance',(int)$instance['id'],'清理测试退款协商、内部退款单和取消决定事实');return$result;
        });
    }

    /** @return array<string,int> */
    private function stageCounts(int $orderId): array
    {
        $counts=[];foreach(['order_cancel_requests','refunds','order_cancel_decisions'] as $table)$counts[$table]=$this->db->tableExists($table)?(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]):0;return$counts;
    }
    private function adaptable(array $instance,?array $order): bool{return$order!==null&&strpos((string)($instance['scenario_key']??''),'ordinary.construction.multi_quote.waiting.')===0&&(int)($order['is_demo']??0)===1&&(string)($order['order_source_type']??'')==='consumer'&&strpos((string)($order['public_no']??''),'BXDEMOHALL')!==0;}
    /** @param mixed $value */ private function digest($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return hash('sha256',is_string($json)?$json:'{}');}
}

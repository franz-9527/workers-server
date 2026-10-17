<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseReviewRepository;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** M2 第五段：只清理当前 testing 实例明确拥有的维修售后事实。 */
final class DatabaseTestCenterRepairCleanupRepository implements TestCenterRepository
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
        $base=$this->inner->cleanupPreview($publicId);
        if(empty($base['plan_digest']))return$base;
        $detail=$this->inner->instanceDetail($publicId);$order=is_array($detail['order']??null)?$detail['order']:null;$instance=(array)($detail['instance']??[]);
        if(!$this->adaptable($instance,$order))return$base;

        $counts=$this->stageCounts((int)$order['id']);
        if(array_sum($counts)===0)return$base;
        $blocking=(array)($base['blocking_relations']??[]);$recognized=[];
        if(isset($blocking['after_sales'])&&(int)$blocking['after_sales']===(int)$counts['after_sales']){
            $recognized['after_sales']=(int)$blocking['after_sales'];unset($blocking['after_sales']);
        }
        if(isset($blocking['reviews'])&&(int)$blocking['reviews']===(int)$counts['repair_auto_reviews']){
            $recognized['reviews']=(int)$blocking['reviews'];unset($blocking['reviews']);
        }
        if(isset($blocking['appointment_records_unowned'])&&(int)$blocking['appointment_records_unowned']===(int)$counts['repair_appointments']){
            $recognized['appointment_records_unowned']=(int)$blocking['appointment_records_unowned'];unset($blocking['appointment_records_unowned']);
        }

        $orderId=(int)$order['id'];$workerId=(int)($order['assigned_worker_id']??0);$ownership=[];
        if((int)$counts['after_sales']>1)$ownership['repair_multiple']=(int)$counts['after_sales'];
        $otherAfterSales=(int)$this->db->fetchValue("SELECT COUNT(*) FROM after_sales WHERE order_id=:id AND type<>'warranty_repair'",['id'=>$orderId]);
        if($otherAfterSales>0)$ownership['other_after_sales']=$otherAfterSales;
        $repair=$this->db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        if($repair){
            if((int)$repair['user_id']!==(int)$order['user_id'])$ownership['repair_user_unowned']=1;
            if(!empty($repair['evidence_media_ids_json']))$ownership['repair_evidence_unadapted']=1;
            $badAppointments=(int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM appointment_records WHERE order_id=:order_id AND context_type='repair' AND (after_sale_id<>:after_sale OR worker_id<>:worker)",
                ['order_id'=>$orderId,'after_sale'=>(int)$repair['id'],'worker'=>$workerId]
            );
            if($badAppointments>0)$ownership['repair_appointment_unowned']=$badAppointments;
        }elseif((int)$counts['repair_appointments']>0)$ownership['repair_missing_for_appointment']=(int)$counts['repair_appointments'];
        if((int)$counts['repair_appointments']>1)$ownership['repair_appointments_multiple']=(int)$counts['repair_appointments'];

        $reviews=$this->db->fetchAll('SELECT * FROM reviews WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
        if(count($reviews)>1)$ownership['reviews_multiple']=count($reviews);
        foreach($reviews as $review){
            if((int)($review['is_auto']??0)!==1||(string)($review['content']??'')!=='系统自动评价'||(int)$review['user_id']!==(int)$order['user_id']||(int)$review['worker_id']!==$workerId){
                $ownership['review_unowned']=($ownership['review_unowned']??0)+1;
            }
        }
        if(count($reviews)!==(int)$counts['repair_auto_reviews'])$ownership['review_shape_changed']=abs(count($reviews)-(int)$counts['repair_auto_reviews']);
        if($this->db->tableExists('order_worker_contact_reveal_requests')){
            $contactRequests=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_worker_contact_reveal_requests WHERE order_id=:id AND context_type='repair'",['id'=>$orderId]);
            if($contactRequests>0)$ownership['repair_contact_reveal_unadapted']=$contactRequests;
        }
        foreach($ownership as$key=>$count)$blocking[$key]=$count;

        $allowed=empty($blocking);
        $plan=['inner_plan_digest'=>(string)$base['plan_digest'],'instance_id'=>(int)$instance['id'],'instance_version'=>(int)($instance['version']??0),'order_id'=>$orderId,'worker_id'=>$workerId,'recognized'=>$recognized,'stage_counts'=>$counts,'blocking'=>$blocking];
        $base['allowed']=$allowed;$base['blocking_relations']=$blocking;$base['repair_counts']=$counts;$base['counts']=array_merge((array)($base['counts']??[]),$counts);
        $base['inner_plan_digest']=(string)$base['plan_digest'];$base['plan_digest']=$this->digest($plan);
        $base['reason']=$allowed?'维修售后、维修预约和自动评价均已证明属于当前 testing 实例，可先删除售后专属事实再进入原清理链。':'维修售后存在未适配或无法证明所有权的数据，清理器拒绝盲删。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('维修售后测试清理仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $detail=$this->inner->instanceDetail($publicId);$order=is_array($detail['order']??null)?$detail['order']:null;$instance=(array)($detail['instance']??[]);
        if(!$this->adaptable($instance,$order))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        $preview=$this->cleanupPreview($publicId);
        if(empty($preview['repair_counts'])||array_sum((array)$preview['repair_counts'])===0)return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        if(empty($preview['allowed']))throw new TestCenterException((string)($preview['reason']??'维修售后清理已被阻断。'),'TEST_REPAIR_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('维修售后清理范围在预览后发生变化，请重新预览。','TEST_REPAIR_CLEANUP_PREVIEW_STALE',409,$preview);

        return$this->db->transaction(function(Database $db)use($publicId,$adminId,$expectedPlanDigest):array{
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed'])||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest))throw new TestCenterException('维修售后清理范围在进入事务后发生变化，请重新预览。','TEST_REPAIR_CLEANUP_PREVIEW_STALE',409,$fresh);
            $detail=$this->inner->instanceDetail($publicId);$order=(array)$detail['order'];$instance=(array)$detail['instance'];$orderId=(int)$order['id'];$workerId=(int)($order['assigned_worker_id']??0);
            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>(int)$instance['id']]);
            $locked=$db->fetch('SELECT id,public_no,is_demo,order_source_type FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            if(!$locked||(int)$locked['is_demo']!==1||(string)$locked['order_source_type']!=='consumer'||strpos((string)$locked['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('维修售后清理最终边界校验失败。','TEST_REPAIR_CLEANUP_BOUNDARY',409);

            $repair=$db->fetch("SELECT id FROM after_sales WHERE order_id=:id AND type='warranty_repair' ORDER BY id DESC LIMIT 1 FOR UPDATE",['id'=>$orderId]);
            if($repair)$db->execute("DELETE FROM appointment_records WHERE order_id=:order_id AND context_type='repair' AND after_sale_id=:after_sale",['order_id'=>$orderId,'after_sale'=>(int)$repair['id']]);
            $db->execute("DELETE FROM after_sales WHERE order_id=:id AND type='warranty_repair'",['id'=>$orderId]);
            $db->execute("DELETE FROM reviews WHERE order_id=:id AND is_auto=1 AND content='系统自动评价'",['id'=>$orderId]);
            if($workerId>0)(new DatabaseReviewRepository($db))->refreshWorkerSummary($workerId);

            $inner=$this->inner->cleanupPreview($publicId);
            if(empty($inner['allowed'])||empty($inner['plan_digest']))throw new TestCenterException('维修售后专属事实删除后，原清理链仍未通过；已整体回滚。','TEST_REPAIR_BASE_CLEANUP_BLOCKED',409,$inner);
            $result=$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
            $result['repair_cleanup']=(array)($fresh['repair_counts']??[]);
            \audit('test_center.repair_cleanup','test_instance',(int)$instance['id'],'清理维修售后、维修预约及测试自动评价');
            return$result;
        });
    }

    /** @return array<string,int> */
    private function stageCounts(int $orderId): array
    {
        return[
            'after_sales'=>$this->db->tableExists('after_sales')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM after_sales WHERE order_id=:id AND type='warranty_repair'",['id'=>$orderId]):0,
            'repair_appointments'=>$this->db->tableExists('appointment_records')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='repair'",['id'=>$orderId]):0,
            'repair_auto_reviews'=>$this->db->tableExists('reviews')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM reviews WHERE order_id=:id AND is_auto=1 AND content='系统自动评价'",['id'=>$orderId]):0,
        ];
    }

    /** @param array<string,mixed> $instance @param array<string,mixed>|null $order */
    private function adaptable(array $instance,?array $order): bool
    {
        return$order!==null&&strpos((string)($instance['scenario_key']??''),'ordinary.construction.multi_quote.waiting.')===0&&(int)($order['is_demo']??0)===1
            &&(string)($order['order_source_type']??'')==='consumer'&&strpos((string)($order['public_no']??''),'BXDEMOHALL')!==0;
    }
    /** @param mixed $value */ private function digest($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return hash('sha256',is_string($json)?$json:'{}');}
}

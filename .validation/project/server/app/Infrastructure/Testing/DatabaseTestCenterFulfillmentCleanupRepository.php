<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 第二段清理层。
 *
 * 只接管预约/完工/验收明确产生的普通测试订单子事实；其余场景原样交给既有清理器。
 * 删除第二段事实与调用内层清理器位于同一共享事务，任一步失败都会整体回滚。
 */
final class DatabaseTestCenterFulfillmentCleanupRepository implements TestCenterRepository
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
        if(!empty($base['allowed']))return$base;

        $orderId=(int)$order['id'];$instanceId=(int)$instance['id'];$workerId=(int)($order['assigned_worker_id']??0);
        $blocking=(array)($base['blocking_relations']??[]);
        $recognized=[];
        foreach(['appointment_records','commission_receivables','order_deadlines'] as $key){if(isset($blocking[$key])){$recognized[$key]=(int)$blocking[$key];unset($blocking[$key]);}}
        if(!$recognized)return$base;

        $counts=$this->stageCounts($orderId);
        $ownership=[];
        $badAppointments=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND (context_type<>'service' OR after_sale_id IS NOT NULL OR worker_id<>:worker)",
            ['id'=>$orderId,'worker'=>$workerId]
        );
        if($badAppointments>0)$ownership['appointment_records_unowned']=$badAppointments;
        $badCompletions=(int)$this->db->fetchValue('SELECT COUNT(*) FROM completion_submissions WHERE order_id=:id AND worker_id<>:worker',['id'=>$orderId,'worker'=>$workerId]);
        if($badCompletions>0)$ownership['completion_submissions_unowned']=$badCompletions;
        $badDeadlines=(int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM order_deadlines WHERE order_id=:id AND deadline_type NOT IN ('auto_accept','auto_accept_completion','auto_review','auto_review_order',:proxy_quote_expiry)",
            ['id'=>$orderId,'proxy_quote_expiry'=>$instance['scenario_key']===DatabaseTestCenterProxyOrderActionRunner::SCENARIO?'quote_expiry':'__not_allowed__']
        );
        if($badDeadlines>0)$ownership['order_deadlines_unadapted']=$badDeadlines;
        $badReceivables=(int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM commission_receivables WHERE order_id=:id AND (worker_id<>:worker OR assignment_id<>:assignment)',
            ['id'=>$orderId,'worker'=>$workerId,'assignment'=>(int)($order['current_assignment_id']??0)]
        );
        if($badReceivables>0)$ownership['commission_receivables_unowned']=$badReceivables;
        if((int)($counts['commission_receivables']??0)>1)$ownership['commission_receivables_multiple']=(int)$counts['commission_receivables'];
        foreach($ownership as $key=>$count)$blocking[$key]=$count;

        $allowed=empty($blocking);
        $plan=[
            'inner_plan_digest'=>(string)$base['plan_digest'],'instance_id'=>$instanceId,'instance_version'=>(int)($instance['version']??0),
            'order_id'=>$orderId,'worker_id'=>$workerId,'recognized'=>$recognized,'stage_counts'=>$counts,'blocking'=>$blocking,
        ];
        $base['allowed']=$allowed;$base['blocking_relations']=$blocking;$base['stage2_counts']=$counts;$base['counts']=array_merge((array)($base['counts']??[]),$counts);
        $base['inner_plan_digest']=(string)$base['plan_digest'];$base['plan_digest']=$this->digest($plan);
        $base['reason']=$allowed
            ?'预约、完工、验收及其服务费/余额台账均已证明属于当前 testing 实例，可与 M1 数据在同一事务中清理。'
            :'履约阶段存在未适配或无法证明所有权的数据，清理器拒绝盲删。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('履约测试清理仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $detail=$this->inner->instanceDetail($publicId);$order=is_array($detail['order']??null)?$detail['order']:null;$instance=(array)($detail['instance']??[]);
        if(!$this->adaptable($instance,$order))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);

        $preview=$this->cleanupPreview($publicId);
        if(empty($preview['allowed']))throw new TestCenterException((string)($preview['reason']??'履约清理已被阻断。'),'TEST_FULFILLMENT_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('履约清理范围在预览后发生变化，请重新预览。','TEST_FULFILLMENT_CLEANUP_PREVIEW_STALE',409,$preview);

        // 没有第二段事实时继续使用 M1 原清理计划，不重复接管。
        if(empty($preview['stage2_counts']))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);

        return$this->db->transaction(function(Database $db)use($publicId,$adminId,$expectedPlanDigest):array{
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed'])||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest))throw new TestCenterException('履约清理范围在进入事务后发生变化，请重新预览。','TEST_FULFILLMENT_CLEANUP_PREVIEW_STALE',409,$fresh);
            $detail=$this->inner->instanceDetail($publicId);$order=(array)$detail['order'];$instance=(array)$detail['instance'];
            $orderId=(int)$order['id'];$workerId=(int)($order['assigned_worker_id']??0);
            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>(int)$instance['id']]);
            $locked=$db->fetch('SELECT id,public_no,is_demo,order_source_type FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            if(!$locked||(int)$locked['is_demo']!==1||(string)$locked['order_source_type']!=='consumer'||strpos((string)$locked['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('履约清理最终边界校验失败。','TEST_FULFILLMENT_CLEANUP_BOUNDARY',409);

            if($db->tableExists('worker_balance_ledger')&&$db->tableExists('commission_receivables')){
                $db->execute(
                    "DELETE l FROM worker_balance_ledger l JOIN commission_receivables c ON c.id=l.business_id
                     WHERE l.business_type='commission' AND c.order_id=:order_id",
                    ['order_id'=>$orderId]
                );
            }
            if($db->tableExists('commission_receivables'))$db->execute('DELETE FROM commission_receivables WHERE order_id=:id',['id'=>$orderId]);
            if($db->tableExists('order_deadlines'))$db->execute('DELETE FROM order_deadlines WHERE order_id=:id',['id'=>$orderId]);
            if($db->tableExists('completion_submissions'))$db->execute('DELETE FROM completion_submissions WHERE order_id=:id',['id'=>$orderId]);
            if($db->tableExists('appointment_records'))$db->execute('DELETE FROM appointment_records WHERE order_id=:id',['id'=>$orderId]);

            $inner=$this->inner->cleanupPreview($publicId);
            if(empty($inner['allowed'])||empty($inner['plan_digest']))throw new TestCenterException('履约子事实清理后，基础清理计划仍未通过；已整体回滚。','TEST_FULFILLMENT_BASE_CLEANUP_BLOCKED',409,$inner);
            $result=$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
            if($workerId>0){
                $db->execute(
                    'UPDATE worker_profiles SET completed_orders=(SELECT COUNT(*) FROM orders WHERE assigned_worker_id=:count_worker AND accepted_at IS NOT NULL),updated_at=UTC_TIMESTAMP() WHERE id=:worker',
                    ['count_worker'=>$workerId,'worker'=>$workerId]
                );
            }
            $result['fulfillment_cleanup']=(array)($fresh['stage2_counts']??[]);
            \audit('test_center.fulfillment_cleanup','test_instance',(int)$instance['id'],'清理预约/完工/验收测试事实并回算测试师傅完工单数');
            return$result;
        });
    }

    /** @return array<string,int> */
    private function stageCounts(int $orderId): array
    {
        $counts=[];
        foreach(['appointment_records','completion_submissions','order_deadlines','commission_receivables'] as $table){
            $counts[$table]=$this->db->tableExists($table)?(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]):0;
        }
        $counts['commission_ledger']=($this->db->tableExists('worker_balance_ledger')&&$this->db->tableExists('commission_receivables'))
            ?(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger l JOIN commission_receivables c ON c.id=l.business_id WHERE l.business_type='commission' AND c.order_id=:id",['id'=>$orderId]):0;
        return$counts;
    }

    /** @param array<string,mixed> $instance @param array<string,mixed>|null $order */
    private function adaptable(array $instance,?array $order): bool
    {
        return$order!==null&&$this->supports((string)($instance['scenario_key']??''))&&(int)($order['is_demo']??0)===1
            &&(string)($order['order_source_type']??'')==='consumer'&&strpos((string)($order['public_no']??''),'BXDEMOHALL')!==0;
    }
    private function supports(string $key): bool{return strpos($key,'ordinary.construction.multi_quote.waiting.')===0||in_array($key,['ordinary.construction.proxy.draft','ordinary.construction.multi_quote.waiting.invitation','ordinary.construction.single_grab.waiting.open','ordinary.construction.single_grab.waiting.negotiated'],true);}
    /** @param mixed $value */ private function digest($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return hash('sha256',is_string($json)?$json:'{}');}
}

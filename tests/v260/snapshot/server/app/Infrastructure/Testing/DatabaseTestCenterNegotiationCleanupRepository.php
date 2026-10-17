<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 第三段清理层：只接管测试中心真实加价协商产生的 change / confirmation / fee dispute。
 *
 * 先证明这些记录都属于当前 testing 实例，再删除并委托履约/M1 清理；全程共享一个事务。
 */
final class DatabaseTestCenterNegotiationCleanupRepository implements TestCenterRepository
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

        $orderId=(int)$order['id'];$instanceId=(int)$instance['id'];
        $blocking=(array)($base['blocking_relations']??[]);$recognized=[];
        foreach(['change_requests','disputes'] as $key){if(isset($blocking[$key])){$recognized[$key]=(int)$blocking[$key];unset($blocking[$key]);}}
        if(!$recognized)return$base;

        $counts=$this->stageCounts($orderId);$ownership=[];
        $change=$this->db->fetch('SELECT * FROM change_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
        if((int)$counts['change_requests']!==1)$ownership['change_requests_count']=(int)$counts['change_requests'];
        if(!$change)$ownership['change_request_missing']=1;
        elseif((int)$change['worker_id']!==(int)($order['assigned_worker_id']??0)||!in_array((string)$change['status'],['pending','rejected'],true))$ownership['change_request_unowned']=1;

        $confirmation=null;
        if($change)$confirmation=$this->db->fetch('SELECT * FROM change_confirmations WHERE change_request_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$change['id']]);
        if((int)$counts['change_confirmations']>1)$ownership['change_confirmations_multiple']=(int)$counts['change_confirmations'];
        if((int)$counts['change_confirmations']===1&&(!$confirmation||(string)$confirmation['decision']!=='rejected'||(int)$confirmation['user_id']!==(int)$order['user_id']))$ownership['change_confirmation_unowned']=1;
        if($change&&(string)$change['status']==='pending'&&(int)$counts['change_confirmations']!==0)$ownership['pending_change_has_confirmation']=(int)$counts['change_confirmations'];
        if($change&&(string)$change['status']==='rejected'&&(int)$counts['change_confirmations']!==1)$ownership['rejected_change_confirmation_missing']=(int)$counts['change_confirmations'];

        $dispute=$this->db->fetch("SELECT * FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        if((int)$counts['disputes']>1)$ownership['fee_disputes_multiple']=(int)$counts['disputes'];
        $allDisputes=(int)$this->db->fetchValue('SELECT COUNT(*) FROM disputes WHERE order_id=:id',['id'=>$orderId]);
        if($allDisputes!==(int)$counts['disputes'])$ownership['other_dispute_types']=$allDisputes-(int)$counts['disputes'];
        if($dispute){
            $valid=(string)$dispute['status']==='open'&&(string)$dispute['raised_by_type']==='user'&&(int)$dispute['raised_by_id']===(int)$order['user_id']
                &&$change&&strpos((string)$dispute['description'],(string)$change['public_id'])!==false;
            if(!$valid)$ownership['fee_dispute_unowned']=1;
        }
        foreach($ownership as $key=>$count)$blocking[$key]=$count;

        $allowed=empty($blocking);
        $plan=['inner_plan_digest'=>(string)$base['plan_digest'],'instance_id'=>$instanceId,'instance_version'=>(int)($instance['version']??0),'order_id'=>$orderId,'recognized'=>$recognized,'stage_counts'=>$counts,'blocking'=>$blocking];
        $base['allowed']=$allowed;$base['blocking_relations']=$blocking;$base['stage3_counts']=$counts;$base['counts']=array_merge((array)($base['counts']??[]),$counts);
        $base['inner_plan_digest']=(string)$base['plan_digest'];$base['plan_digest']=$this->digest($plan);
        $base['reason']=$allowed
            ?'加价申请、客户拒绝确认和费用争议均已证明属于当前 testing 实例，可与履约/M1 数据在同一事务中清理。'
            :'协商阶段存在未适配或无法证明所有权的数据，清理器拒绝盲删。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('协商测试清理仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $detail=$this->inner->instanceDetail($publicId);$order=is_array($detail['order']??null)?$detail['order']:null;$instance=(array)($detail['instance']??[]);
        if(!$this->adaptable($instance,$order))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        $preview=$this->cleanupPreview($publicId);
        if(empty($preview['stage3_counts']))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        if(empty($preview['allowed']))throw new TestCenterException((string)($preview['reason']??'协商清理已被阻断。'),'TEST_NEGOTIATION_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('协商清理范围在预览后发生变化，请重新预览。','TEST_NEGOTIATION_CLEANUP_PREVIEW_STALE',409,$preview);

        return$this->db->transaction(function(Database $db)use($publicId,$adminId,$expectedPlanDigest):array{
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed'])||!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest))throw new TestCenterException('协商清理范围在进入事务后发生变化，请重新预览。','TEST_NEGOTIATION_CLEANUP_PREVIEW_STALE',409,$fresh);
            $detail=$this->inner->instanceDetail($publicId);$order=(array)$detail['order'];$instance=(array)$detail['instance'];$orderId=(int)$order['id'];
            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>(int)$instance['id']]);
            $locked=$db->fetch('SELECT id,public_no,is_demo,order_source_type FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            if(!$locked||(int)$locked['is_demo']!==1||(string)$locked['order_source_type']!=='consumer'||strpos((string)$locked['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('协商清理最终边界校验失败。','TEST_NEGOTIATION_CLEANUP_BOUNDARY',409);

            if($db->tableExists('change_confirmations'))$db->execute('DELETE cc FROM change_confirmations cc JOIN change_requests c ON c.id=cc.change_request_id WHERE c.order_id=:id',['id'=>$orderId]);
            if($db->tableExists('disputes'))$db->execute("DELETE FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation'",['id'=>$orderId]);
            if($db->tableExists('change_requests'))$db->execute('DELETE FROM change_requests WHERE order_id=:id',['id'=>$orderId]);

            $inner=$this->inner->cleanupPreview($publicId);
            if(empty($inner['allowed'])||empty($inner['plan_digest']))throw new TestCenterException('协商事实清理后，基础清理计划仍未通过；已整体回滚。','TEST_NEGOTIATION_BASE_CLEANUP_BLOCKED',409,$inner);
            $result=$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
            $result['negotiation_cleanup']=(array)($fresh['stage3_counts']??[]);
            \audit('test_center.negotiation_cleanup','test_instance',(int)$instance['id'],'清理测试加价协商、拒绝确认和平台争议事实');
            return$result;
        });
    }

    private function stageCounts(int $orderId): array
    {
        $changes=$this->db->tableExists('change_requests')?(int)$this->db->fetchValue('SELECT COUNT(*) FROM change_requests WHERE order_id=:id',['id'=>$orderId]):0;
        $confirmations=($changes>0&&$this->db->tableExists('change_confirmations'))?(int)$this->db->fetchValue('SELECT COUNT(*) FROM change_confirmations cc JOIN change_requests c ON c.id=cc.change_request_id WHERE c.order_id=:id',['id'=>$orderId]):0;
        $disputes=$this->db->tableExists('disputes')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation'",['id'=>$orderId]):0;
        return['change_requests'=>$changes,'change_confirmations'=>$confirmations,'disputes'=>$disputes];
    }

    private function adaptable(array $instance,?array $order): bool
    {
        return$order!==null&&strpos((string)($instance['scenario_key']??''),'ordinary.construction.multi_quote.waiting.')===0
            &&(int)($order['is_demo']??0)===1&&(string)($order['order_source_type']??'')==='consumer'&&strpos((string)($order['public_no']??''),'BXDEMOHALL')!==0;
    }

    private function digest($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return hash('sha256',is_string($json)?$json:'{}');}
}

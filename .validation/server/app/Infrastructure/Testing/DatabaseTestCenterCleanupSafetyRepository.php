<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 测试中心清理最外层并发安全门禁。
 *
 * cleanupPreview 将正在执行的 test_runs 作为显式阻断；cleanup 与真实动作共用实例级
 * MySQL named lock，避免清理与业务状态推进交叉执行。
 */
final class DatabaseTestCenterCleanupSafetyRepository implements TestCenterRepository
{
    private Database $db;
    private TestCenterRepository $inner;

    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function verify(string $publicId,int $adminId): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试验证仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        if($adminId<1)throw new TestCenterException('管理员身份无效。','TEST_ADMIN_REQUIRED',403);
        $instance=$this->instance(trim($publicId));
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $instanceId=(int)$instance['id'];$lock=$this->lockName($instanceId);
        if((int)$this->db->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$lock])!==1){
            throw new TestCenterException('当前实例正在执行动作、验证或清理，请稍后重试。','TEST_VERIFY_INSTANCE_BUSY',409);
        }
        $lastRunId=null;
        try{
            $fresh=$this->instance(trim($publicId));
            if(!$fresh||empty($fresh['order_id'])||!empty($fresh['archived_at'])||!empty($fresh['deleted_at'])||!in_array((string)$fresh['build_status'],['built','build_invalid'],true)){
                throw new TestCenterException('当前实例已清理、归档或尚未构建完成，不能重新检查。','TEST_VERIFY_INSTANCE_NOT_WRITABLE',409);
            }
            if($this->runningCount($instanceId)>0)throw new TestCenterException('当前实例已有动作正在执行，请稍后重试。','TEST_VERIFY_INSTANCE_BUSY',409);
            $lastRunId=(int)$this->db->fetchValue('SELECT COALESCE(MAX(id),0) FROM test_runs WHERE instance_id=:instance',['instance'=>$instanceId]);
            return$this->inner->verify($publicId,$adminId);
        }catch(Throwable $exception){
            if($lastRunId!==null){
                try{
                    $this->db->execute("UPDATE test_runs SET result='failed',error_code='TEST_VERIFY_FAILED',error_message=:message,finished_at=UTC_TIMESTAMP() WHERE instance_id=:instance AND id>:last_id AND result='running' AND operation_mode='inspect' AND action_key LIKE 'verify%'",['message'=>mb_substr($exception->getMessage(),0,1000),'instance'=>$instanceId,'last_id'=>$lastRunId]);
                    $this->db->execute("UPDATE test_instances SET verification_status='failed',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:instance",['instance'=>$instanceId]);
                }catch(Throwable $ignored){}
            }
            throw $exception;
        }finally{
            try{$this->db->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lock]);}catch(Throwable $ignored){}
        }
    }
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function instanceDetail(string $publicId): array
    {
        $detail=$this->inner->instanceDetail($publicId);
        $detail['cleanup']=$this->cleanupPreview($publicId);
        return$detail;
    }

    public function cleanupPreview(string $publicId): array
    {
        $base=$this->inner->cleanupPreview($publicId);
        $instance=$this->instance(trim($publicId));
        if(!$instance||empty($base['plan_digest']))return$base;
        $blocking=(array)($base['blocking_relations']??[]);
        $running=$this->runningCount((int)$instance['id']);
        if($running>0)$blocking['test_runs_running']=$running;
        if((string)($instance['build_status']??'')==='cleaning')$blocking['instance_cleaning']=1;
        $allowed=!empty($base['allowed'])&&empty($blocking);
        $digest=$this->digest([
            'inner_plan_digest'=>(string)$base['plan_digest'],
            'instance_id'=>(int)$instance['id'],
            'instance_version'=>(int)($instance['version']??0),
            'build_status'=>(string)($instance['build_status']??''),
            'running'=>$running,
            'blocking'=>$blocking,
        ]);
        $base['inner_plan_digest']=(string)$base['plan_digest'];
        $base['plan_digest']=$digest;
        $base['allowed']=$allowed;
        $base['blocking_relations']=$blocking;
        if(!$allowed&&($running>0||(string)($instance['build_status']??'')==='cleaning'))$base['reason']='当前测试实例仍有动作正在执行或清理已开始，禁止并发删除业务数据。';
        return$base;
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试清理安全门禁只允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $instance=$this->instance(trim($publicId));
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $lock=$this->lockName((int)$instance['id']);
        if((int)$this->db->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$lock])!==1){
            throw new TestCenterException('当前测试实例正在执行其它动作或清理，请稍后重新预览。','TEST_CLEANUP_INSTANCE_BUSY',409);
        }
        try{
            $fresh=$this->cleanupPreview($publicId);
            if(empty($fresh['allowed']))throw new TestCenterException((string)($fresh['reason']??'清理已被并发安全门禁阻断。'),'TEST_CLEANUP_SAFETY_BLOCKED',409,$fresh);
            if(!hash_equals((string)$fresh['plan_digest'],$expectedPlanDigest))throw new TestCenterException('清理范围在获得实例锁后发生变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409,$fresh);
            $inner=$this->inner->cleanupPreview($publicId);
            if(empty($inner['allowed'])||empty($inner['plan_digest']))throw new TestCenterException('底层清理计划已变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409,$inner);
            return$this->inner->cleanup($publicId,$adminId,(string)$inner['plan_digest']);
        }finally{
            try{$this->db->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lock]);}catch(Throwable $ignored){}
        }
    }

    private function instance(string $publicId): ?array
    {
        if($publicId==='')return null;
        $row=$this->db->fetch('SELECT id,order_id,build_status,version,archived_at,deleted_at FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
        return$row?:null;
    }
    private function runningCount(int $instanceId): int
    {
        if($instanceId<1||!$this->db->tableExists('test_runs'))return 0;
        return(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_runs WHERE instance_id=:instance AND result='running'",['instance'=>$instanceId]);
    }
    private function lockName(int $instanceId): string{return'test_center_instance_'.$instanceId;}
    /** @param mixed $value */ private function digest($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return hash('sha256',is_string($json)?$json:'{}');}
}

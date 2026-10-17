<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 测试中心真实动作最外层安全门禁。
 *
 * 统一阻止清理/归档/已删除实例继续写入，并通过 MySQL named lock 将动作与清理串行化。
 * 业务 runner 仍负责各自状态机和幂等语义；本层不改业务状态。
 */
final class DatabaseTestCenterActionSafetyRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;

    public function __construct(Database $db, TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
    }

    public function available(string $instancePublicId): array
    {
        $instance=$this->instance(trim($instancePublicId));
        if(!$instance)return$this->inner->available($instancePublicId);
        if(!$this->writable($instance)){
            return['items'=>[],'message'=>'当前测试实例正在清理、已经删除/归档，或构建状态异常；真实流程动作已关闭。'];
        }
        $running=$this->runningCount((int)$instance['id'],'');
        if($running>0)return['items'=>[],'message'=>'当前测试实例已有动作正在执行，请等待本次执行结束后刷新。'];
        return$this->inner->available($instancePublicId);
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试动作安全门禁只允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $instance=$this->instance(trim($instancePublicId));
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $existing=$requestKey!==''?$this->db->fetch('SELECT id FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]):null;
        if($existing)return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        $lock=$this->lockName((int)$instance['id']);
        if((int)$this->db->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$lock])!==1)throw new TestCenterException('当前测试实例正在执行其它动作或清理，请刷新后重试。','TEST_ACTION_INSTANCE_BUSY',409);
        try{
            $fresh=$this->instance(trim($instancePublicId));
            if(!$fresh||!$this->writable($fresh))throw new TestCenterException('测试实例已进入清理、删除、归档或异常状态，不能继续执行动作。','TEST_ACTION_INSTANCE_NOT_WRITABLE',409);
            $running=$this->runningCount((int)$fresh['id'],$requestKey);
            if($running>0)throw new TestCenterException('当前测试实例已有其它动作正在执行，请刷新后重试。','TEST_ACTION_INSTANCE_BUSY',409,['running_actions'=>$running]);
            $result=$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
            $runPublic=trim((string)($result['run_public_id']??''));
            if($runPublic!==''){$role=$this->canonicalRole($actionKey);if($role!=='')$this->db->execute('UPDATE test_runs SET actor_role=:role WHERE BINARY public_id=BINARY :public_id',['role'=>$role,'public_id'=>$runPublic]);}
            return$result;
        }finally{try{$this->db->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lock]);}catch(Throwable $ignored){}}
    }

    private function instance(string $publicId): ?array
    {
        if($publicId==='')return null;$row=$this->db->fetch('SELECT id,order_id,build_status,archived_at,deleted_at FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);return$row?:null;
    }
    private function writable(array $instance): bool{return(int)($instance['order_id']??0)>0&&(string)($instance['build_status']??'')==='built'&&empty($instance['archived_at'])&&empty($instance['deleted_at']);}
    private function runningCount(int $instanceId,string $exceptRequestKey): int
    {
        if($instanceId<1||!$this->db->tableExists('test_runs'))return 0;
        if($exceptRequestKey==='')return(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_runs WHERE instance_id=:instance AND result='running'",['instance'=>$instanceId]);
        return(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_runs WHERE instance_id=:instance AND result='running' AND BINARY idempotency_key<>BINARY :idem",['instance'=>$instanceId,'idem'=>$exceptRequestKey]);
    }
    private function canonicalRole(string $actionKey): string
    {
        if(strpos($actionKey,'admin_')===0)return'admin';if(strpos($actionKey,'worker_')===0)return'worker';if(strpos($actionKey,'customer_')===0)return'user';if(in_array($actionKey,['prepare_repair_warranty','repair_advance_timeout'],true))return'system';return'';
    }
    private function lockName(int $instanceId): string{return'test_center_instance_'.$instanceId;}
}

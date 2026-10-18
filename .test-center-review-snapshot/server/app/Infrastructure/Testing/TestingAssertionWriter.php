<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Domain\Testing\TestCenterException;

/** 记录测试断言快照，不修改业务订单。 */
final class TestingAssertionWriter
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function write(int $instance,string $action,array $checks,int $admin): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试断言只允许 testing。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $public=\public_id();$json=(string)json_encode($checks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $run=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,:action,'admin','inspect',:key,:digest,:request,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public,'instance'=>$instance,'action'=>$action,'key'=>'assert:'.bin2hex(random_bytes(12)),'digest'=>hash('sha256',$json),'request'=>\request_id(),'admin'=>$admin]);
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instance]);$failed=0;
        foreach($checks as[$key,$expected,$ok,$actual]){if(!$ok)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,'progress',:key,:status,:expected,:actual,'{}',UTC_TIMESTAMP())",['instance'=>$instance,'run'=>$run,'key'=>$key,'status'=>$ok?'passed':'failed','expected'=>$expected,'actual'=>$actual]);}
        $status=$failed?'failed':'passed';$this->db->execute('UPDATE test_runs SET result=:status,after_summary_json=:after,finished_at=UTC_TIMESTAMP() WHERE id=:id',['status'=>$status,'after'=>$json,'id'=>$run]);$this->db->execute('UPDATE test_instances SET verification_status=:status,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id',['status'=>$status,'id'=>$instance]);
        return['status'=>$status,'failed'=>$failed,'total'=>count($checks),'run_public_id'=>$public];
    }
}

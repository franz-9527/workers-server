<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 受控测试命令的持久化请求键、审计与实例边界。回调只能由服务端白名单适配器装配。 */
final class TestingActionExecution
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function context(string $publicId): array
    {
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :public LIMIT 1',['public'=>trim($publicId)]);
        if(!$i)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $o=!empty($i['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]):null;
        if($o&&((int)$o['is_demo']!==1||(string)$o['public_no']!==(string)$i['order_no_snapshot']||strpos((string)$o['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('订单不属于当前测试实例。','TEST_ACTION_ORDER_BOUNDARY',403);
        return[$i,$o];
    }
    public function run(string $publicId,string $action,array $command,int $adminId,string $request,string $role,string $mode,callable $execute): array
    {
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试动作仅允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        if($adminId<1)throw new TestCenterException('管理员身份无效。','TEST_ADMIN_REQUIRED',403);
        if(preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$request)!==1)throw new TestCenterException('动作请求键无效。','TEST_ACTION_REQUEST_KEY_INVALID',422);
        [$i,$o]=$this->context($publicId);
        $digest=hash('sha256',$this->json(['action'=>$action,'order'=>(int)($o['id']??0),'command'=>$command]));
        $prior=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :key LIMIT 1',['instance'=>(int)$i['id'],'key'=>$request]);
        if($prior){
            if(!hash_equals((string)$prior['request_digest'],$digest))throw new TestCenterException('相同请求键对应的动作或参数已经变化。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if($prior['result']==='passed')return['replayed'=>true,'result'=>'passed','run_public_id'=>$prior['public_id'],'after'=>json_decode((string)$prior['after_summary_json'],true)];
            throw new TestCenterException((string)($prior['error_message']?:'动作正在执行或已失败，请查看原运行记录。'),(string)($prior['error_code']?:'TEST_ACTION_RUNNING'),409);
        }
        if(!$o||$i['build_status']!=='built'||!empty($i['archived_at'])||!empty($i['deleted_at']))throw new TestCenterException('测试实例当前不可写。','TEST_ACTION_INSTANCE_NOT_WRITABLE',409);
        $public=\public_id();$started=microtime(true);
        $run=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,:action,:role,:mode,:key,:digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public,'instance'=>(int)$i['id'],'action'=>$action,'role'=>$role,'mode'=>$mode,'key'=>$request,'digest'=>$digest,'request'=>\request_id(),'before'=>$this->json(['order'=>$o]),'admin'=>$adminId]);
        try{
            $business=$execute($i,$o,$command);
            $after=['business'=>$business,'order'=>$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$o['id']])];
            $this->finish($run,$started,'passed',$after,null,null);
            $this->db->execute("UPDATE test_instances SET verification_status='pending',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$i['id']]);
            return['replayed'=>false,'result'=>'passed','run_public_id'=>$public,'business'=>$business,'after'=>$after];
        }catch(Throwable $e){
            $code=$e instanceof OrderOperationException?$e->apiCode():($e instanceof TestCenterException?$e->businessCode():'TEST_ACTION_FAILED');
            $this->finish($run,$started,'failed',['order'=>$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$o['id']])],$code,$e->getMessage());
            if($e instanceof TestCenterException)throw$e;
            $status=$e instanceof OrderOperationException?(int)$e->getCode():500;
            throw new TestCenterException($e instanceof OrderOperationException?$e->getMessage():'测试动作失败，请查看运行记录。',$code,$status>=400&&$status<=599?$status:409,['run_public_id'=>$public]);
        }
    }
    private function finish(int $id,float $started,string $status,array $after,?string $code,?string $message): void
    {
        $this->db->execute('UPDATE test_runs SET result=:status,after_summary_json=:after,error_code=:code,error_message=:message,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id',['status'=>$status,'after'=>$this->json($after),'code'=>$code,'message'=>$message===null?null:mb_substr($message,0,1000),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$id]);
    }
    private function json(array $value): string{return(string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}

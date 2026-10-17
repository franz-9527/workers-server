<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 普通施工 single_grab：真实抢单 + 第二师傅正式失败探针。 */
final class DatabaseTestCenterSingleGrabActionRunner implements TestCenterActionRunner
{
    private const SCENARIO='ordinary.construction.single_grab.waiting.open';
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;
    private PlatformWorkerAgreementVersionProvider $agreements;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;$this->inner=$inner;$this->orders=new OrderApplicationContainer($db);$this->agreements=new PlatformWorkerAgreementVersionProvider($db);
    }

    public function available(string $instancePublicId): array
    {
        $context=$this->context($instancePublicId,false);
        if((string)$context['scenario_key']!==self::SCENARIO)return$this->inner->available($instancePublicId);
        $order=$context['order'];if(!$order)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $instance=(array)$context['instance'];$workers=$this->approvedParticipants((int)$instance['id']);
        if($this->openState($order)){
            return['items'=>['worker_grab'=>[
                'key'=>'worker_grab','label'=>'真实师傅抢单','description'=>'调用正式 orders.grab；固定期望总价场景抢单成功即成交，并生成 selected quote、accepted assignment 与服务费规则快照。',
                'workers'=>$workers,'default_worker_slot'=>(string)($workers[0]['slot_key']??''),'amount_cents'=>(int)($order['expected_fee_cents']??0),
            ]],'message'=>'第一位测试师傅会通过正式 single_grab 状态机竞争订单。'];
        }
        if($this->dealtState($order)){
            $probe=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_runs WHERE instance_id=:instance AND action_key='worker_grab_competitor' AND result='passed' AND error_code='ORDER_ALREADY_GRABBED'",['instance'=>(int)$instance['id']]);
            if($probe>0)return['items'=>[],'message'=>'已验证第一位师傅唯一成交，第二位师傅通过正式 orders.grab 收到 ORDER_ALREADY_GRABBED。'];
            $competitors=[];foreach($workers as $worker)if((int)$worker['worker_id']!==(int)$order['assigned_worker_id'])$competitors[]=$worker;
            if($competitors)return['items'=>['worker_grab_competitor'=>[
                'key'=>'worker_grab_competitor','label'=>'验证第二师傅抢单失败','description'=>'以另一名 approved 测试师傅再次调用正式 orders.grab；只有返回 ORDER_ALREADY_GRABBED 才视为通过。',
                'workers'=>$competitors,'default_worker_slot'=>(string)$competitors[0]['slot_key'],'expected_error_code'=>'ORDER_ALREADY_GRABBED',
            ]],'message'=>'订单已经由第一位测试师傅成交；现在验证竞争失败方得到明确业务错误且不会产生第二个 assignment。'];
        }
        return['items'=>[],'message'=>'当前 single_grab 状态不属于已适配节点，请检查订单事实或重建实例。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!in_array($actionKey,['worker_grab','worker_grab_competitor'],true))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('单师傅抢单测试动作仅允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);
        if((string)$context['scenario_key']!==self::SCENARIO)throw new TestCenterException('当前场景未开放单师傅抢单测试。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效，请刷新后重试。','TEST_ACTION_REQUEST_KEY_INVALID',422);
        $instance=(array)$context['instance'];$order=(array)$context['order'];$normalized=$this->normalize($input);
        $digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id']]);
        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','error_code'=>(string)($existing['error_code']??''),'after'=>$this->decode((string)($existing['after_summary_json']??''))];
            if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);
            throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409,['run_public_id'=>$existing['public_id']]);
        }

        if($actionKey==='worker_grab'&&!$this->openState($order))throw new TestCenterException('当前订单已不处于公开待抢状态。','TEST_ACTION_STATE_CHANGED',409);
        if($actionKey==='worker_grab_competitor'&&!$this->dealtState($order))throw new TestCenterException('请先由第一位测试师傅完成抢单成交。','TEST_ACTION_STATE_CHANGED',409);
        $worker=$this->participant((int)$instance['id'],(string)$normalized['worker_slot']);
        if($actionKey==='worker_grab_competitor'&&(int)$worker['worker_id']===(int)$order['assigned_worker_id'])throw new TestCenterException('竞争失败探针必须选择另一名测试师傅。','TEST_SINGLE_GRAB_COMPETITOR_REQUIRED',422);

        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,:action_key,'worker','real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId]
        );

        try{
            $business=$this->formalGrab($order,$worker);
            if($actionKey==='worker_grab_competitor'){
                $this->failRun($runId,$started,'TEST_SINGLE_GRAB_COMPETITOR_UNEXPECTED_SUCCESS','第二位师傅竟然再次抢单成功，唯一性失效。',(int)$order['id']);
                throw new TestCenterException('第二位师傅竟然再次抢单成功，single_grab 唯一性失效。','TEST_SINGLE_GRAB_COMPETITOR_UNEXPECTED_SUCCESS',500);
            }
            $after=$this->summary((int)$order['id']);$this->passRun($runId,$started,$after,null,null);
            $this->touchInstance((int)$instance['id']);
            return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after];
        }catch(OrderOperationException $exception){
            if($actionKey==='worker_grab_competitor'&&$exception->apiCode()==='ORDER_ALREADY_GRABBED'){
                $after=$this->summary((int)$order['id']);$this->passRun($runId,$started,$after,'ORDER_ALREADY_GRABBED',$exception->getMessage());$this->touchInstance((int)$instance['id']);
                return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','expected_rejection'=>true,'error_code'=>'ORDER_ALREADY_GRABBED','message'=>$exception->getMessage(),'before'=>$before,'after'=>$after];
            }
            $this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);
            $status=(int)$exception->getCode();if($status<400||$status>599)$status=409;
            throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());
        }catch(TestCenterException $exception){throw$exception;}
        catch(Throwable $exception){$this->failRun($runId,$started,'TEST_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);throw new TestCenterException('单师傅抢单测试动作执行失败。','TEST_ACTION_FAILED',500,['run_public_id'=>$runPublic]);}
    }

    private function formalGrab(array $order,array $worker): array
    {
        $versions=$this->agreements->currentVersions();if(!$versions)throw new TestCenterException('当前没有可确认的师傅协议版本。','TEST_ACTION_AGREEMENTS_UNAVAILABLE',409);
        $actor=[
            'user_id'=>(int)$worker['user_id'],'real_user_id'=>(int)$worker['user_id'],'user_public_id'=>(string)$worker['user_public_id'],
            'worker_id'=>(int)$worker['worker_id'],'worker_public_id'=>(string)$worker['worker_public_id'],'worker_name'=>(string)$worker['display_name'],
            'worker_status'=>(string)$worker['verification_status'],'worker_lat'=>$worker['lat'],'worker_lng'=>$worker['lng'],
            'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false,
        ];
        return$this->orders->deal()->execute('orders.grab',$actor,[
            'order_id'=>(string)$order['public_no'],'fixed_price_acknowledged'=>true,'distance_acknowledged'=>true,
            'rule_acknowledged'=>true,'agreement_versions'=>$versions,
        ]);
    }

    private function participant(int $instanceId,string $slot): array
    {
        $row=$this->db->fetch(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,tp.expected_status,u.public_id user_public_id,
                    w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode,w.lat,w.lng
             FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND BINARY tp.slot_key=BINARY :slot LIMIT 1",
            ['instance'=>$instanceId,'slot'=>$slot]
        );
        if(!$row||(string)$row['expected_status']!=='approved'||(string)$row['verification_status']!=='approved'||(string)$row['cloud_record_mode']!=='demo_local')throw new TestCenterException('所选测试师傅不是当前实例登记的 approved testing 参与者。','TEST_ACTION_WORKER_INVALID',409);
        return$row;
    }

    private function approvedParticipants(int $instanceId): array
    {
        return$this->db->fetchAll(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,w.display_name,w.verification_status
             FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND tp.expected_status='approved' AND w.verification_status='approved' AND w.cloud_record_mode='demo_local' ORDER BY tp.id",
            ['instance'=>$instanceId]
        );
    }

    private function openState(array $order): bool
    {
        return(string)($order['order_source_type']??'')==='consumer'&&(string)($order['workflow_profile']??'')==='construction'&&(string)($order['dispatch_mode']??'')==='single_grab'
            &&(string)($order['audit_status']??'')==='approved'&&(string)($order['publish_status']??'')==='published'&&(string)($order['payment_status']??'')==='paid'
            &&(string)($order['assignment_status']??'unassigned')==='unassigned'&&(string)($order['lifecycle_status']??'')==='active'
            &&empty($order['assigned_worker_id'])&&empty($order['current_assignment_id'])&&empty($order['selected_quote_id'])
            &&(string)($order['expected_fee_type']??'')==='total'&&(int)($order['expected_fee_cents']??0)>0;
    }

    private function dealtState(array $order): bool
    {
        return(string)($order['dispatch_mode']??'')==='single_grab'&&(string)($order['assignment_status']??'')==='accepted'&&(string)($order['quote_status']??'')==='selected'
            &&(string)($order['publish_status']??'')==='hidden'&&(int)($order['assigned_worker_id']??0)>0&&(int)($order['current_assignment_id']??0)>0&&(int)($order['selected_quote_id']??0)>0;
    }

    private function context(string $publicId,bool $mustHaveOrder): array
    {
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $order=!empty($instance['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;
        if($mustHaveOrder&&!$order)throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        if($order&&((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可执行单抢测试的数据域。','TEST_ACTION_ORDER_BOUNDARY',409);
        return['instance'=>$instance,'scenario_key'=>(string)$instance['scenario_key'],'order'=>$order];
    }

    private function normalize(array $input): array
    {
        $slot=trim((string)($input['worker_slot']??''));if($slot===''||preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$slot)!==1)throw new TestCenterException('请选择测试师傅。','TEST_ACTION_WORKER_SLOT_REQUIRED',422);return['worker_slot'=>$slot];
    }

    private function summary(int $orderId): array
    {
        return[
            'order'=>$this->db->fetch('SELECT public_no,dispatch_mode,quote_status,assignment_status,publish_status,selected_quote_id,assigned_worker_id,current_assignment_id,final_amount_cents,expected_fee_cents,version FROM orders WHERE id=:id',['id'=>$orderId])?:[],
            'selected_quotes'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='selected'",['id'=>$orderId]),
            'assignments'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>$orderId]),
            'accepted_assignments'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status='accepted'",['id'=>$orderId]),
        ];
    }

    private function passRun(int $runId,float $started,array $after,?string $errorCode,?string $message): void
    {
        $this->db->execute("UPDATE test_runs SET result='passed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>$errorCode,'message'=>$message,'after_json'=>$this->json(['facts'=>$after]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);
    }
    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void
    {
        $this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after_json'=>$this->json(['facts'=>$this->summary($orderId)]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);
    }
    private function touchInstance(int $id): void{$this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$id]);}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @return array<string,mixed> */ private function decode(string $value): array{$decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[];}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 第四段：退款协商。
 *
 * 客户提出退款 -> 师傅接受/拒绝 -> 拒绝后重新提案或申请平台介入。
 * 接受方案只允许正式取消领域针对当前实例 TEST_INTERNAL 支付登记 refunds；本类不调用微信退款。
 */
final class DatabaseTestCenterRefundActionRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;
    private TestingRefundBoundaryGuard $funds;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;$this->inner=$inner;$this->orders=new OrderApplicationContainer($db);$this->funds=new TestingRefundBoundaryGuard($db);
    }

    public function available(string $instancePublicId): array
    {
        $inner=$this->inner->available($instancePublicId);
        $context=$this->context($instancePublicId,false);
        if(!$this->supports((string)$context['scenario_key']))return$inner;
        $order=$context['order'];if(!$order)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $instance=(array)$context['instance'];$request=$this->latestRequest((int)$order['id']);

        if($request){
            $reason=(string)$request['reason_code'];$status=(string)$request['status'];
            if($status==='pending'&&$reason==='bilateral_refund_pending'){
                return['items'=>[
                    'worker_accept_refund'=>[
                        'key'=>'worker_accept_refund','label'=>'真实师傅接受退款方案','reference'=>(string)$request['public_id'],
                        'amount_cents'=>(int)$request['requested_refund_cents'],
                        'description'=>'以成交测试师傅调用正式 exceptions.respond；只登记内部退款事实，不执行微信退款。',
                    ],
                    'worker_reject_refund'=>[
                        'key'=>'worker_reject_refund','label'=>'真实师傅拒绝退款方案','reference'=>(string)$request['public_id'],
                        'amount_cents'=>(int)$request['requested_refund_cents'],
                        'description'=>'拒绝后仍由双方继续协商；只有拒绝或超时后才允许申请平台介入。',
                    ],
                ],'message'=>'退款方案正在双方协商。接受会终止履约并登记 testing 内部退款单；不会向微信提交退款。'];
            }
            if($status==='pending'&&$reason==='bilateral_refund_rejected'){
                try{$funds=$this->funds->inspect((int)$instance['id'],$order,true);}catch(TestCenterException $exception){$funds=['available_cents'=>(int)$request['requested_refund_cents']];}
                return['items'=>[
                    'customer_repropose_refund'=>[
                        'key'=>'customer_repropose_refund','label'=>'真实客户重新提出退款方案','reference'=>(string)$request['public_id'],
                        'default_amount_cents'=>(int)($funds['available_cents']??0),
                        'description'=>'调用正式 refunds.propose；重新进入双方协商，不直接触发资金执行。',
                    ],
                    'customer_escalate_refund'=>[
                        'key'=>'customer_escalate_refund','label'=>'真实申请平台介入退款','reference'=>(string)$request['public_id'],
                        'description'=>'调用正式 exceptions.escalate；本阶段只进入平台介入语义，不执行管理员资金裁决。',
                    ],
                ],'message'=>'对方已拒绝退款方案，可重新协商，也可按正式规则申请平台介入。'];
            }
            if($status==='pending'&&$reason==='bilateral_refund_escalated'){
                return['items'=>[],'message'=>'退款协商已升级到平台介入；本阶段停在资金裁决前，不模拟管理员审核或微信退款。'];
            }
            if($reason==='bilateral_refund_execution_required'){
                return['items'=>[],'message'=>'双方已同意，但正式领域判定需要平台核账；测试中心不会绕过该财务边界。'];
            }
            if($status==='approved'&&$reason==='bilateral_refund_accepted'){
                return['items'=>[],'message'=>'双方退款方案已接受，内部退款单已登记；微信退款提交不属于本阶段测试动作。'];
            }
            return['items'=>[],'message'=>'退款事项已进入当前阶段不支持的后续状态，请查看数据检查和订单事件。'];
        }

        if($this->canOpenRefund((int)$instance['id'],$order)){
            $items=(array)($inner['items']??[]);$funds=$this->funds->inspect((int)$instance['id'],$order,true);
            $items['customer_refund_request']=[
                'key'=>'customer_refund_request','label'=>'真实客户提出退款方案',
                'default_amount_cents'=>(int)$funds['available_cents'],
                'description'=>'调用正式 orders.cancel.request；成交后先由师傅接受或拒绝，不自动进入平台审核。',
            ];
            return['items'=>$items,'message'=>'可继续正常履约、加价协商，或由客户进入真实退款协商；退款只允许使用当前实例拥有的 TEST_INTERNAL 支付。'];
        }
        return$inner;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        $allowed=['customer_refund_request','worker_accept_refund','worker_reject_refund','customer_repropose_refund','customer_escalate_refund'];
        if(!in_array($actionKey,$allowed,true))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('真实退款协商测试动作仅允许 testing 环境。','TEST_REFUND_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);
        if(!$this->supports((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放真实退款协商。','TEST_REFUND_ACTION_SCENARIO_UNSUPPORTED',409);
        $instance=(array)$context['instance'];$order=(array)$context['order'];
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效，请刷新后重试。','TEST_ACTION_REQUEST_KEY_INVALID',422);

        $normalized=$this->normalizeInput($actionKey,$input,(int)$instance['id'],$order);
        $digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id']]);
        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??''))];
            if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);
            throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409,['run_public_id'=>$existing['public_id']]);
        }

        $this->assertState($actionKey,(int)$instance['id'],$order);
        $actorRole=strpos($actionKey,'worker_')===0?'worker':'user';
        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,:action_key,:actor_role,'real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'actor_role'=>$actorRole,'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId]
        );
        try{
            $business=$this->execute($actionKey,(int)$instance['id'],$order,$normalized);
            $after=$this->summary((int)$order['id']);$duration=(int)round((microtime(true)-$started)*1000);
            $this->db->execute("UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]);
            $this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);
            return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after];
        }catch(TestCenterException $exception){$this->failRun($runId,$started,$exception->businessCode(),$exception->getMessage(),(int)$order['id']);throw$exception;}
        catch(OrderOperationException $exception){
            $this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);
            $status=(int)$exception->getCode();if($status<400||$status>599)$status=409;
            throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());
        }catch(Throwable $exception){
            $this->failRun($runId,$started,'TEST_REFUND_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);
            throw new TestCenterException('真实退款协商测试动作执行失败，请查看测试执行记录。','TEST_REFUND_ACTION_FAILED',500,['run_public_id'=>$runPublic]);
        }
    }

    /** @return array<string,mixed> */
    private function execute(string $actionKey,int $instanceId,array $order,array $input): array
    {
        if($actionKey==='customer_refund_request'){
            $this->funds->inspect($instanceId,$order,true);
            return$this->orders->cancellation()->request($this->userActor($order),['order_id'=>(string)$order['public_no'],'reason'=>(string)$input['note'],'reason_code'=>'user_request']);
        }
        $request=$this->latestRequest((int)$order['id']);if(!$request)throw new TestCenterException('退款协商事项不存在。','TEST_REFUND_REQUEST_NOT_FOUND',409);
        if($actionKey==='worker_reject_refund'||$actionKey==='worker_accept_refund'){
            if($actionKey==='worker_accept_refund')$this->funds->inspect($instanceId,$order,true);
            return$this->orders->negotiation()->execute('exceptions.respond',$this->workerActor($instanceId,$order),[
                'order_id'=>(string)$order['public_no'],'kind'=>'refund','reference'=>(string)$request['public_id'],
                'decision'=>$actionKey==='worker_accept_refund'?'accepted':'rejected','note'=>(string)$input['note'],
            ]);
        }
        if($actionKey==='customer_repropose_refund'){
            $funds=$this->funds->inspect($instanceId,$order,true);
            return$this->orders->negotiation()->execute('refunds.propose',$this->userActor($order),[
                'order_id'=>(string)$order['public_no'],'kind'=>'refund','reference'=>(string)$request['public_id'],
                'amount_cents'=>(int)$funds['available_cents'],'note'=>(string)$input['note'],
            ]);
        }
        return$this->orders->negotiation()->execute('exceptions.escalate',$this->userActor($order),[
            'order_id'=>(string)$order['public_no'],'kind'=>'refund','reference'=>(string)$request['public_id'],'note'=>(string)$input['note'],
        ]);
    }

    private function normalizeInput(string $actionKey,array $input,int $instanceId,array $order): array
    {
        if($actionKey==='customer_refund_request')$default='测试中心：客户希望取消本次施工并退还当前可退预约费';
        elseif($actionKey==='worker_accept_refund')$default='测试中心：师傅同意客户退款方案';
        elseif($actionKey==='worker_reject_refund')$default='测试中心：师傅不同意当前退款方案，双方继续协商';
        elseif($actionKey==='customer_repropose_refund')$default='测试中心：客户重新提出按当前可退金额退款';
        else$default='测试中心：退款协商被拒绝，申请平台介入处理';
        $note=mb_substr(trim((string)($input['note']??$default)),0,500);
        if(mb_strlen($note)<4)throw new TestCenterException('请填写至少 4 个字的退款协商说明。','TEST_REFUND_NOTE_REQUIRED',422);
        $normalized=['note'=>$note];
        if($actionKey==='customer_refund_request'||$actionKey==='customer_repropose_refund'){
            $funds=$this->funds->inspect($instanceId,$order,true);$normalized['amount_cents']=(int)$funds['available_cents'];
        }
        return$normalized;
    }

    private function assertState(string $actionKey,int $instanceId,array $order): void
    {
        $request=$this->latestRequest((int)$order['id']);
        if($actionKey==='customer_refund_request')$ok=$request===null&&$this->canOpenRefund($instanceId,$order);
        elseif($actionKey==='worker_accept_refund'||$actionKey==='worker_reject_refund')$ok=$request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_pending'&&(string)$request['applicant_type']==='user'&&(string)$order['lifecycle_status']==='active';
        else$ok=$request&&(string)$request['status']==='pending'&&(string)$request['reason_code']==='bilateral_refund_rejected'&&(string)$order['lifecycle_status']==='active';
        if(!$ok)throw new TestCenterException('当前退款协商状态已经变化，不能执行该测试动作。','TEST_REFUND_ACTION_STATE_CHANGED',409);
    }

    private function canOpenRefund(int $instanceId,array $order): bool
    {
        if(!$this->dealtState($order)||(string)($order['lifecycle_status']??'')!=='active'||in_array((string)($order['fulfillment_status']??''),['submitted','accepted'],true)
            ||!in_array((string)($order['refund_status']??'none'),['','none'],true)||$this->latestRequest((int)$order['id'])!==null)return false;
        if($this->db->fetchValue("SELECT id FROM change_requests WHERE order_id=:id AND status IN ('pending','processing','negotiating','rejected') LIMIT 1",['id'=>$order['id']]))return false;
        if($this->db->fetchValue("SELECT id FROM disputes WHERE order_id=:id AND status IN ('negotiating','open','pending','processing') LIMIT 1",['id'=>$order['id']]))return false;
        try{$this->funds->inspect($instanceId,$order,true);return true;}catch(TestCenterException $exception){return false;}
    }

    private function dealtState(array $order): bool
    {
        return(string)($order['order_source_type']??'')==='consumer'&&(string)($order['workflow_profile']??'construction')==='construction'
            &&(string)($order['dispatch_mode']??'multi_quote')==='multi_quote'&&(string)($order['quote_status']??'')==='selected'
            &&(string)($order['assignment_status']??'')==='accepted'&&(int)($order['selected_quote_id']??0)>0
            &&(int)($order['assigned_worker_id']??0)>0&&(int)($order['current_assignment_id']??0)>0;
    }

    private function latestRequest(int $orderId): ?array{return$this->db->fetch('SELECT * FROM order_cancel_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);}

    private function userActor(array $order): array{return['user_id'=>(int)$order['user_id'],'real_user_id'=>(int)$order['user_id'],'worker_id'=>null,'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false];}

    private function workerActor(int $instanceId,array $order): array
    {
        $workerId=(int)($order['assigned_worker_id']??0);
        $row=$this->db->fetch(
            "SELECT tp.user_id,tp.worker_id,tp.expected_status,u.public_id user_public_id,w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode
             FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND tp.worker_id=:worker LIMIT 1",
            ['instance'=>$instanceId,'worker'=>$workerId]
        );
        if(!$row||(string)$row['expected_status']!=='approved'||(string)$row['verification_status']!=='approved'||(string)$row['cloud_record_mode']!=='demo_local')throw new TestCenterException('成交师傅不是当前实例登记的已审核 testing 参与者。','TEST_REFUND_WORKER_INVALID',409);
        return['user_id'=>(int)$row['user_id'],'real_user_id'=>(int)$row['user_id'],'user_public_id'=>(string)$row['user_public_id'],'worker_id'=>(int)$row['worker_id'],'worker_public_id'=>(string)$row['worker_public_id'],'worker_name'=>(string)$row['display_name'],'worker_status'=>(string)$row['verification_status'],'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false];
    }

    private function supports(string $key): bool{return in_array($key,['ordinary.construction.multi_quote.waiting.zero_quotes','ordinary.construction.multi_quote.waiting.one_quote','ordinary.construction.multi_quote.waiting.three_quotes'],true);}

    /** @return array<string,mixed> */
    private function context(string $publicId,bool $mustHaveOrder): array
    {
        $publicId=trim($publicId);$instance=$publicId!==''?$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]):null;
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $order=!empty($instance['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>(int)$instance['order_id']]):null;
        if($mustHaveOrder&&!$order)throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        if($order&&((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可执行退款测试的数据域。','TEST_REFUND_ACTION_ORDER_BOUNDARY',409);
        return['instance'=>$instance,'scenario_key'=>(string)$instance['scenario_key'],'order'=>$order];
    }

    /** @return array<string,mixed> */
    private function summary(int $orderId): array
    {
        $order=$this->db->fetch('SELECT public_no,publish_status,quote_status,assignment_status,fulfillment_status,lifecycle_status,refund_status,dispute_status,selected_quote_id,assigned_worker_id,current_assignment_id,version FROM orders WHERE id=:id',['id'=>$orderId]);
        $request=$this->latestRequest($orderId);
        $refunds=$this->db->fetchAll('SELECT id,public_no,payment_order_id,cancel_request_id,amount_cents,status,wechat_refund_id FROM refunds WHERE order_id=:id ORDER BY id',['id'=>$orderId]);
        return['order'=>$order?:[],'cancel_request'=>$request?:[],'refunds'=>$refunds,'refund_count'=>count($refunds)];
    }

    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void
    {
        $this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after_json'=>$this->json(['facts'=>$this->summary($orderId)]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);
    }
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @return array<string,mixed> */ private function decode(string $value): array{$decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[];}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
}

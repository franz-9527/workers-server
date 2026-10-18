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
 * M2 第三段：成交后的加价协商 -> 客户拒绝 -> 平台介入。
 *
 * 仍然只调用正式 OrderFulfillment / OrderNegotiation 用例；测试中心不直接写 change_requests、
 * change_confirmations 或 disputes。拒绝前不提供平台介入按钮，用真实 NegotiationPolicy 验证三级处理模型。
 */
final class DatabaseTestCenterNegotiationActionRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;

    public function __construct(Database $db, TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->orders=new OrderApplicationContainer($db);
    }

    public function available(string $instancePublicId): array
    {
        $inner=$this->inner->available($instancePublicId);
        $context=$this->context($instancePublicId,false);
        if(!$this->supportsPositiveScenario((string)$context['scenario_key']))return$inner;
        $order=$context['order'];
        if(!$order)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $instance=(array)$context['instance'];
        $change=$this->latestChange((int)$order['id']);
        $dispute=$this->activeFeeDispute((int)$order['id']);

        if($dispute){
            return['items'=>[],'message'=>'加价协商已升级为平台争议，当前测试实例停在“平台介入”节点；平台裁决后续阶段再接入。'];
        }
        if($change&&((string)$change['status']==='pending')){
            return['items'=>[
                'customer_reject_change'=>[
                    'key'=>'customer_reject_change','label'=>'真实客户拒绝加价',
                    'description'=>'以订单客户调用正式 changes.decide，拒绝后才开放平台介入。',
                    'change_id'=>(string)$change['public_id'],'added_amount_cents'=>(int)$change['added_amount_cents'],
                ],
            ],'message'=>'当前加价仍在双方协商阶段；测试中心不会提前显示平台介入。'];
        }
        if($change&&((string)$change['status']==='rejected')){
            return['items'=>[
                'customer_escalate_change'=>[
                    'key'=>'customer_escalate_change','label'=>'真实申请平台介入',
                    'description'=>'客户拒绝加价后调用正式 exceptions.escalate；由 NegotiationPolicy 校验介入资格并创建争议。',
                    'change_id'=>(string)$change['public_id'],
                ],
            ],'message'=>'对方已经拒绝方案，符合“先协商 → 拒绝 → 平台介入”的升级条件。'];
        }

        if($this->canCreateChange($order,(int)$instance['id'])){
            $items=(array)($inner['items']??[]);
            $worker=$this->assignedParticipant((int)$instance['id'],$order,false);
            if($worker){
                $items['worker_change_request']=[
                    'key'=>'worker_change_request','label'=>'真实师傅申请加价',
                    'description'=>'以成交测试师傅调用正式 changes.create；创建后客户先接受/拒绝，不直接生成平台待办。',
                    'worker_name'=>(string)$worker['display_name'],'default_added_amount_cents'=>10000,
                ];
            }
            if($items)return['items'=>$items,'message'=>'可继续正常履约，也可从当前成交状态进入真实加价协商分支；平台介入必须经过拒绝或超时条件。'];
        }
        return$inner;
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!in_array($actionKey,['worker_change_request','customer_reject_change','customer_escalate_change'],true)){
            return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        }
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('真实协商测试动作仅允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);
        if(!$this->supportsPositiveScenario((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放真实协商推进。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
        $instance=(array)$context['instance'];$order=(array)$context['order'];
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效，请刷新后重试。','TEST_ACTION_REQUEST_KEY_INVALID',422);

        $normalized=$this->normalizeInput($actionKey,$input);
        $digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id']]);
        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$instance['id'],'idem'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已经用于不同参数，请刷新后重试。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??''))];
            if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已经失败，请刷新后重新发起。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409,['run_public_id'=>$existing['public_id']]);
            throw new TestCenterException('相同测试动作正在执行，请刷新后查看结果。','TEST_ACTION_RUNNING',409,['run_public_id'=>$existing['public_id']]);
        }

        $this->assertState($actionKey,$order,(int)$instance['id']);
        $actorRole=$actionKey==='worker_change_request'?'worker':'user';
        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,:action_key,:actor_role,'real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'actor_role'=>$actorRole,'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId]
        );

        try{
            if($actionKey==='worker_change_request')$business=$this->workerCreateChange((int)$instance['id'],$order,$normalized);
            elseif($actionKey==='customer_reject_change')$business=$this->customerRejectChange($order,$normalized);
            else$business=$this->customerEscalateChange($order,$normalized);
            $after=$this->summary((int)$order['id']);$duration=(int)round((microtime(true)-$started)*1000);
            $this->db->execute("UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]);
            $this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);
            return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after];
        }catch(TestCenterException $exception){
            $this->failRun($runId,$started,$exception->businessCode(),$exception->getMessage(),(int)$order['id']);
            throw$exception;
        }catch(OrderOperationException $exception){
            $this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);
            $status=(int)$exception->getCode();if($status<400||$status>599)$status=409;
            throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());
        }catch(Throwable $exception){
            $this->failRun($runId,$started,'TEST_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);
            throw new TestCenterException('真实协商测试动作执行失败，请查看测试执行记录和运行日志。','TEST_ACTION_FAILED',500,['run_public_id'=>$runPublic]);
        }
    }

    private function workerCreateChange(int $instanceId,array $order,array $input): array
    {
        return$this->orders->fulfillment()->execute('changes.create',$this->workerActor($instanceId,$order),[
            'order_id'=>(string)$order['public_no'],'added_amount_cents'=>(int)$input['added_amount_cents'],'reason'=>(string)$input['reason'],
        ]);
    }

    private function customerRejectChange(array $order,array $input): array
    {
        $change=$this->latestChange((int)$order['id']);
        if(!$change)throw new TestCenterException('加价申请已经不存在。','TEST_CHANGE_NOT_FOUND',409);
        return$this->orders->fulfillment()->execute('changes.decide',$this->userActor($order),[
            'change_id'=>(string)$change['public_id'],'decision'=>'rejected','note'=>(string)$input['note'],
        ]);
    }

    private function customerEscalateChange(array $order,array $input): array
    {
        $change=$this->latestChange((int)$order['id']);
        if(!$change)throw new TestCenterException('加价事项已经不存在。','TEST_CHANGE_NOT_FOUND',409);
        return$this->orders->negotiation()->execute('exceptions.escalate',$this->userActor($order),[
            'order_id'=>(string)$order['public_no'],'kind'=>'change','reference'=>(string)$change['public_id'],'note'=>(string)$input['note'],
        ]);
    }

    private function userActor(array $order): array
    {
        return['user_id'=>(int)$order['user_id'],'real_user_id'=>(int)$order['user_id'],'worker_id'=>null,'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false];
    }

    private function workerActor(int $instanceId,array $order): array
    {
        $worker=$this->assignedParticipant($instanceId,$order,true);
        return[
            'user_id'=>(int)$worker['user_id'],'real_user_id'=>(int)$worker['user_id'],'user_public_id'=>(string)$worker['user_public_id'],
            'worker_id'=>(int)$worker['worker_id'],'worker_public_id'=>(string)$worker['worker_public_id'],'worker_name'=>(string)$worker['display_name'],
            'worker_status'=>(string)$worker['verification_status'],'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false,
        ];
    }

    private function normalizeInput(string $actionKey,array $input): array
    {
        if($actionKey==='worker_change_request'){
            $cents=$this->yuanToCents((string)($input['added_amount_yuan']??'100.00'));
            $reason=mb_substr(trim((string)($input['reason']??'测试中心：现场新增施工内容，需要增加施工费用')),0,500);
            if(mb_strlen($reason)<4)throw new TestCenterException('请填写至少 4 个字的加价原因。','TEST_CHANGE_REASON_REQUIRED',422);
            return['added_amount_cents'=>$cents,'reason'=>$reason];
        }
        if($actionKey==='customer_reject_change'){
            $note=mb_substr(trim((string)($input['note']??'测试中心：客户拒绝本次加价，继续双方协商')),0,500);
            if(mb_strlen($note)<4)throw new TestCenterException('请填写拒绝说明。','TEST_CHANGE_REJECT_NOTE_REQUIRED',422);
            return['note'=>$note];
        }
        $note=mb_substr(trim((string)($input['note']??'测试中心：加价协商已被拒绝，现申请平台介入处理')),0,500);
        if(mb_strlen($note)<4)throw new TestCenterException('请说明协商未解决的情况。','TEST_CHANGE_ESCALATE_NOTE_REQUIRED',422);
        return['note'=>$note];
    }

    private function yuanToCents(string $value): int
    {
        $value=trim($value);
        if(preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/D',$value,$match)!==1)throw new TestCenterException('加价金额格式不正确。','TEST_CHANGE_AMOUNT_INVALID',422);
        $cents=((int)$match[1])*100+(int)str_pad((string)($match[2]??''),2,'0');
        if($cents<1||$cents>100000000)throw new TestCenterException('加价金额必须在 0.01 元到 100 万元之间。','TEST_CHANGE_AMOUNT_INVALID',422);
        return$cents;
    }

    private function assertState(string $actionKey,array $order,int $instanceId): void
    {
        $change=$this->latestChange((int)$order['id']);$dispute=$this->activeFeeDispute((int)$order['id']);
        if($actionKey==='worker_change_request')$ok=$this->canCreateChange($order,$instanceId);
        elseif($actionKey==='customer_reject_change')$ok=$change&&(string)$change['status']==='pending'&&!$dispute&&(string)$order['lifecycle_status']==='active';
        else$ok=$change&&(string)$change['status']==='rejected'&&!$dispute&&(string)$order['lifecycle_status']==='active';
        if(!$ok)throw new TestCenterException('当前协商状态已经变化，不能执行该测试动作。','TEST_ACTION_STATE_CHANGED',409);
    }

    private function canCreateChange(array $order,int $instanceId): bool
    {
        return$this->dealtState($order)&&(string)($order['lifecycle_status']??'')==='active'
            &&!in_array((string)($order['fulfillment_status']??''),['submitted','accepted'],true)
            &&$this->latestChange((int)$order['id'])===null&&$this->activeFeeDispute((int)$order['id'])===null
            &&$this->assignedParticipant($instanceId,$order,false)!==null;
    }

    private function dealtState(array $order): bool
    {
        return(string)($order['order_source_type']??'')==='consumer'&&(string)($order['workflow_profile']??'construction')==='construction'
            &&(string)($order['dispatch_mode']??'multi_quote')==='multi_quote'&&(string)($order['quote_status']??'')==='selected'
            &&(string)($order['assignment_status']??'')==='accepted'&&(int)($order['selected_quote_id']??0)>0
            &&(int)($order['assigned_worker_id']??0)>0&&(int)($order['current_assignment_id']??0)>0;
    }

    private function latestChange(int $orderId): ?array
    {
        return$this->db->fetch('SELECT * FROM change_requests WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
    }

    private function activeFeeDispute(int $orderId): ?array
    {
        return$this->db->fetch("SELECT * FROM disputes WHERE order_id=:id AND dispute_type='fee_negotiation' AND status IN ('negotiating','open','pending','processing') ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
    }

    private function assignedParticipant(int $instanceId,array $order,bool $required): ?array
    {
        $workerId=(int)($order['assigned_worker_id']??0);
        $row=$workerId>0?$this->db->fetch(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,tp.expected_status,u.public_id user_public_id,w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode
             FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND tp.worker_id=:worker LIMIT 1",
            ['instance'=>$instanceId,'worker'=>$workerId]
        ):null;
        $valid=$row&&(string)$row['expected_status']==='approved'&&(string)$row['verification_status']==='approved'&&(string)$row['cloud_record_mode']==='demo_local';
        if(!$valid){if($required)throw new TestCenterException('成交师傅不是当前实例登记的已审核 testing 参与者。','TEST_ACTION_ASSIGNED_WORKER_INVALID',409);return null;}
        return$row;
    }

    private function supportsPositiveScenario(string $key): bool
    {
        return in_array($key,[
            'ordinary.construction.multi_quote.waiting.zero_quotes','ordinary.construction.multi_quote.waiting.one_quote','ordinary.construction.multi_quote.waiting.three_quotes',
        ],true);
    }

    private function context(string $publicId,bool $mustHaveOrder): array
    {
        $publicId=trim($publicId);if($publicId==='')throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_REQUIRED',422);
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $order=null;if(!empty($instance['order_id']))$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>(int)$instance['order_id']]);
        if($mustHaveOrder&&!$order)throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        if($order&&((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可执行真实协商测试的数据域。','TEST_ACTION_ORDER_BOUNDARY',409);
        return['instance'=>$instance,'scenario_key'=>(string)$instance['scenario_key'],'order'=>$order];
    }

    private function summary(int $orderId): array
    {
        $order=$this->db->fetch('SELECT public_no,publish_status,quote_status,assignment_status,fulfillment_status,lifecycle_status,dispute_status,selected_quote_id,assigned_worker_id,current_assignment_id,version FROM orders WHERE id=:id',['id'=>$orderId]);
        $change=$this->latestChange($orderId);$dispute=$this->activeFeeDispute($orderId);
        return[
            'order'=>$order?:[],'latest_change'=>$change?:[],
            'change_confirmations'=>$change?(int)$this->db->fetchValue('SELECT COUNT(*) FROM change_confirmations WHERE change_request_id=:id',['id'=>(int)$change['id']]):0,
            'active_fee_disputes'=>$dispute?1:0,'latest_fee_dispute'=>$dispute?:[],
        ];
    }

    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void
    {
        $this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",[
            'code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after_json'=>$this->json(['facts'=>$this->summary($orderId)]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId,
        ]);
    }

    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @return array<string,mixed> */ private function decode(string $value): array{$decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[];}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
}

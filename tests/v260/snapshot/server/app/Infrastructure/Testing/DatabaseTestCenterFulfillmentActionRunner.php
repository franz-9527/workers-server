<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 第二段：在报价/成交 runner 外再接预约、完工和客户验收。
 *
 * 这里不直接改 orders 状态；所有业务推进仍调用生产 OrderAppointment / OrderFulfillment。
 * 完工照片与收款凭证只补 testing 私有夹具，用来满足正式服务前置条件，不冒充上传 API 已被验证。
 */
final class DatabaseTestCenterFulfillmentActionRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;
    private TestingMediaFixtureFactory $media;

    public function __construct(Database $db, TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->orders=new OrderApplicationContainer($db);
        $this->media=new TestingMediaFixtureFactory($db);
    }

    public function available(string $instancePublicId): array
    {
        $inner=$this->inner->available($instancePublicId);
        if(!empty($inner['items']))return$inner;

        $context=$this->context($instancePublicId,false);
        if(!$this->supportsPositiveScenario((string)$context['scenario_key']))return$inner;
        $order=$context['order'];
        if(!$order)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $instance=(array)$context['instance'];
        $worker=$this->assignedParticipant((int)$instance['id'],$order,false);
        $items=[];

        if($this->canAppointment($order,(int)$instance['id'])){
            if($worker){
                $timezone=(string)\app_config('app.timezone','Asia/Shanghai');
                try{$defaultAt=(new DateTimeImmutable('tomorrow 10:00',new DateTimeZone($timezone)))->format('Y-m-d\TH:i');}
                catch(Throwable $exception){$defaultAt=date('Y-m-d\TH:i',time()+86400);}
                $items['worker_appointment']=[
                    'key'=>'worker_appointment','label'=>'真实师傅预约施工',
                    'description'=>'调用正式 OrderAppointment；记录施工预约并推进 fulfillment_status=scheduled。',
                    'worker_name'=>(string)$worker['display_name'],'default_appointment_at'=>$defaultAt,
                ];
            }
        }elseif($this->canComplete($order,(int)$instance['id'])){
            if($worker){
                $items['worker_complete']=[
                    'key'=>'worker_complete','label'=>'真实师傅提交完工',
                    'description'=>'先补 1 张 testing 私有完工图片夹具，再调用正式 fulfillment.complete；会生成完工提交、自动验收期限和预计服务费。',
                    'worker_name'=>(string)$worker['display_name'],
                ];
            }
        }elseif($this->canAccept($order,(int)$instance['id'])){
            $items['customer_accept_completion']=[
                'key'=>'customer_accept_completion','label'=>'真实客户验收',
                'description'=>'先补 1 张 testing 私有收款凭证夹具，再以订单客户调用正式 fulfillment.accept；会正式化服务费并完成订单。',
            ];
        }

        if($items)return['items'=>$items,'message'=>'以下动作继续使用正式履约服务推进；需要回到前一阶段时请重建新实例，不倒退当前订单。'];
        if((string)($order['lifecycle_status']??'')==='completed'&&(string)($order['fulfillment_status']??'')==='accepted'){
            return['items'=>[],'message'=>'当前测试订单已完成“成交 → 预约 → 完工 → 客户验收”正向闭环。'];
        }
        return['items'=>[],'message'=>'当前状态没有已适配的真实履约动作；退款、售后和结算后续分阶段接入。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!in_array($actionKey,['worker_appointment','worker_complete','customer_accept_completion'],true)){
            return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        }
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('真实履约测试动作仅允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);
        if(!$this->supportsPositiveScenario((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放真实履约推进。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
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
        $actorRole=$actionKey==='customer_accept_completion'?'user':'worker';
        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,:action_key,:actor_role,'real_service',:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'actor_role'=>$actorRole,
                'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId,
            ]
        );

        try{
            if($actionKey==='worker_appointment')$business=$this->workerAppointment((int)$instance['id'],$order,$normalized);
            elseif($actionKey==='worker_complete')$business=$this->workerComplete((int)$instance['id'],$order,$normalized,$adminId);
            else$business=$this->customerAccept((int)$instance['id'],$order,$adminId);
            $after=$this->summary((int)$order['id']);$duration=(int)round((microtime(true)-$started)*1000);
            $this->db->execute(
                "UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",
                ['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]
            );
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
            throw new TestCenterException('真实履约测试动作执行失败，请查看测试执行记录和运行日志。','TEST_ACTION_FAILED',500,['run_public_id'=>$runPublic]);
        }
    }

    private function workerAppointment(int $instanceId,array $order,array $input): array
    {
        $actor=$this->workerActor($instanceId,$order);
        return$this->orders->appointment()->save($actor,[
            'order_id'=>(string)$order['public_no'],'context_type'=>'service','appointment_at'=>(string)$input['appointment_at'],
            'wait_customer_notice'=>false,'note'=>(string)$input['note'],
        ]);
    }

    private function workerComplete(int $instanceId,array $order,array $input,int $adminId): array
    {
        $fixture=$this->media->ensureEvidence($instanceId,(int)$order['id'],'completion',$adminId);
        $actor=$this->workerActor($instanceId,$order);
        $result=$this->orders->fulfillment()->execute('fulfillment.complete',$actor,[
            'order_id'=>(string)$order['public_no'],'description'=>(string)$input['description'],'warranty_months'=>0,'warranty_note'=>'',
        ]);
        $result['data']['test_media_fixture']=$fixture;
        return$result;
    }

    private function customerAccept(int $instanceId,array $order,int $adminId): array
    {
        $fixture=$this->media->ensureEvidence($instanceId,(int)$order['id'],'collection_receipt',$adminId);
        $actor=['user_id'=>(int)$order['user_id'],'real_user_id'=>(int)$order['user_id'],'worker_id'=>null,'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false];
        $result=$this->orders->fulfillment()->execute('fulfillment.accept',$actor,['order_id'=>(string)$order['public_no']]);
        $result['data']['test_media_fixture']=$fixture;
        return$result;
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

    private function assignedParticipant(int $instanceId,array $order,bool $required): ?array
    {
        $workerId=(int)($order['assigned_worker_id']??0);
        $row=$workerId>0?$this->db->fetch(
            "SELECT tp.slot_key,tp.user_id,tp.worker_id,tp.expected_status,u.public_id user_public_id,
                    w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode
             FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id
             WHERE tp.instance_id=:instance AND tp.worker_id=:worker LIMIT 1",
            ['instance'=>$instanceId,'worker'=>$workerId]
        ):null;
        $valid=$row&&(string)$row['expected_status']==='approved'&&(string)$row['verification_status']==='approved'&&(string)$row['cloud_record_mode']==='demo_local';
        if(!$valid){if($required)throw new TestCenterException('成交师傅不是当前实例登记的已审核 testing 参与者。','TEST_ACTION_ASSIGNED_WORKER_INVALID',409);return null;}
        return$row;
    }

    private function normalizeInput(string $actionKey,array $input): array
    {
        if($actionKey==='worker_appointment'){
            $at=trim((string)($input['appointment_at']??''));
            if($at===''||strlen($at)>40)throw new TestCenterException('请选择施工预约时间。','TEST_ACTION_APPOINTMENT_REQUIRED',422);
            $note=mb_substr(trim((string)($input['note']??'测试中心 M2 正向施工预约')),0,300);
            return['appointment_at'=>$at,'note'=>$note];
        }
        if($actionKey==='worker_complete')return['description'=>mb_substr(trim((string)($input['description']??'测试中心 M2 正向完工提交')),0,500)];
        return[];
    }

    private function assertState(string $actionKey,array $order,int $instanceId): void
    {
        $ok=$actionKey==='worker_appointment'?$this->canAppointment($order,$instanceId):($actionKey==='worker_complete'?$this->canComplete($order,$instanceId):$this->canAccept($order,$instanceId));
        if(!$ok)throw new TestCenterException('当前订单状态已经变化，不能执行该履约测试动作。','TEST_ACTION_STATE_CHANGED',409);
    }

    private function dealtState(array $order): bool
    {
        return(string)($order['order_source_type']??'')==='consumer'&&(string)($order['workflow_profile']??'construction')==='construction'
            &&(string)($order['dispatch_mode']??'multi_quote')==='multi_quote'&&(string)($order['quote_status']??'')==='selected'
            &&(string)($order['assignment_status']??'')==='accepted'&&(int)($order['selected_quote_id']??0)>0
            &&(int)($order['assigned_worker_id']??0)>0&&(int)($order['current_assignment_id']??0)>0;
    }

    private function canAppointment(array $order,int $instanceId): bool
    {
        return$this->dealtState($order)&&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'not_started')==='not_started'
            &&$this->serviceAppointmentCount((int)$order['id'])===0&&$this->assignedParticipant($instanceId,$order,false)!==null;
    }

    private function canComplete(array $order,int $instanceId): bool
    {
        return$this->dealtState($order)&&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'')==='scheduled'&&empty($order['completion_submitted_at'])
            &&$this->serviceAppointmentCount((int)$order['id'])>0&&$this->assignedParticipant($instanceId,$order,false)!==null;
    }

    private function canAccept(array $order,int $instanceId): bool
    {
        return$this->dealtState($order)&&(string)($order['lifecycle_status']??'')==='active'
            &&(string)($order['fulfillment_status']??'')==='submitted'&&!empty($order['completion_submitted_at'])
            &&(int)$this->db->fetchValue("SELECT COUNT(*) FROM completion_submissions WHERE order_id=:id AND status='submitted'",['id'=>(int)$order['id']])===1
            &&$this->assignedParticipant($instanceId,$order,false)!==null;
    }

    private function serviceAppointmentCount(int $orderId): int
    {
        return(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='service'",['id'=>$orderId]);
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
        if($order&&((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可执行真实履约测试的数据域。','TEST_ACTION_ORDER_BOUNDARY',409);
        return['instance'=>$instance,'scenario_key'=>(string)$instance['scenario_key'],'order'=>$order];
    }

    private function summary(int $orderId): array
    {
        $order=$this->db->fetch('SELECT public_no,quote_status,assignment_status,fulfillment_status,lifecycle_status,selected_quote_id,assigned_worker_id,current_assignment_id,completion_submitted_at,accepted_at,version FROM orders WHERE id=:id',['id'=>$orderId]);
        return[
            'order'=>$order?:[],
            'appointments'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='service'",['id'=>$orderId]),
            'completion_submissions'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM completion_submissions WHERE order_id=:id',['id'=>$orderId]),
            'pending_auto_accept'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=:id AND deadline_type='auto_accept_completion' AND status='pending'",['id'=>$orderId]),
            'pending_auto_review'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=:id AND deadline_type IN ('auto_review','auto_review_order') AND status='pending'",['id'=>$orderId]),
            'commission_receivables'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM commission_receivables WHERE order_id=:id',['id'=>$orderId]),
            'commission_ledger'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger l JOIN commission_receivables c ON c.id=l.business_id WHERE l.business_type='commission' AND c.order_id=:id",['id'=>$orderId]),
        ];
    }

    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void
    {
        $this->db->execute(
            "UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",
            ['code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after_json'=>$this->json(['facts'=>$this->summary($orderId)]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]
        );
    }

    private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    private function decode(string $value): array{$decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[];}
    private function digest($value): string{return hash('sha256',$this->json($value));}
}

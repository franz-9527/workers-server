<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\RepairInterventionPolicy;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Foundation\OrderFulfillmentAuxiliaryComposition;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * M2 第五段：保修维修售后闭环。
 *
 * 真实业务动作：客户提交维修 -> 师傅接受/拒绝 -> 维修预约 -> 完成维修；
 * 拒绝或 48 小时超时后，客户可走专用正式维修介入入口。
 *
 * prepare_repair_warranty / repair_advance_timeout 是 testing fixture，不冒充真实业务动作。
 */
final class DatabaseTestCenterRepairActionRunner implements TestCenterActionRunner
{
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;
    private OrderFulfillmentAuxiliary $auxiliary;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;
        $this->inner=$inner;
        $this->orders=new OrderApplicationContainer($db);
        $this->auxiliary=OrderFulfillmentAuxiliaryComposition::build($db);
    }

    public function available(string $instancePublicId): array
    {
        $inner=$this->inner->available($instancePublicId);
        $context=$this->context($instancePublicId,false);
        if(!$this->supports((string)$context['scenario_key']))return$inner;
        $order=$context['order'];
        if(!$order)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $instance=(array)$context['instance'];

        if(!$this->completedState($order))return$inner;
        $repair=$this->latestRepair((int)$order['id']);
        if(!$repair){
            if(!$this->warrantyReady($order)){
                return['items'=>[
                    'prepare_repair_warranty'=>[
                        'key'=>'prepare_repair_warranty','label'=>'补齐售后保修测试条件',
                        'description'=>'testing fixture：为已验收测试订单补齐 12 个月保修快照，使真实客户端售后入口满足业务前置条件；不代表正式完工填写保修流程已被本动作验证。',
                    ],
                ],'message'=>'订单已验收，但当前测试完工夹具未设置保修期。先补齐售后测试条件，再执行真实维修申请。'];
            }
            return['items'=>[
                'customer_repair_request'=>[
                    'key'=>'customer_repair_request','label'=>'真实客户提交维修申请',
                    'description'=>'调用正式 after_sales.repair.create；维修仍归原成交师傅处理，不自动进入平台介入。',
                ],
            ],'message'=>'订单已完成且保修条件有效，可由测试客户进入真实售后维修流程。'];
        }

        $status=(string)$repair['status'];$resolution=trim((string)($repair['resolution']??''));
        if(in_array($status,['resolved','closed','rejected'],true)){
            return['items'=>[],'message'=>'当前维修售后已经结束。需要测试其它分支时请复制或重建实例。'];
        }
        if(strpos($resolution,'用户申请平台介入')===0||strpos($resolution,'平台主动介入')===0){
            return['items'=>[],'message'=>'维修已进入平台介入。本阶段停在管理员裁决前，不模拟平台替双方作出维修结果。'];
        }
        if(strpos($resolution,'师傅拒绝维修')===0){
            return['items'=>[
                'customer_escalate_repair'=>[
                    'key'=>'customer_escalate_repair','label'=>'真实客户申请维修平台介入',
                    'after_sale_id'=>(string)$repair['public_no'],
                    'description'=>'师傅已明确拒绝；调用正式维修专用介入服务，RepairInterventionPolicy 会再次校验资格。',
                ],
            ],'message'=>'师傅已拒绝维修，客户现在可以按正式规则申请平台介入。'];
        }
        if(strpos($resolution,'师傅接受维修')===0){
            $appointments=$this->repairAppointmentCount((int)$order['id'],(int)$repair['id']);
            if($appointments===0){
                $timezone=(string)\app_config('app.timezone','Asia/Shanghai');
                try{$defaultAt=(new DateTimeImmutable('tomorrow 10:00',new DateTimeZone($timezone)))->format('Y-m-d\TH:i');}
                catch(Throwable $exception){$defaultAt=date('Y-m-d\TH:i',time()+86400);}
                return['items'=>[
                    'worker_repair_appointment'=>[
                        'key'=>'worker_repair_appointment','label'=>'真实师傅预约维修','after_sale_id'=>(string)$repair['public_no'],
                        'default_appointment_at'=>$defaultAt,
                        'description'=>'复用正式 OrderAppointment，context_type=repair；主订单保持已完成，只新增维修预约事实。',
                    ],
                ],'message'=>'师傅已接受维修，下一步登记维修预约。'];
            }
            return['items'=>[
                'worker_complete_repair'=>[
                    'key'=>'worker_complete_repair','label'=>'真实师傅完成维修','after_sale_id'=>(string)$repair['public_no'],
                    'description'=>'调用正式 after_sales.repair.respond(action=complete)，关闭本次维修售后；不会改写原订单验收和结算事实。',
                ],
            ],'message'=>'维修预约已登记，可提交真实维修完成结果。'];
        }

        $eligibility=RepairInterventionPolicy::evaluate($status,$resolution,$repair['opened_at']??null,'UTC');
        $items=[
            'worker_accept_repair'=>[
                'key'=>'worker_accept_repair','label'=>'真实师傅接受维修','after_sale_id'=>(string)$repair['public_no'],
                'description'=>'调用正式 after_sales.repair.respond；接受后仍需登记维修预约或完成结果。',
            ],
            'worker_reject_repair'=>[
                'key'=>'worker_reject_repair','label'=>'真实师傅拒绝维修','after_sale_id'=>(string)$repair['public_no'],
                'description'=>'明确拒绝后维修仍保持处理中，由客户决定是否申请平台介入。',
            ],
        ];
        if(!empty($eligibility['eligible'])&&(string)$eligibility['reason']==='response_timeout'){
            $items['customer_escalate_repair']=[
                'key'=>'customer_escalate_repair','label'=>'真实客户按超时申请平台介入','after_sale_id'=>(string)$repair['public_no'],
                'description'=>'维修申请已超过 48 小时未明确响应，调用正式维修专用介入服务。',
            ];
        }else{
            $items['repair_advance_timeout']=[
                'key'=>'repair_advance_timeout','label'=>'推进到维修响应超时',
                'after_sale_id'=>(string)$repair['public_no'],
                'description'=>'testing time fixture：仅把本测试维修 opened_at 推到 49 小时前，用于验证正式 48 小时介入规则；不修改系统时钟。',
            ];
        }
        return['items'=>$items,'message'=>'维修申请先由原成交师傅处理；师傅拒绝或满 48 小时未响应后才允许平台介入。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        $allowed=['prepare_repair_warranty','customer_repair_request','worker_accept_repair','worker_reject_repair','worker_repair_appointment','worker_complete_repair','repair_advance_timeout','customer_escalate_repair'];
        if(!in_array($actionKey,$allowed,true))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('维修售后测试动作仅允许 testing 环境。','TEST_REPAIR_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);
        if(!$this->supports((string)$context['scenario_key']))throw new TestCenterException('当前场景未开放维修售后测试。','TEST_REPAIR_SCENARIO_UNSUPPORTED',409);
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

        $this->assertState($actionKey,(int)$instance['id'],$order);
        $mode=$actionKey==='prepare_repair_warranty'?'fixture':($actionKey==='repair_advance_timeout'?'time_fixture':'real_service');
        $actorRole=strpos($actionKey,'worker_')===0?'worker':($mode==='real_service'?'user':'system');
        $before=$this->summary((int)$order['id']);$runPublic=\public_id();$started=microtime(true);
        $runId=$this->db->insert(
            "INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at)
             VALUES(:public_id,:instance,:action_key,:actor_role,:mode,:idem,:digest,:request_id,:before_json,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id'=>$runPublic,'instance'=>(int)$instance['id'],'action_key'=>$actionKey,'actor_role'=>$actorRole,'mode'=>$mode,'idem'=>$requestKey,'digest'=>$digest,'request_id'=>\request_id(),'before_json'=>$this->json($before),'admin'=>$adminId]
        );
        try{
            $business=$this->execute($actionKey,(int)$instance['id'],$order,$normalized);
            $after=$this->summary((int)$order['id']);$duration=(int)round((microtime(true)-$started)*1000);
            $this->db->execute("UPDATE test_runs SET result='passed',after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['after_json'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>$duration,'id'=>$runId]);
            $this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);
            return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after,'operation_mode'=>$mode];
        }catch(TestCenterException $exception){$this->failRun($runId,$started,$exception->businessCode(),$exception->getMessage(),(int)$order['id']);throw$exception;}
        catch(OrderOperationException $exception){
            $this->failRun($runId,$started,$exception->apiCode(),$exception->getMessage(),(int)$order['id']);
            $status=(int)$exception->getCode();if($status<400||$status>599)$status=409;
            throw new TestCenterException($exception->getMessage(),$exception->apiCode(),$status,$exception->data());
        }catch(Throwable $exception){
            $this->failRun($runId,$started,'TEST_REPAIR_ACTION_FAILED',$exception->getMessage(),(int)$order['id']);
            throw new TestCenterException('维修售后测试动作执行失败，请查看测试执行记录。','TEST_REPAIR_ACTION_FAILED',500,['run_public_id'=>$runPublic]);
        }
    }

    /** @return array<string,mixed> */
    private function execute(string $actionKey,int $instanceId,array $order,array $input): array
    {
        if($actionKey==='prepare_repair_warranty'){
            $changed=$this->db->execute("UPDATE orders SET warranty_months=12,warranty_expires_at=DATE_ADD(COALESCE(accepted_at,UTC_TIMESTAMP()),INTERVAL 12 MONTH),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND is_demo=1",['id'=>(int)$order['id']]);
            if($changed!==1)throw new TestCenterException('保修测试条件写入失败，请刷新订单状态。','TEST_REPAIR_WARRANTY_FIXTURE_FAILED',409);
            return['fixture'=>'repair_warranty','warranty_months'=>12];
        }
        if($actionKey==='customer_repair_request'){
            return$this->auxiliary->execute('after_sales.repair.create',$this->userActor($order),[
                'order_id'=>(string)$order['public_no'],'description'=>(string)$input['note'],'evidence_ids'=>[],'non_human_confirmed'=>true,
            ]);
        }
        $repair=$this->latestRepair((int)$order['id']);
        if(!$repair)throw new TestCenterException('维修申请不存在。','TEST_REPAIR_NOT_FOUND',409);
        if($actionKey==='worker_accept_repair'||$actionKey==='worker_reject_repair'||$actionKey==='worker_complete_repair'){
            return$this->orders->negotiation()->execute('after_sales.repair.respond',$this->workerActor($instanceId,$order),[
                'order_id'=>(string)$order['public_no'],'after_sale_id'=>(string)$repair['public_no'],
                'action'=>$actionKey==='worker_accept_repair'?'accept':($actionKey==='worker_reject_repair'?'reject':'complete'),'note'=>(string)$input['note'],
            ]);
        }
        if($actionKey==='worker_repair_appointment'){
            return$this->orders->appointment()->save($this->workerActor($instanceId,$order),[
                'order_id'=>(string)$order['public_no'],'context_type'=>'repair','after_sale_id'=>(string)$repair['public_no'],
                'appointment_at'=>(string)$input['appointment_at'],'wait_customer_notice'=>false,'note'=>(string)$input['note'],
            ]);
        }
        if($actionKey==='repair_advance_timeout'){
            $changed=$this->db->execute("UPDATE after_sales SET opened_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 49 HOUR),updated_at=UTC_TIMESTAMP() WHERE id=:id AND type='warranty_repair' AND status IN ('open','processing') AND COALESCE(resolution,'')=''",['id'=>(int)$repair['id']]);
            if($changed!==1)throw new TestCenterException('维修超时测试条件已经变化，请刷新。','TEST_REPAIR_TIMEOUT_FIXTURE_CHANGED',409);
            return['fixture'=>'repair_timeout','hours'=>49,'after_sale_id'=>(string)$repair['public_no']];
        }
        return$this->orders->repairIntervention()->request($this->userActor($order),[
            'order_id'=>(string)$order['public_no'],'after_sale_id'=>(string)$repair['public_no'],'note'=>(string)$input['note'],
        ]);
    }

    /** @return array<string,mixed> */
    private function normalizeInput(string $actionKey,array $input): array
    {
        if($actionKey==='prepare_repair_warranty'||$actionKey==='repair_advance_timeout')return[];
        if($actionKey==='worker_repair_appointment'){
            $at=trim((string)($input['appointment_at']??''));if($at===''||strlen($at)>40)throw new TestCenterException('请选择维修预约时间。','TEST_REPAIR_APPOINTMENT_REQUIRED',422);
            $note=mb_substr(trim((string)($input['note']??'测试中心：师傅已与客户约定维修时间')),0,300);
            return['appointment_at'=>$at,'note'=>$note];
        }
        $defaults=[
            'customer_repair_request'=>'测试中心：验收后发现墙面局部翘边，需要原成交师傅返修处理',
            'worker_accept_repair'=>'测试中心：师傅接受本次保修维修并联系客户预约',
            'worker_reject_repair'=>'测试中心：师傅认为当前问题不属于本次施工责任，拒绝维修',
            'worker_complete_repair'=>'测试中心：师傅已按预约完成本次保修维修',
            'customer_escalate_repair'=>'测试中心：维修协商未解决，申请平台介入处理',
        ];
        $note=mb_substr(trim((string)($input['note']??($defaults[$actionKey]??'测试中心维修流程'))),0,1000);
        $min=in_array($actionKey,['worker_reject_repair','customer_escalate_repair'],true)?4:1;
        if(mb_strlen($note)<$min)throw new TestCenterException('维修说明内容过短。','TEST_REPAIR_NOTE_REQUIRED',422);
        return['note'=>$note];
    }

    private function assertState(string $actionKey,int $instanceId,array $order): void
    {
        if(!$this->completedState($order))throw new TestCenterException('订单尚未完成验收，不能进入维修售后。','TEST_REPAIR_ORDER_NOT_COMPLETED',409);
        $repair=$this->latestRepair((int)$order['id']);
        if($actionKey==='prepare_repair_warranty')$ok=$repair===null&&!$this->warrantyReady($order);
        elseif($actionKey==='customer_repair_request')$ok=$repair===null&&$this->warrantyReady($order);
        else{
            $resolution=$repair?trim((string)($repair['resolution']??'')):'';$active=$repair&&in_array((string)$repair['status'],['open','processing'],true);
            if($actionKey==='worker_accept_repair'||$actionKey==='worker_reject_repair')$ok=$active&&$resolution===''&&$this->assignedParticipant($instanceId,$order,false)!==null;
            elseif($actionKey==='worker_repair_appointment')$ok=$active&&strpos($resolution,'师傅接受维修')===0&&$this->repairAppointmentCount((int)$order['id'],(int)$repair['id'])===0&&$this->assignedParticipant($instanceId,$order,false)!==null;
            elseif($actionKey==='worker_complete_repair')$ok=$active&&strpos($resolution,'师傅接受维修')===0&&$this->repairAppointmentCount((int)$order['id'],(int)$repair['id'])>0&&$this->assignedParticipant($instanceId,$order,false)!==null;
            elseif($actionKey==='repair_advance_timeout'){
                $eligibility=$active?RepairInterventionPolicy::evaluate((string)$repair['status'],$resolution,$repair['opened_at']??null,'UTC'):['eligible'=>false];
                $ok=$active&&$resolution===''&&empty($eligibility['eligible']);
            }else{
                $eligibility=$active?RepairInterventionPolicy::evaluate((string)$repair['status'],$resolution,$repair['opened_at']??null,'UTC'):['eligible'=>false];
                $ok=$active&&!empty($eligibility['eligible']);
            }
        }
        if(!$ok)throw new TestCenterException('当前维修售后状态已经变化，不能执行该测试动作。','TEST_REPAIR_ACTION_STATE_CHANGED',409);
    }

    private function completedState(array $order): bool
    {
        return(string)($order['order_source_type']??'')==='consumer'&&(string)($order['workflow_profile']??'construction')==='construction'
            &&(string)($order['dispatch_mode']??'multi_quote')==='multi_quote'&&(string)($order['fulfillment_status']??'')==='accepted'
            &&(string)($order['lifecycle_status']??'')==='completed'&&!empty($order['accepted_at'])&&(int)($order['assigned_worker_id']??0)>0;
    }

    private function warrantyReady(array $order): bool
    {
        if((int)($order['warranty_months']??0)<1)return false;
        $expires=trim((string)($order['warranty_expires_at']??''));if($expires==='')return false;
        $time=strtotime($expires.' UTC');return$time!==false&&$time>time();
    }

    /** @return array<string,mixed>|null */
    private function latestRepair(int $orderId): ?array
    {
        $row=$this->db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        return$row?:null;
    }

    private function repairAppointmentCount(int $orderId,int $afterSaleId): int
    {
        return(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:order_id AND context_type='repair' AND after_sale_id=:after_sale",['order_id'=>$orderId,'after_sale'=>$afterSaleId]);
    }

    /** @return array<string,mixed> */
    private function userActor(array $order): array
    {
        return['user_id'=>(int)$order['user_id'],'real_user_id'=>(int)$order['user_id'],'worker_id'=>null,'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false];
    }

    /** @return array<string,mixed> */
    private function workerActor(int $instanceId,array $order): array
    {
        $worker=$this->assignedParticipant($instanceId,$order,true);
        return[
            'user_id'=>(int)$worker['user_id'],'real_user_id'=>(int)$worker['user_id'],'user_public_id'=>(string)$worker['user_public_id'],
            'worker_id'=>(int)$worker['worker_id'],'worker_public_id'=>(string)$worker['worker_public_id'],'worker_name'=>(string)$worker['display_name'],
            'worker_status'=>(string)$worker['verification_status'],'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false,
        ];
    }

    /** @return array<string,mixed>|null */
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
        if(!$valid){if($required)throw new TestCenterException('成交师傅不是当前实例登记的已审核 testing 参与者。','TEST_REPAIR_WORKER_INVALID',409);return null;}
        return$row;
    }

    /** @return array<string,mixed> */
    private function context(string $publicId,bool $mustHaveOrder): array
    {
        $publicId=trim($publicId);if($publicId==='')throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_REQUIRED',422);
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
        if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $order=null;if(!empty($instance['order_id']))$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1',['id'=>(int)$instance['order_id']]);
        if($mustHaveOrder&&!$order)throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        if($order&&((int)($order['is_demo']??0)!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于可执行维修售后测试的数据域。','TEST_REPAIR_ORDER_BOUNDARY',409);
        return['instance'=>$instance,'scenario_key'=>(string)$instance['scenario_key'],'order'=>$order];
    }

    /** @return array<string,mixed> */
    private function summary(int $orderId): array
    {
        $order=$this->db->fetch('SELECT public_no,lifecycle_status,fulfillment_status,assigned_worker_id,warranty_months,warranty_expires_at,accepted_at,version FROM orders WHERE id=:id',['id'=>$orderId]);
        $repair=$this->latestRepair($orderId);
        return[
            'order'=>$order?:[],
            'repair'=>$repair?['id'=>(int)$repair['id'],'public_no'=>(string)$repair['public_no'],'status'=>(string)$repair['status'],'resolution'=>(string)($repair['resolution']??''),'opened_at'=>$repair['opened_at']??null,'closed_at'=>$repair['closed_at']??null]:null,
            'repair_appointments'=>$repair?$this->repairAppointmentCount($orderId,(int)$repair['id']):0,
            'reviews'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM reviews WHERE order_id=:id',['id'=>$orderId]),
            'auto_reviews'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM reviews WHERE order_id=:id AND is_auto=1",['id'=>$orderId]),
            'external_outbox'=>$this->outboxCount($orderId,(string)($order['public_no']??'')),
        ];
    }

    private function outboxCount(int $orderId,string $orderNo): int
    {
        if(!$this->db->tableExists('message_outbox'))return 0;
        return(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id_needle OR payload_json LIKE :no_needle OR business_key LIKE :business_needle',['id_needle'=>'%"order_id":'.$orderId.'%','no_needle'=>'%'.$orderNo.'%','business_needle'=>'%'.$orderNo.'%']);
    }

    private function failRun(int $runId,float $started,string $code,string $message,int $orderId): void
    {
        $this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after_json,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after_json'=>$this->json(['facts'=>$this->summary($orderId)]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);
    }

    private function supports(string $key): bool{return strpos($key,'ordinary.construction.multi_quote.waiting.')===0;}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @return array<string,mixed> */ private function decode(string $value): array{$decoded=$value!==''?json_decode($value,true):null;return is_array($decoded)?$decoded:[];}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
}

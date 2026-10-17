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
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;

/** 普通测量：报价 → 选报价 → TEST_INTERNAL 补款 → 预约 → 成果 → 确认冻结 → 关联施工。 */
final class DatabaseTestCenterMeasurementActionRunner implements TestCenterActionRunner
{
    private const SCENARIO='ordinary.measurement.multi_quote.waiting.zero_quotes';
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;
    private PlatformWorkerAgreementVersionProvider $agreements;
    public function __construct(Database $db,TestCenterActionRunner $inner){$this->db=$db;$this->inner=$inner;$this->orders=new OrderApplicationContainer($db);$this->agreements=new PlatformWorkerAgreementVersionProvider($db);}

    public function available(string $instancePublicId): array
    {
        $context=$this->context($instancePublicId,false);if((string)$context['scenario_key']!==self::SCENARIO)return$this->inner->available($instancePublicId);
        if(!$context['order'])return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $i=(array)$context['instance'];$o=(array)$context['order'];$state=$this->measurementState((int)$o['id']);$items=[];
        if($this->waiting($o)){
            $workers=$this->approvedParticipants((int)$i['id'],(int)$o['id']);
            if($workers){$default=$workers[0];foreach($workers as$w){if(empty($w['active_quote_id'])){$default=$w;break;}}$items['worker_measurement_quote']=['key'=>'worker_measurement_quote','label'=>empty($default['active_quote_id'])?'真实师傅测量报价':'真实修改测量报价','workers'=>$workers,'default_worker_slot'=>(string)$default['slot_key'],'default_amount_cents'=>max((int)$o['booking_fee_cents']+1000,12000),'description'=>'调用正式 quotes.create，不直接写报价表。'];}
            $quotes=$this->selectableQuotes((int)$o['id']);if($quotes)$items['customer_select_measurement_quote']=['key'=>'customer_select_measurement_quote','label'=>'真实客户选择测量报价','quotes'=>$quotes,'description'=>'调用正式 QuoteSelection；正常情况下进入待补 measurement_balance，而不是直接成交。'];
        }elseif($state&&(string)$state['service_payment_status']==='awaiting_payment'&&(string)$o['quote_status']==='selected_pending_payment'){
            $selection=$this->pendingSelection((int)$o['id']);if($selection){$amount=$this->requiredBalance((int)$o['id'],$selection);if($amount>0)$items['customer_pay_measurement_balance']=['key'=>'customer_pay_measurement_balance','label'=>'TEST_INTERNAL 补齐测量服务款','amount_cents'=>$amount,'description'=>'登记 testing 内部 measurement_balance 支付事实，再调用正式到账结算；不触发微信。'];}
        }elseif($this->dealt($o,$state)){
            if($this->serviceAppointmentCount((int)$o['id'])===0&&(string)$o['fulfillment_status']==='not_started'){
                $tz=(string)\app_config('app.timezone','Asia/Shanghai');try{$at=(new DateTimeImmutable('tomorrow 10:00',new DateTimeZone($tz)))->format('Y-m-d\TH:i');}catch(Throwable $e){$at=date('Y-m-d\TH:i',time()+86400);}
                $items['worker_measurement_appointment']=['key'=>'worker_measurement_appointment','label'=>'真实师傅预约测量','default_appointment_at'=>$at,'description'=>'调用正式 OrderAppointment。'];
            }elseif((string)$o['fulfillment_status']==='scheduled'&&(string)$state['result_status']==='editing'){
                $items['worker_submit_measurement']=['key'=>'worker_submit_measurement','label'=>'真实提交测量成果','description'=>'先生成 testing 私有空间+1x1 图片夹具，再调用正式 orders.measurement.submit；夹具不代表真实上传或标注已验证。'];
            }
        }
        if($state&&(string)$state['result_status']==='submitted'&&(string)$o['fulfillment_status']==='submitted'&&(string)$o['lifecycle_status']==='active'){
            $items['customer_accept_measurement']=['key'=>'customer_accept_measurement','label'=>'真实客户确认测量成果','description'=>'调用正式 fulfillment.accept；普通测量完成后生成冻结净收入和关联施工有效期。'];
        }
        if($state&&(string)$state['result_status']==='accepted'&&(string)$state['settlement_status']==='frozen'&&(string)$o['lifecycle_status']==='completed'){
            $relation=$this->relation((int)$o['id']);
            if(!$relation)$items['customer_create_linked_construction']=['key'=>'customer_create_linked_construction','label'=>'创建并正式关联施工单','description'=>'testing 构造 is_demo 施工草稿；施工预约费由 TEST_INTERNAL 到账，关系必须由生产结算器正式建立。'];
            else{
                $child=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$relation['child_order_id']]);
                if($child&&$this->linkedWaiting($child)){
                    $items['worker_quote_linked_construction']=['key'=>'worker_quote_linked_construction','label'=>'原测量师傅正式施工报价','description'=>'以 relation.worker_id 调用正式 quotes.create，验证隐藏关联单允许原师傅报价。','default_amount_cents'=>max((int)$child['booking_fee_cents'],52000)];
                    $items['worker_probe_linked_construction_other']=['key'=>'worker_probe_linked_construction_other','label'=>'验证其他师傅被拒绝','description'=>'另一名已审核 testing 师傅调用同一正式 quotes.create；只有 MEASUREMENT_WORKER_BOUND 才算通过。'];
                }
            }
        }
        return['items'=>$items,'message'=>$items?'普通测量动作严格按生产状态机推进；要回退请重建实例。':'当前测量节点没有可执行的已适配动作。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        $allowed=['worker_measurement_quote','customer_select_measurement_quote','customer_pay_measurement_balance','worker_measurement_appointment','worker_submit_measurement','customer_accept_measurement','customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other'];
        if(!in_array($actionKey,$allowed,true))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('普通测量测试动作仅允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        $context=$this->context($instancePublicId,true);if((string)$context['scenario_key']!==self::SCENARIO)throw new TestCenterException('当前场景未开放普通测量推进。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试动作请求键无效。','TEST_ACTION_REQUEST_KEY_INVALID',422);
        $i=(array)$context['instance'];$o=(array)$context['order'];$normalized=$this->normalize($actionKey,$input,$o);
        $digest=$this->digest(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$o['id']]);$existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:instance AND BINARY idempotency_key=BINARY :idem LIMIT 1',['instance'=>(int)$i['id'],'idem'=>$requestKey]);
        if($existing){if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一动作请求键已用于不同参数。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);if((string)$existing['result']==='passed')return['replayed'=>true,'run_public_id'=>(string)$existing['public_id'],'result'=>'passed','after'=>$this->decode((string)($existing['after_summary_json']??''))];if((string)$existing['result']==='failed')throw new TestCenterException((string)($existing['error_message']?:'该动作此前已失败。'),(string)($existing['error_code']?:'TEST_ACTION_ALREADY_FAILED'),409);throw new TestCenterException('相同测试动作正在执行。','TEST_ACTION_RUNNING',409);}
        $before=$this->summary((int)$i['id'],(int)$o['id']);$runPublic=\public_id();$started=microtime(true);$mode=$actionKey==='customer_pay_measurement_balance'?'internal_payment':($actionKey==='customer_create_linked_construction'?'preset_plus_real_settlement':'real_service');
        $role=strpos($actionKey,'worker_')===0?'worker':'user';
        $runId=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,:action,:role,:mode,:idem,:digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$runPublic,'instance'=>(int)$i['id'],'action'=>$actionKey,'role'=>$role,'mode'=>$mode,'idem'=>$requestKey,'digest'=>$digest,'request'=>\request_id(),'before'=>$this->json($before),'admin'=>$adminId]);
        try{
            switch($actionKey){
                case'worker_measurement_quote':$business=$this->workerQuote((int)$i['id'],$o,$normalized);break;
                case'customer_select_measurement_quote':$business=$this->selectQuote($o,$normalized);break;
                case'customer_pay_measurement_balance':$business=$this->payBalance((int)$i['id'],$o,$adminId);break;
                case'worker_measurement_appointment':$business=$this->appointment((int)$i['id'],$o,$normalized);break;
                case'worker_submit_measurement':$business=$this->submitMeasurement((int)$i['id'],$o,$adminId);break;
                case'customer_accept_measurement':$business=$this->acceptMeasurement($o);break;
                case'customer_create_linked_construction':$business=(new TestingLinkedConstructionBuilder($this->db,new GlobalPrivateValueCipher()))->build((int)$i['id'],(int)$o['id'],$adminId);break;
                case'worker_quote_linked_construction':$business=$this->quoteLinked((int)$i['id'],$o,$normalized,false);break;
                default:$business=$this->quoteLinked((int)$i['id'],$o,$normalized,true);break;
            }
            $after=$this->summary((int)$i['id'],(int)$o['id']);$this->db->execute("UPDATE test_runs SET result='passed',after_summary_json=:after,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['after'=>$this->json(['business'=>$business,'facts'=>$after]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);$this->db->execute("UPDATE test_instances SET verification_status='pending',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$i['id']]);return['replayed'=>false,'run_public_id'=>$runPublic,'result'=>'passed','business'=>$business,'before'=>$before,'after'=>$after];
        }catch(OrderOperationException $e){$this->fail($runId,$started,$e->apiCode(),$e->getMessage(),(int)$i['id'],(int)$o['id']);$status=(int)$e->getCode();if($status<400||$status>599)$status=409;throw new TestCenterException($e->getMessage(),$e->apiCode(),$status,$e->data());}
        catch(TestCenterException $e){$this->fail($runId,$started,$e->businessCode(),$e->getMessage(),(int)$i['id'],(int)$o['id']);throw$e;}
        catch(Throwable $e){$this->fail($runId,$started,'TEST_ACTION_FAILED',$e->getMessage(),(int)$i['id'],(int)$o['id']);throw new TestCenterException('普通测量测试动作执行失败，请查看运行记录。','TEST_ACTION_FAILED',500,['run_public_id'=>$runPublic]);}
    }

    private function workerQuote(int $instanceId,array $order,array $input): array{return$this->orders->deal()->execute('quotes.create',$this->workerActorBySlot($instanceId,(string)$input['worker_slot']),['order_id'=>(string)$order['public_no'],'amount_cents'=>(int)$input['amount_cents'],'description'=>(string)$input['description'],'quote_details'=>['included'=>'测试测量服务','excluded'=>'','available'=>'按预约上门','conditions'=>'testing'],'rule_acknowledged'=>true,'agreement_versions'=>$this->versions(),'distance_acknowledged'=>true]);}
    private function selectQuote(array $order,array $input): array{$quote=$this->db->fetch("SELECT public_id FROM quotes WHERE order_id=:order AND BINARY public_id=BINARY :quote AND status='active'",['order'=>(int)$order['id'],'quote'=>(string)$input['quote_id']]);if(!$quote)throw new TestCenterException('报价不存在或已失效。','TEST_ACTION_QUOTE_INVALID',409);return$this->orders->quoteSelection()->execute($this->customerActor($order),['quote_id'=>(string)$quote['public_id']]);}
    private function payBalance(int $instanceId,array $order,int $adminId): array{$s=$this->pendingSelection((int)$order['id']);if(!$s)throw new TestCenterException('没有待支付测量报价预占。','TEST_MEASUREMENT_SELECTION_INVALID',409);$amount=$this->requiredBalance((int)$order['id'],$s);return(new TestingMeasurementBalancePaymentSimulator($this->db))->settle($instanceId,(int)$order['id'],(int)$order['user_id'],(int)$s['id'],$amount,$adminId);}
    private function appointment(int $instanceId,array $order,array $input): array{return$this->orders->appointment()->save($this->assignedWorkerActor($instanceId,$order),['order_id'=>(string)$order['public_no'],'context_type'=>'service','appointment_at'=>(string)$input['appointment_at'],'wait_customer_notice'=>false,'note'=>'TEST 普通测量预约']);}
    private function submitMeasurement(int $instanceId,array $order,int $adminId): array{$fixture=(new TestingMeasurementEvidenceFactory($this->db))->ensure($instanceId,(int)$order['id'],$adminId);$result=$this->orders->fulfillment()->execute('orders.measurement.submit',$this->assignedWorkerActor($instanceId,$order),['order_id'=>(string)$order['public_no']]);$result['data']['test_measurement_fixture']=$fixture;return$result;}
    private function acceptMeasurement(array $order): array{return$this->orders->fulfillment()->execute('fulfillment.accept',$this->customerActor($order),['order_id'=>(string)$order['public_no']]);}

    private function quoteLinked(int $instanceId,array $measurement,array $input,bool $probe): array
    {
        $relation=$this->relation((int)$measurement['id']);if(!$relation)throw new TestCenterException('关联施工关系不存在。','TEST_LINK_RELATION_MISSING',409);$child=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$relation['child_order_id']]);if(!$child||!$this->linkedWaiting($child))throw new TestCenterException('关联施工单不在可报价状态。','TEST_LINK_CHILD_STATE',409);
        $workerId=$probe?$this->otherApprovedWorkerId($instanceId,(int)$relation['worker_id']):(int)$relation['worker_id'];$actor=$this->workerActorById($instanceId,$workerId);$body=['order_id'=>(string)$child['public_no'],'amount_cents'=>$probe?53000:(int)$input['amount_cents'],'description'=>$probe?'TEST 非原测量师傅越权探针':'TEST 原测量师傅施工报价','quote_details'=>['included'=>'关联施工','excluded'=>'','available'=>'按预约','conditions'=>'testing'],'rule_acknowledged'=>true,'agreement_versions'=>$this->versions(),'distance_acknowledged'=>true];
        if(!$probe)return$this->orders->deal()->execute('quotes.create',$actor,$body);
        try{$this->orders->deal()->execute('quotes.create',$actor,$body);}catch(OrderOperationException $e){if($e->apiCode()==='MEASUREMENT_WORKER_BOUND')return['expected_rejection'=>true,'error_code'=>$e->apiCode(),'worker_id'=>$workerId];throw$e;}
        throw new TestCenterException('非原测量师傅竟然可以给关联施工单报价。','TEST_LINK_WORKER_BOUNDARY_BROKEN',500);
    }

    private function normalize(string $action,array $input,array $order): array
    {
        if($action==='worker_measurement_quote'){$slot=trim((string)($input['worker_slot']??''));if($slot==='')$slot='worker_1';$amount=$this->money((string)($input['amount_yuan']??'120.00'));if($amount<(int)$order['booking_fee_cents'])throw new TestCenterException('测量报价不能低于预约费。','TEST_ACTION_AMOUNT_INVALID',422);return['worker_slot'=>$slot,'amount_cents'=>$amount,'description'=>mb_substr(trim((string)($input['description']??'TEST 普通测量真实报价')),0,300)];}
        if($action==='customer_select_measurement_quote'){$id=trim((string)($input['quote_id']??''));if($id==='')throw new TestCenterException('请选择测量报价。','TEST_ACTION_QUOTE_REQUIRED',422);return['quote_id'=>$id];}
        if($action==='worker_measurement_appointment'){$at=trim((string)($input['appointment_at']??''));if($at==='')throw new TestCenterException('请选择测量预约时间。','TEST_ACTION_APPOINTMENT_REQUIRED',422);return['appointment_at'=>$at];}
        if($action==='worker_quote_linked_construction')return['amount_cents'=>$this->money((string)($input['amount_yuan']??'520.00'))];
        return[];
    }

    private function context(string $public,bool $must): array{$i=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($public)]);if(!$i)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);$o=!empty($i['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]):null;if($must&&!$o)throw new TestCenterException('测试实例业务数据已清理。','TEST_INSTANCE_DELETED',409);if($o&&((int)$o['is_demo']!==1||strpos((string)$o['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('目标订单不属于测量测试域。','TEST_ACTION_ORDER_BOUNDARY',409);return['instance'=>$i,'scenario_key'=>(string)$i['scenario_key'],'order'=>$o];}
    private function measurementState(int $orderId): ?array{$r=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$orderId]);return$r?:null;}
    private function waiting(array $o): bool{return(string)$o['workflow_profile']==='measurement'&&(string)$o['audit_status']==='approved'&&(string)$o['publish_status']==='published'&&(string)$o['payment_status']==='paid'&&in_array((string)$o['quote_status'],['waiting','quoting','collecting'],true)&&(string)$o['assignment_status']==='unassigned'&&(string)$o['lifecycle_status']==='active';}
    private function dealt(array $o,?array $s): bool{return$s&&(string)$s['service_payment_status']==='paid'&&(string)$o['quote_status']==='selected'&&(string)$o['assignment_status']==='accepted'&&(int)$o['assigned_worker_id']>0&&(int)$o['current_assignment_id']>0&&(string)$o['lifecycle_status']==='active';}
    private function linkedWaiting(array $o): bool{return(int)$o['is_demo']===1&&(string)$o['workflow_profile']==='construction'&&(string)$o['dispatch_mode']==='multi_quote'&&(string)$o['audit_status']==='approved'&&(string)$o['publish_status']==='hidden'&&(string)$o['payment_status']==='paid'&&in_array((string)$o['quote_status'],['waiting','quoting','collecting'],true)&&(string)$o['assignment_status']==='unassigned'&&(string)$o['lifecycle_status']==='active';}
    private function serviceAppointmentCount(int $id): int{return(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='service'",['id'=>$id]);}
    private function pendingSelection(int $id): ?array{$r=$this->db->fetch("SELECT * FROM quote_selections WHERE order_id=:id AND status='pending_payment' ORDER BY id DESC LIMIT 1",['id'=>$id]);return$r?:null;}
    private function requiredBalance(int $id,array $s): int{$paid=(int)$this->db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM payment_orders WHERE order_id=:id AND business_type='order_booking' AND status='paid'",['id'=>$id]);$booking=min((int)$s['amount_cents_snapshot'],min((int)$s['booking_fee_cents_snapshot'],$paid));return max(0,(int)$s['amount_cents_snapshot']-$booking);}
    private function relation(int $parent): ?array{$r=$this->db->fetch("SELECT * FROM order_relations WHERE parent_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$parent]);return$r?:null;}
    private function selectableQuotes(int $id): array{return$this->db->fetchAll("SELECT q.public_id,q.amount_cents,q.worker_id,w.display_name worker_name FROM quotes q JOIN worker_profiles w ON w.id=q.worker_id WHERE q.order_id=:id AND q.quote_type='worker' AND q.status='active' ORDER BY q.id",['id'=>$id]);}
    private function approvedParticipants(int $instance,int $order): array{return$this->db->fetchAll("SELECT tp.slot_key,tp.worker_id,w.display_name,(SELECT public_id FROM quotes q WHERE q.order_id=:order AND q.worker_id=tp.worker_id AND q.status='active' ORDER BY q.id DESC LIMIT 1) active_quote_id FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:instance AND tp.expected_status='approved' AND w.verification_status='approved' ORDER BY tp.id",['instance'=>$instance,'order'=>$order]);}
    private function workerActorBySlot(int $instance,string $slot): array{$row=$this->db->fetch("SELECT tp.user_id,tp.worker_id,u.public_id user_public_id,w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode,w.lat,w.lng,w.city,w.district FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:instance AND BINARY tp.slot_key=BINARY :slot LIMIT 1",['instance'=>$instance,'slot'=>$slot]);if(!$row||(string)$row['verification_status']!=='approved'||(string)$row['cloud_record_mode']!=='demo_local')throw new TestCenterException('测试师傅无效。','TEST_ACTION_WORKER_INVALID',409);return$this->workerActor($row);}
    private function workerActorById(int $instance,int $worker): array{$row=$this->db->fetch("SELECT tp.user_id,tp.worker_id,u.public_id user_public_id,w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode,w.lat,w.lng,w.city,w.district FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:instance AND tp.worker_id=:worker LIMIT 1",['instance'=>$instance,'worker'=>$worker]);if(!$row||(string)$row['verification_status']!=='approved'||(string)$row['cloud_record_mode']!=='demo_local')throw new TestCenterException('测试师傅无效。','TEST_ACTION_WORKER_INVALID',409);return$this->workerActor($row);}
    private function assignedWorkerActor(int $instance,array $o): array{return$this->workerActorById($instance,(int)$o['assigned_worker_id']);}
    private function workerActor(array $w): array{return['user_id'=>(int)$w['user_id'],'real_user_id'=>(int)$w['user_id'],'user_public_id'=>(string)$w['user_public_id'],'worker_id'=>(int)$w['worker_id'],'worker_public_id'=>(string)$w['worker_public_id'],'worker_name'=>(string)$w['display_name'],'worker_status'=>(string)$w['verification_status'],'worker_lat'=>$w['lat']??null,'worker_lng'=>$w['lng']??null,'worker_city'=>(string)($w['city']??''),'worker_district'=>(string)($w['district']??''),'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false];}
    private function customerActor(array $o): array{return['user_id'=>(int)$o['user_id'],'real_user_id'=>(int)$o['user_id'],'worker_id'=>null,'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false];}
    private function otherApprovedWorkerId(int $instance,int $exclude): int{$id=(int)$this->db->fetchValue("SELECT tp.worker_id FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:instance AND tp.expected_status='approved' AND w.verification_status='approved' AND tp.worker_id<>:exclude ORDER BY tp.id LIMIT 1",['instance'=>$instance,'exclude'=>$exclude]);if($id<1)throw new TestCenterException('没有第二名测试师傅可执行绑定探针。','TEST_ACTION_WORKER_INVALID',409);return$id;}
    private function versions(): array{$v=$this->agreements->currentVersions();if(!$v)throw new TestCenterException('当前没有可确认的师傅协议版本。','TEST_ACTION_AGREEMENTS_UNAVAILABLE',409);return$v;}
    private function summary(int $instance,int $order): array{$o=$this->db->fetch('SELECT public_no,quote_status,assignment_status,fulfillment_status,payment_status,lifecycle_status,selected_quote_id,assigned_worker_id,current_assignment_id,final_amount_cents FROM orders WHERE id=:id',['id'=>$order]);$s=$this->measurementState($order);$r=$this->relation($order);return['order'=>$o?:[],'measurement'=>$s?:[],'active_quotes'=>(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",['id'=>$order]),'payments'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_payment_facts WHERE instance_id=:id',['id'=>$instance]),'spaces'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_measurement_spaces WHERE order_id=:id',['id'=>$order]),'relation'=>$r?:[]];}
    private function fail(int $run,float $started,string $code,string $message,int $instance,int $order): void{$this->db->execute("UPDATE test_runs SET result='failed',error_code=:code,error_message=:message,after_summary_json=:after,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>mb_substr($code,0,80),'message'=>mb_substr($message,0,1000),'after'=>$this->json(['facts'=>$this->summary($instance,$order)]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$run]);}
    private function money(string $v): int{$v=trim($v);if(preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D',$v)!==1)throw new TestCenterException('金额格式不正确。','TEST_ACTION_AMOUNT_INVALID',422);[$y,$d]=array_pad(explode('.',$v,2),2,'');return(int)$y*100+(int)substr(str_pad($d,2,'0'),0,2);}
    /** @param mixed $v */private function json($v): string{$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($j)?$j:'{}';}
    /** @return array<string,mixed> */private function decode(string $v): array{$d=$v!==''?json_decode($v,true):null;return is_array($d)?$d:[];}
    /** @param mixed $v */private function digest($v): string{return hash('sha256',$this->json($v));}
}

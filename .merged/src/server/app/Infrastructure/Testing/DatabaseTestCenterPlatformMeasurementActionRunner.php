<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Application\Order\PlatformOrderPricingReview;
use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderPricingReviewRepository;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderFrozenBalanceReleaser;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 平台测量到施工：正式业务入口、同单转换、独立请求幂等。 */
final class DatabaseTestCenterPlatformMeasurementActionRunner implements TestCenterActionRunner
{
    public const SCENARIO='platform.measurement.single_grab.waiting.open';
    private const ACTIONS=['worker_platform_grab','worker_platform_measurement_appointment','worker_platform_submit_measurement','customer_platform_measurement_rework','admin_platform_pricing_review','worker_platform_construction_appointment','worker_platform_complete','customer_platform_accept','system_platform_auto_accept','system_platform_balance_release','admin_platform_income','fixture_platform_refund','admin_platform_refund_review','admin_platform_refund_decide','admin_platform_refund_recovery'];
    private Database $db;
    private TestCenterActionRunner $inner;
    private OrderApplicationContainer $orders;

    public function __construct(Database $db,TestCenterActionRunner $inner)
    {
        $this->db=$db;$this->inner=$inner;$this->orders=new OrderApplicationContainer($db);
    }

    public function available(string $instancePublicId): array
    {
        [$i,$o]=$this->context($instancePublicId);
        if(!TestingPlatformScenario::supports((string)$i['scenario_key']))return$this->inner->available($instancePublicId);
        if(!$o)return['items'=>[],'message'=>'测试实例业务数据已清理。'];
        $items=[];
        foreach(self::ACTIONS as$key){
            if(!$this->allowed($key,$o))continue;
            $item=['key'=>$key,'label'=>$this->label($key)];
            if($key==='worker_platform_grab')$item['workers']=$this->db->fetchAll("SELECT tp.slot_key,w.display_name FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id WHERE tp.instance_id=:id AND tp.expected_status='approved' AND w.verification_status='approved' AND w.cloud_record_mode='demo_local' ORDER BY tp.id",['id'=>(int)$i['id']]);
            if($key==='admin_platform_pricing_review'){
                $terms=$this->db->fetch('SELECT customer_contract_total_cents,worker_labor_cents,customer_pricing_mode FROM order_platform_terms WHERE order_id=:id',['id'=>(int)$o['id']]);
                $item['customer_total_cents']=(int)($terms['customer_contract_total_cents']??0);
                $item['worker_labor_cents']=(int)($terms['worker_labor_cents']??0);$item['pricing_mode']=$terms['customer_pricing_mode'];$item['pricing_items']=$this->db->fetchAll("SELECT sort_order,description,quantity,unit,unit_price_cents FROM order_pricing_items WHERE order_id=:id AND item_source='base' AND status='active' ORDER BY sort_order,id",['id'=>(int)$o['id']]);
            }
            $items[$key]=$item;
        }
        return['items'=>$items,'message'=>'平台托管单在同一订单上测量、核价后直接预约施工；核价由管理员正式权限完成，无客户二次确认。'];
    }

    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
    {
        if(!in_array($actionKey,self::ACTIONS,true))return$this->inner->run($instancePublicId,$actionKey,$input,$adminId,$requestKey);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('平台测量测试只允许 testing 环境。','TEST_ACTION_PRODUCTION_BLOCKED',403);
        [$i,$o]=$this->context($instancePublicId);
        if(!TestingPlatformScenario::supports((string)$i['scenario_key']))throw new TestCenterException('当前场景未适配平台同单测量。','TEST_ACTION_SCENARIO_UNSUPPORTED',409);
        if(!$o)throw new TestCenterException('测试订单已清理。','TEST_INSTANCE_DELETED',409);
        if($adminId<1)throw new TestCenterException('测试经办管理员无效。','TEST_ADMIN_REQUIRED',403);
        if($requestKey===''||strlen($requestKey)>96||preg_match('/^[A-Za-z0-9._:-]+$/D',$requestKey)!==1)throw new TestCenterException('测试请求键无效。','TEST_ACTION_REQUEST_KEY_INVALID',422);
        $normalized=$this->normalize($actionKey,$input);
        $digest=hash('sha256',$this->json(['action'=>$actionKey,'order_id'=>(int)$o['id'],'input'=>$normalized]));
        $existing=$this->db->fetch('SELECT * FROM test_runs WHERE instance_id=:id AND BINARY idempotency_key=BINARY :key LIMIT 1',['id'=>(int)$i['id'],'key'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('请求键已用于不同动作或参数。','TEST_ACTION_IDEMPOTENCY_CONFLICT',409);
            if((string)$existing['result']==='passed')return['replayed'=>true,'result'=>'passed','run_public_id'=>(string)$existing['public_id'],'after'=>json_decode((string)$existing['after_summary_json'],true)];
            throw new TestCenterException((string)($existing['error_message']??'该请求正在执行或已经失败，请查看运行记录。'),(string)($existing['error_code']?:'TEST_ACTION_RUNNING'),409);
        }
        if(!$this->allowed($actionKey,$o))throw new TestCenterException('当前业务状态不允许此平台测量动作。','TEST_ACTION_STATE_CHANGED',409);
        // 请求只允许使用当前实例的订单、参与者和动作参数，不接受 order_id、actor 权限或任意业务类型。
        $before=$this->facts((int)$o['id']);$public=\public_id();$started=microtime(true);
        $mode=in_array($actionKey,['system_platform_auto_accept','system_platform_balance_release'],true)?'scoped_scheduler':(in_array($actionKey,['admin_platform_income','admin_platform_refund_recovery'],true)?'internal_finance':($actionKey==='fixture_platform_refund'?'refund_success_fixture':'real_service'));
        $role=strpos($actionKey,'admin_')===0?'admin':(strpos($actionKey,'customer_')===0?'user':(strpos($actionKey,'worker_')===0?'worker':'system'));
        $run=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,:action,:role,:mode,:key,:digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public,'instance'=>(int)$i['id'],'action'=>$actionKey,'role'=>$role,'mode'=>$mode,'key'=>$requestKey,'digest'=>$digest,'request'=>\request_id(),'before'=>$this->json($before),'admin'=>$adminId]);
        try{
            $business=$this->execute($actionKey,$normalized,$i,$o,$adminId);
            $after=['business'=>$business,'facts'=>$this->facts((int)$o['id'])];
            $this->finish($run,$started,'passed',$after);
            $this->db->execute("UPDATE test_instances SET verification_status='pending',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$i['id']]);
            return['replayed'=>false,'result'=>'passed','run_public_id'=>$public,'business'=>$business,'before'=>$before,'after'=>$after];
        }catch(Throwable $e){
            $code=$e instanceof OrderOperationException?$e->apiCode():($e instanceof TestCenterException?$e->businessCode():'TEST_ACTION_FAILED');
            $this->finish($run,$started,'failed',['facts'=>$this->facts((int)$o['id'])],$code,$e->getMessage());
            if($e instanceof TestCenterException)throw$e;
            $status=$e instanceof OrderOperationException?(int)$e->getCode():500;
            throw new TestCenterException($e instanceof OrderOperationException?$e->getMessage():'平台测量动作执行失败，请查看运行记录。',$code,$status>=400&&$status<=599?$status:409,['run_public_id'=>$public]);
        }
    }

    private function execute(string $key,array $input,array $i,array $o,int $adminId): array
    {
        $orderNo=(string)$o['public_no'];
        if(in_array($key,['fixture_platform_refund','admin_platform_refund_review','admin_platform_refund_decide','admin_platform_refund_recovery'],true))return(new TestingPlatformRefundReview($this->db))->execute($i,$o,$key,$adminId);
        if($key==='admin_platform_pricing_review')return(new PlatformOrderPricingReview(new DatabasePlatformOrderPricingReviewRepository($this->db)))->review(['user_id'=>$adminId,'real_user_id'=>$adminId,'is_admin'=>true],['order_id'=>$orderNo]+$input);
        if($key==='customer_platform_measurement_rework')return$this->orders->fulfillment()->execute('orders.measurement.rework',['user_id'=>(int)$o['user_id'],'real_user_id'=>(int)$o['user_id'],'worker_id'=>null,'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false],['order_id'=>$orderNo,'reason'=>$input['reason']]);
        if($key==='system_platform_auto_accept'){(new TestingMediaFixtureFactory($this->db))->ensureEvidence((int)$i['id'],(int)$o['id'],'collection_receipt',$adminId);return(new TestingPlatformAutomaticAcceptance($this->db))->accept((int)$i['id']);}
        if($key==='system_platform_balance_release'){$result=(new PlatformOrderFrozenBalanceReleaser())->releaseTestInstance($this->db,(int)$i['id'],true);if(empty($result['released']))throw new TestCenterException('当前平台冻结工费尚不可释放或存在业务阻断。','TEST_PLATFORM_BALANCE_RELEASE_BLOCKED',409,$result);return$result;}
        if($key==='customer_platform_accept'){(new TestingMediaFixtureFactory($this->db))->ensureEvidence((int)$i['id'],(int)$o['id'],'collection_receipt',$adminId);return$this->orders->fulfillment()->execute('fulfillment.accept',['user_id'=>(int)$o['user_id'],'real_user_id'=>(int)$o['user_id'],'is_demo_actor'=>true,'demo_role'=>'user','is_admin'=>false,'business_admin'=>false],['order_id'=>$orderNo]);}
        if($key==='admin_platform_income'){$terms=$this->db->fetch('SELECT platform_receivable_cents FROM order_platform_terms WHERE order_id=:id',['id'=>(int)$o['id']]);(new \WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderFinanceRepository($this->db))->markIncomePaid((int)$o['id'],(int)$terms['platform_receivable_cents'],'TEST_INTERNAL:platform:'.(int)$i['id'],'TEST_INTERNAL 测试内部核账数据，未验证外部转账',$adminId);return['fixture_reference'=>true,'external_receipt_verified'=>false];}
        $actor=$this->worker((int)$i['id'],$key==='worker_platform_grab'?(string)$input['worker_slot']:'',(int)($o['assigned_worker_id']??0));
        if($key==='worker_platform_grab'){
            $versions=(new PlatformWorkerAgreementVersionProvider($this->db))->currentVersions();
            if(!$versions)throw new TestCenterException('师傅协议版本不可用。','TEST_ACTION_AGREEMENTS_UNAVAILABLE',409);
            return$this->orders->deal()->execute('orders.grab',$actor,['order_id'=>$orderNo,'fixed_price_acknowledged'=>true,'distance_acknowledged'=>true,'rule_acknowledged'=>true,'agreement_versions'=>$versions]);
        }
        if($key==='worker_platform_complete'){(new TestingMediaFixtureFactory($this->db))->ensureEvidence((int)$i['id'],(int)$o['id'],'completion',$adminId);return$this->orders->fulfillment()->execute('fulfillment.complete',$actor,['order_id'=>$orderNo,'description'=>'测试平台施工完工','warranty_months'=>0,'warranty_note'=>'']);}
        if($key==='worker_platform_submit_measurement'){
            $fixture=(new TestingMeasurementEvidenceFactory($this->db))->ensure((int)$i['id'],(int)$o['id'],$adminId);
            $result=$this->orders->fulfillment()->execute('orders.measurement.submit',$actor,['order_id'=>$orderNo]);
            $result['test_fixture']=$fixture;
            return$result;
        }
        return$this->orders->appointment()->save($actor,['order_id'=>$orderNo,'context_type'=>'service','appointment_at'=>$input['appointment_at'],'wait_customer_notice'=>false,'note'=>$key==='worker_platform_measurement_appointment'?'测试平台测量预约':'测试核价后施工预约']);
    }

    private function allowed(string $key,array $o): bool
    {
        if(!in_array((string)$o['lifecycle_status'],['active','completed'],true)||(string)$o['order_source_type']!=='platform'||(string)$o['payment_status']!=='not_required'||(int)$o['booking_fee_cents']!==0)return false;
        if(!in_array((string)$o['refund_status'],['none','failed'],true)||!in_array((string)$o['dispute_status'],['none','resolved'],true))return false;
        $measurement=(string)$o['workflow_profile']==='measurement';
        $state=$this->db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:id',['id'=>(int)$o['id']]);
        $terms=$this->db->fetch('SELECT settlement_status,worker_settlement_mode,worker_balance_freeze_days_snapshot,worker_balance_credit_cents FROM order_platform_terms WHERE order_id=:id',['id'=>(int)$o['id']]);
        if(in_array($key,['fixture_platform_refund','admin_platform_refund_review','admin_platform_refund_decide','admin_platform_refund_recovery'],true)){if($o['lifecycle_status']!=='completed'||!$terms||$terms['settlement_status']!=='settled')return false;$refund=$this->db->fetch('SELECT r.id,v.status FROM refunds r LEFT JOIN platform_order_refund_reviews v ON v.refund_id=r.id WHERE r.order_id=:id ORDER BY r.id DESC LIMIT 1',['id'=>(int)$o['id']]);if($key==='fixture_platform_refund')return!$refund;if(!$refund)return false;if($key==='admin_platform_refund_review')return empty($refund['status']);if($key==='admin_platform_refund_decide')return$refund['status']==='open';return$refund['status']==='awaiting_recovery';}
        if($key==='system_platform_balance_release'){if($o['lifecycle_status']!=='completed'||$o['fulfillment_status']!=='accepted'||!$terms||$terms['worker_settlement_mode']!=='platform_balance'||(int)$terms['worker_balance_freeze_days_snapshot']<1||(int)$terms['worker_balance_credit_cents']<1||!in_array($terms['settlement_status'],['awaiting_platform_income','settled'],true))return false;$source=(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id AND entry_type='platform_order_income_frozen' AND idempotency_key=:key AND status='effective'",['id'=>(int)$o['id'],'key'=>'platform-order:'.(int)$o['id'].':worker-income']);$released=(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id AND entry_type='platform_order_income_release' AND idempotency_key=:key AND status='effective'",['id'=>(int)$o['id'],'key'=>'platform-order:'.(int)$o['id'].':worker-income-release']);return$source===1&&$released===0;}
        if($key==='admin_platform_income')return$o['lifecycle_status']==='completed'&&$terms&&$terms['settlement_status']==='awaiting_platform_income';
        if($o['lifecycle_status']!=='active')return false;
        $dealt=(string)$o['assignment_status']==='accepted'&&(int)$o['current_assignment_id']>0&&(int)$o['assigned_worker_id']>0;
        $service=(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='service'",['id'=>(int)$o['id']]);
        if($key==='worker_platform_grab')return$measurement&&(string)$o['dispatch_mode']==='single_grab'&&(string)$o['audit_status']==='approved'&&(string)$o['publish_status']==='published'&&(string)$o['quote_status']==='waiting'&&(string)$o['assignment_status']==='unassigned';
        if(!$dealt||!$state)return false;
        if($key==='worker_platform_measurement_appointment')return$measurement&&$state['result_status']==='editing'&&$service===0;
        if($key==='worker_platform_submit_measurement')return$measurement&&$state['result_status']==='editing'&&$service>0;
        if($key==='customer_platform_measurement_rework')return$this->boundCustomer($o)&&$measurement&&$state['result_status']==='accepted'&&(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:id AND event_type='measurement_rework_requested'",['id'=>(int)$o['id']])===0;
        if($key==='admin_platform_pricing_review')return$measurement&&$state['result_status']==='accepted';
        if($key==='worker_platform_complete')return!$measurement&&in_array($o['fulfillment_status'],['scheduled','in_progress'],true)&&$service===1;
        if($key==='customer_platform_accept')return!$measurement&&$o['fulfillment_status']==='submitted'&&$this->boundCustomer($o);
        if($key==='system_platform_auto_accept')return!$measurement&&$o['fulfillment_status']==='submitted';
        return!$measurement&&(string)$o['workflow_profile']==='construction'&&$state['result_status']==='accepted'&&(string)$o['fulfillment_status']==='not_started'&&$service===0;
    }

    private function context(string $public): array
    {
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($public)]);
        if(!$i)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
        $o=!empty($i['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]):null;
        if(TestingPlatformScenario::supports((string)$i['scenario_key'])&&$o&&((int)$o['is_demo']!==1||(string)$o['public_no']!==(string)$i['order_no_snapshot']||strpos((string)$o['public_no'],'BXDEMOHALL')===0))throw new TestCenterException('平台测试订单归属不符。','TEST_ACTION_ORDER_BOUNDARY',409);
        return[$i,$o];
    }

    private function worker(int $instance,string $slot,int $assigned): array
    {
        $where=$slot!==''?'BINARY tp.slot_key=BINARY :slot':'tp.worker_id=:worker';
        $p=['id'=>$instance]+($slot!==''?['slot'=>$slot]:['worker'=>$assigned]);
        $w=$this->db->fetch("SELECT tp.user_id,tp.worker_id,tp.expected_status,u.public_id user_public_id,w.public_id worker_public_id,w.display_name,w.verification_status,w.cloud_record_mode,w.lat,w.lng FROM test_participants tp JOIN users u ON u.id=tp.user_id JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id WHERE tp.instance_id=:id AND {$where} LIMIT 1",$p);
        if(!$w||$w['expected_status']!=='approved'||$w['verification_status']!=='approved'||$w['cloud_record_mode']!=='demo_local')throw new TestCenterException('师傅不属于本实例 approved 测试参与者。','TEST_ACTION_WORKER_INVALID',409);
        return['user_id'=>(int)$w['user_id'],'real_user_id'=>(int)$w['user_id'],'user_public_id'=>$w['user_public_id'],'worker_id'=>(int)$w['worker_id'],'worker_public_id'=>$w['worker_public_id'],'worker_name'=>$w['display_name'],'worker_status'=>$w['verification_status'],'worker_lat'=>$w['lat'],'worker_lng'=>$w['lng'],'is_demo_actor'=>true,'demo_role'=>'worker','is_admin'=>false,'business_admin'=>false];
    }

    private function normalize(string $key,array $input): array
    {
        if($key==='worker_platform_grab')return['worker_slot'=>trim((string)($input['worker_slot']??'worker_1'))];
        if(in_array($key,['worker_platform_measurement_appointment','worker_platform_construction_appointment'],true))return['appointment_at'=>trim((string)($input['appointment_at']??''))];
        if($key==='customer_platform_measurement_rework')return['reason'=>mb_substr(trim((string)($input['reason']??'测试平台测量退回一次补测')),0,500)];
        if($key==='admin_platform_pricing_review')return['worker_labor'=>trim((string)($input['worker_labor']??'')),'customer_total'=>trim((string)($input['customer_total']??'')),'note'=>mb_substr(trim((string)($input['note']??'测试平台同单核价')),0,500),'items'=>$this->normalizeItems($input)];
        return[];
    }

    private function boundCustomer(array $o):bool{return$this->db->fetchValue('SELECT status FROM users WHERE id=:id',['id'=>(int)$o['user_id']])==='active';}
    private function normalizeItems(array $input):array
    {
        $items=[];foreach((array)($input['items']??[])as$row){if(!is_array($row))throw new TestCenterException('计价项目格式错误。','TEST_PRICING_ITEMS_INVALID',422);$items[]=['sort_order'=>(int)($row['sort_order']??0),'quantity'=>trim((string)($row['quantity']??''))];}return$items;
    }

    private function facts(int $id): array
    {
        return['order'=>$this->db->fetch('SELECT id,public_no,workflow_profile,fulfillment_status,lifecycle_status,current_assignment_id,assigned_worker_id,final_amount_cents,expected_fee_cents,accepted_at,version FROM orders WHERE id=:id',['id'=>$id]),'terms'=>$this->db->fetch('SELECT customer_contract_total_cents,worker_labor_cents,booking_paid_cents,worker_settlement_mode,worker_balance_credit_cents,worker_balance_freeze_days_snapshot,settlement_status FROM order_platform_terms WHERE order_id=:id',['id'=>$id]),'measurement'=>$this->db->fetch('SELECT result_status,settlement_status FROM order_measurement_states WHERE order_id=:id',['id'=>$id]),'platform_ledger'=>$this->db->fetchAll("SELECT entry_type,amount_cents,available_delta_cents,frozen_delta_cents,idempotency_key,status FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id ORDER BY id",['id'=>$id])];
    }
    private function finish(int $run,float $started,string $result,array $after,?string $code=null,?string $message=null): void
    {
        $this->db->execute('UPDATE test_runs SET result=:result,after_summary_json=:after,error_code=:code,error_message=:message,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id',['result'=>$result,'after'=>$this->json($after),'code'=>$code,'message'=>$message===null?null:mb_substr($message,0,1000),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$run]);
    }
    private function label(string $key): string{return['worker_platform_grab'=>'师傅正式抢单','worker_platform_measurement_appointment'=>'预约测量','worker_platform_submit_measurement'=>'提交测量成果','customer_platform_measurement_rework'=>'客户退回一次补测','admin_platform_pricing_review'=>'管理员核价并转施工','worker_platform_construction_appointment'=>'预约施工','worker_platform_complete'=>'师傅提交施工完工','customer_platform_accept'=>'绑定客户验收','system_platform_auto_accept'=>'系统到期验收（限定实例时钟）','system_platform_balance_release'=>'推进平台工费冻结解冻','admin_platform_income'=>'TEST_INTERNAL 平台收入核账','fixture_platform_refund'=>'登记已退款前置夹具','admin_platform_refund_review'=>'正式建立退款复核','admin_platform_refund_decide'=>'正式判定分担责任','admin_platform_refund_recovery'=>'TEST_INTERNAL 分笔追偿核账'][$key];}
    private function json(array $value): string{return(string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}

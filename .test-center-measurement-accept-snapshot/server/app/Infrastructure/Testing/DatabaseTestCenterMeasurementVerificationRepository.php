<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 普通测量及关联施工后置断言。 */
final class DatabaseTestCenterMeasurementVerificationRepository implements TestCenterRepository
{
    private const SCENARIO='ordinary.measurement.multi_quote.waiting.zero_quotes';
    private Database $db;private TestCenterRepository $inner;
    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function instanceDetail(string $publicId): array{return$this->inner->instanceDetail($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);}
    public function cleanupPreview(string $publicId): array{return$this->inner->cleanupPreview($publicId);}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array{return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function verify(string $publicId,int $adminId): array
    {
        $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);
        if(!$instance||(string)($instance['scenario_key']??'')!==self::SCENARIO||empty($instance['order_id']))return$this->inner->verify($publicId,$adminId);
        $latest=$this->db->fetch("SELECT * FROM test_runs WHERE instance_id=:instance AND result='passed' AND action_key IN ('worker_measurement_quote','customer_select_measurement_quote','customer_pay_measurement_balance','worker_measurement_appointment','worker_submit_measurement','customer_measurement_rework','customer_accept_measurement','system_measurement_accept','customer_extend_measurement_hold','customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other','customer_select_linked_construction_quote','release_measurement_income','customer_create_independent_construction') ORDER BY id DESC LIMIT 1",['instance'=>(int)$instance['id']]);
        if(!$latest)return$this->inner->verify($publicId,$adminId);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测量流程检查只允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);$state=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$orderId]);
        if(!$order||!$state||(int)$order['is_demo']!==1||(string)$order['workflow_profile']!=='measurement')throw new TestCenterException('普通测量验证边界失败。','TEST_VERIFY_ORDER_BOUNDARY',409);
        $action=(string)$latest['action_key'];$checks=$this->commonChecks((int)$instance['id'],$order,$state);
        $activeQuotes=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND quote_type='worker' AND status='active'",['id'=>$orderId]);
        $selection=$this->db->fetch('SELECT * FROM quote_selections WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>$orderId]);
        $assignments=(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=:id',['id'=>$orderId]);
        $accepted=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status IN ('accepted','completed')",['id'=>$orderId]);
        $reworks=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:id AND event_type='measurement_rework_requested'",['id'=>$orderId]);
        $checks[]=['progress','measurement_rework_limit','普通测量补测次数不得超过一次',$reworks<=1,'reworks='.$reworks];
        if($action==='worker_measurement_quote')$checks[]=['progress','measurement_quote_active','至少一个正式有效测量报价',$activeQuotes>=1,'active_quotes='.$activeQuotes];
        if($action==='customer_select_measurement_quote'){
            $balanceFacts=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_payment_facts WHERE instance_id=:instance AND business_type='measurement_balance'",['instance'=>(int)$instance['id']]);
            $checks[]=['progress','selection_pending_payment','选测量报价后进入 pending_payment',$selection&&(string)$selection['status']==='pending_payment'&&(string)$order['quote_status']==='selected_pending_payment',(string)($selection['status']??'missing').'/'.(string)$order['quote_status']];
            $checks[]=['progress','measurement_balance_waiting','状态等待测量补款',(string)$state['service_payment_status']==='awaiting_payment',(string)$state['service_payment_status']];
            $checks[]=['funds','no_balance_before_payment','选报价不会伪造 measurement_balance',$balanceFacts===0,'balance_facts='.$balanceFacts];
        }
        $postPayment=['customer_pay_measurement_balance','worker_measurement_appointment','worker_submit_measurement','customer_measurement_rework','customer_accept_measurement','system_measurement_accept','customer_extend_measurement_hold','customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other','customer_select_linked_construction_quote','release_measurement_income','customer_create_independent_construction'];
        if(in_array($action,$postPayment,true)){
            $balance=$this->paymentFacts((int)$instance['id'],$orderId,'measurement_balance');
            $checks[]=['funds','measurement_balance_internal','恰有 1 笔 TEST_INTERNAL measurement_balance',count($balance)===1&&$this->allInternal($balance),'rows='.count($balance)];
            $checks[]=['progress','measurement_dealt','补款后 selected + accepted/completed assignment',(string)$state['service_payment_status']==='paid'&&(string)$order['quote_status']==='selected'&&$assignments===1&&$accepted===1,'service='.(string)$state['service_payment_status'].'; quote='.(string)$order['quote_status'].'; assignments='.$assignments.'; accepted='.$accepted];
        }
        $postAppointment=['worker_measurement_appointment','worker_submit_measurement','customer_measurement_rework','customer_accept_measurement','system_measurement_accept','customer_extend_measurement_hold','customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other','customer_select_linked_construction_quote','release_measurement_income','customer_create_independent_construction'];
        if(in_array($action,$postAppointment,true)){
            $appointments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=:id AND context_type='service'",['id'=>$orderId]);
            $checks[]=['progress','measurement_appointment','正式测量预约恰有 1 条',$appointments===1,'appointments='.$appointments];
        }
        $postEvidence=['worker_submit_measurement','customer_measurement_rework','customer_accept_measurement','system_measurement_accept','customer_extend_measurement_hold','customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other','customer_select_linked_construction_quote','release_measurement_income','customer_create_independent_construction'];
        if(in_array($action,$postEvidence,true)){
            $spaces=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_measurement_spaces WHERE order_id=:id AND status='active'",['id'=>$orderId]);
            $images=(int)$this->db->fetchValue("SELECT COUNT(*) FROM media_links ml JOIN order_measurement_spaces s ON s.id=ml.entity_id JOIN test_media_facts tm ON tm.media_file_id=ml.media_file_id AND tm.instance_id=:instance WHERE ml.entity_type='order_measurement_space' AND s.order_id=:order AND s.status='active' AND tm.purpose='measurement_result'",['instance'=>(int)$instance['id'],'order'=>$orderId]);
            $checks[]=['media','measurement_result_fixture','至少 1 个空间且每个测试空间有归属图片',$spaces>=1&&$images>=$spaces,'spaces='.$spaces.'; owned_images='.$images];
        }
        if($action==='worker_submit_measurement')$checks[]=['progress','measurement_submitted','成果正式进入 submitted',(string)$state['result_status']==='submitted'&&(string)$order['fulfillment_status']==='submitted',(string)$state['result_status'].'/'.(string)$order['fulfillment_status']];
        if($action==='customer_measurement_rework'){
            $checks[]=['progress','measurement_rework_editing','一次补测正式退回 editing/in_progress',$reworks===1&&(string)$state['result_status']==='editing'&&(string)$order['fulfillment_status']==='in_progress','reworks='.$reworks.'; state='.(string)$state['result_status'].'/'.(string)$order['fulfillment_status']];
        }
        $postAccept=['customer_accept_measurement','system_measurement_accept','customer_extend_measurement_hold','customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other','customer_select_linked_construction_quote','release_measurement_income','customer_create_independent_construction'];
        if(in_array($action,$postAccept,true)){
            $ledger=$this->db->fetchAll("SELECT * FROM worker_balance_ledger WHERE business_type='order_measurement' AND business_id=:id",['id'=>$orderId]);
            $released=$state['settlement_status']==='released';$net=(int)$state['worker_net_cents'];$ledgerOk=count($ledger)===($net>0?($released?2:1):0);$available=0;$frozen=0;$types=[];
            foreach($ledger as$l){$available+=(int)$l['available_delta_cents'];$frozen+=(int)$l['frozen_delta_cents'];$types[]=$l['entry_type'];if((int)$l['worker_id']!==(int)$order['assigned_worker_id']||(int)$l['amount_cents']!==$net||$l['status']!=='effective')$ledgerOk=false;}
            $ledgerOk=$ledgerOk&&$available===($released?$net:0)&&$frozen===($released?0:$net)&&($net===0||(in_array('measurement_income_frozen',$types,true)&&(!$released||in_array('measurement_income_release',$types,true))));
            $checks[]=['progress','measurement_accepted_frozen','成果 accepted + settlement frozen + 主单 completed',(string)$state['result_status']==='accepted'&&in_array((string)$state['settlement_status'],['frozen','released'],true)&&(string)$order['fulfillment_status']==='accepted'&&(string)$order['lifecycle_status']==='completed',implode('/',[(string)$state['result_status'],(string)$state['settlement_status'],(string)$order['fulfillment_status'],(string)$order['lifecycle_status']])];
            $checks[]=['funds','measurement_frozen_ledger','冻结净收入与 measurement state 精确一致',$ledgerOk,'ledger='.count($ledger).'; net='.(int)$state['worker_net_cents']];
            $checks[]=['progress','measurement_hold_valid','关联施工冻结窗口仍有效',!empty($state['hold_until'])&&($released||strtotime((string)$state['hold_until'].' UTC')>time()),(string)($state['hold_until']??'')];
            $extensions=$this->db->tableExists('order_measurement_hold_extensions')?$this->db->fetchAll('SELECT * FROM order_measurement_hold_extensions WHERE order_id=:id ORDER BY id',['id'=>$orderId]):[];
            if($extensions){$ext=count($extensions)===1?$extensions[0]:null;$approved=$ext?(string)($ext['approved_hold_until']??''):'';$max=(string)($state['max_hold_until']??'');$extensionOk=$ext&&(int)$ext['requested_by_user_id']===(int)$order['user_id']&&(string)$ext['status']==='approved'&&empty($ext['reviewed_by'])&&!empty($approved)&&$approved===(string)$state['hold_until']&&strtotime($approved.' UTC')>strtotime((string)$ext['old_hold_until'].' UTC')&&(!empty($max)&&strtotime($approved.' UTC')<=strtotime($max.' UTC'));
                $checks[]=['progress','measurement_hold_extension_auto_approved','首次小范围延期由正式规则自动批准且不突破 max_hold_until',$extensionOk,'rows='.count($extensions).'; status='.(string)($ext['status']??'missing').'; hold='.(string)$state['hold_until'].'; max='.$max];
            }
        }
        if(in_array($action,['customer_create_linked_construction','worker_quote_linked_construction','worker_probe_linked_construction_other','customer_select_linked_construction_quote'],true))$checks=array_merge($checks,$this->relationChecks((int)$instance['id'],$order,$state,$latest));
        if($action==='release_measurement_income')$checks[]=['funds','measurement_release_completed','释放动作必须真正进入 released 且保留 settled_at',$state['settlement_status']==='released'&&!empty($state['settled_at']),(string)$state['settlement_status']];
        return$this->storeChecks((int)$instance['id'],(int)$latest['id'],(string)$latest['public_id'],$checks,$action,$adminId);
    }

    private function commonChecks(int $instanceId,array $order,array $state): array
    {
        $bookings=$this->paymentFacts($instanceId,(int)$order['id'],'order_booking');$outbox=$this->outboxCount([(int)$order['id']],[(string)$order['public_no']]);
        return[
            ['structure','measurement_shape','demo consumer measurement multi_quote',(int)$order['is_demo']===1&&(string)$order['order_source_type']==='consumer'&&(string)$order['workflow_profile']==='measurement'&&(string)$order['dispatch_mode']==='multi_quote','is_demo='.(int)$order['is_demo'].'; source='.(string)$order['order_source_type'].'; workflow='.(string)$order['workflow_profile']],
            ['funds','booking_internal','父测量单只有 1 笔内部预约费',count($bookings)===1&&$this->allInternal($bookings),'rows='.count($bookings)],
            ['safety','no_external_outbox_parent','普通测量演示单不得产生外部 outbox',$outbox===0,'outbox='.$outbox],
            ['safety','not_review_sample','不得操作 BXDEMOHALL',strpos((string)$order['public_no'],'BXDEMOHALL')!==0,(string)$order['public_no']],
        ];
    }

    private function relationChecks(int $instanceId,array $measurement,array $state,array $latest): array
    {
        $relations=$this->db->fetchAll("SELECT * FROM order_relations WHERE parent_order_id=:id AND relation_type='measurement_construction'",['id'=>(int)$measurement['id']]);$relation=count($relations)===1?$relations[0]:null;$child=$relation?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$relation['child_order_id']]):null;
        $checks=[];$checks[]=['relation','single_measurement_construction','父测量单恰有一个 active measurement_construction',$relation&&(string)$relation['status']==='active','relations='.count($relations)];
        $checks[]=['relation','bound_original_worker','关系 worker 等于原测量成交师傅',$relation&&(int)$relation['worker_id']===(int)$measurement['assigned_worker_id'],'relation_worker='.(int)($relation['worker_id']??0).'; measurement_worker='.(int)$measurement['assigned_worker_id']];
        $checks[]=['relation','amount_snapshot','关系保存测量成交额快照',$relation&&(int)$relation['measurement_amount_cents_snapshot']===(int)$measurement['final_amount_cents'],'snapshot='.(int)($relation['measurement_amount_cents_snapshot']??0).'; final='.(int)$measurement['final_amount_cents']];
        $checks[]=['relation','child_demo_construction','子单为同客户 demo construction multi_quote',$child&&(int)$child['is_demo']===1&&(int)$child['user_id']===(int)$measurement['user_id']&&(string)$child['workflow_profile']==='construction'&&(string)$child['dispatch_mode']==='multi_quote',$child?'child='.$child['public_no'].'; user='.(int)$child['user_id']:'missing'];
        $checks[]=['relation','same_address_hmac','父子地址 HMAC 完全一致',$child&&!empty($measurement['address_hmac'])&&hash_equals((string)$measurement['address_hmac'],(string)$child['address_hmac']),'match='.($child&&hash_equals((string)$measurement['address_hmac'],(string)$child['address_hmac'])?'1':'0')];
        $checks[]=['relation','child_hidden_bound','子单 pending_measurement 已清除且 approved/hidden',$child&&empty($child['pending_measurement_order_id'])&&(string)$child['audit_status']==='approved'&&(string)$child['publish_status']==='hidden','pending='.(int)($child['pending_measurement_order_id']??0).'; state='.(string)($child['audit_status']??'').'/'.(string)($child['publish_status']??'')];
        if($child){$payments=$this->paymentFacts($instanceId,(int)$child['id'],'order_booking');$checks[]=['funds','child_booking_internal','关联施工预约费独立属于子单且 TEST_INTERNAL',count($payments)===1&&$this->allInternal($payments),'rows='.count($payments)];$checks[]=['safety','no_external_outbox_child','关联施工 demo 单不得产生外部 outbox',$this->outboxCount([(int)$child['id']],[(string)$child['public_no']])===0,'outbox='.$this->outboxCount([(int)$child['id']],[(string)$child['public_no']])];}
        if((string)$latest['action_key']==='worker_quote_linked_construction'&&$child){$owned=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND worker_id=:worker AND status='active'",['id'=>(int)$child['id'],'worker'=>(int)$measurement['assigned_worker_id']]);$checks[]=['relation','original_worker_quote_allowed','原测量师傅正式施工报价成功',$owned>=1,'owned_active_quotes='.$owned];}
        if((string)$latest['action_key']==='worker_probe_linked_construction_other'&&$child){$foreign=(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND worker_id<>:worker",['id'=>(int)$child['id'],'worker'=>(int)$measurement['assigned_worker_id']]);$expected=strpos((string)($latest['after_summary_json']??''),'MEASUREMENT_WORKER_BOUND')!==false;$checks[]=['relation','other_worker_rejected','其他师傅必须收到 MEASUREMENT_WORKER_BOUND 且未产生报价',$expected&&$foreign===0,'error_recorded='.($expected?'1':'0').'; foreign_quotes='.$foreign];}
        if($child){$q=$this->db->fetch('SELECT * FROM quotes WHERE order_id=:id ORDER BY id DESC LIMIT 1',['id'=>(int)$child['id']]);if($q){$event=$this->db->fetch("SELECT payload_json FROM order_events WHERE order_id=:id AND event_type IN ('worker_quoted','worker_quote_updated') ORDER BY id DESC LIMIT 1",['id'=>(int)$child['id']]);$payload=json_decode((string)($event['payload_json']??''),true);$deduction=(int)$relation['agreed_discount_cents'];$checks[]=['funds','construction_discount_snapshot','正式报价 gross - deduction = net；优惠不超过测量费',$deduction>=0&&$deduction<=(int)$relation['measurement_amount_cents_snapshot']&&(int)($payload['measurement_deduction_cents']??-1)===$deduction&&(int)($payload['gross_construction_amount_cents']??0)-$deduction===(int)$q['amount_cents'],'discount='.$deduction.'; net='.(int)$q['amount_cents']];}}
        return$checks;
    }

    private function paymentFacts(int $instance,int $order,string $type): array{return$this->db->fetchAll("SELECT p.*,tf.simulated_provider FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND tf.business_type=:type AND p.order_id=:order ORDER BY p.id",['instance'=>$instance,'type'=>$type,'order'=>$order]);}
    private function allInternal(array $rows): bool{foreach($rows as$r){if((string)($r['simulated_provider']??'')!=='internal_test'||(string)($r['status']??'')!=='paid'||!empty($r['wechat_prepay_id'])||!empty($r['wechat_transaction_id'])||(string)($r['app_id']??'')!=='TEST_INTERNAL'||(string)($r['mch_id']??'')!=='TEST_INTERNAL')return false;}return true;}
    private function outboxCount(array $ids,array $nos): int{if(!$this->db->tableExists('message_outbox'))return 0;$total=0;foreach($ids as$k=>$id){$no=$nos[$k]??'';$total+=(int)$this->db->fetchValue('SELECT COUNT(*) FROM message_outbox WHERE payload_json LIKE :id OR payload_json LIKE :no OR business_key LIKE :business',['id'=>'%"order_id":'.$id.'%','no'=>'%'.$no.'%','business'=>'%'.$no.'%']);}return$total;}
    private function storeChecks(int $instanceId,int $sourceRunId,string $sourceRunPublic,array $checks,string $action,int $adminId): array{$runPublic=\public_id();$runId=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,before_summary_json,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,'verify_measurement_flow','admin','inspect',:idem,:digest,:request,:before,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$runPublic,'instance'=>$instanceId,'idem'=>'verify-measurement:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$sourceRunPublic.'|'.$action),'request'=>\request_id(),'before'=>$this->json(['source_run_id'=>$sourceRunId,'source_run_public'=>$sourceRunPublic,'latest_action'=>$action]),'admin'=>$adminId]);$this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;foreach($checks as$c){[$g,$k,$e,$p,$a]=$c;if(!$p)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:g,:k,:s,:e,:a,:d,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'g'=>$g,'k'=>$k,'s'=>$p?'passed':'failed','e'=>$e,'a'=>$a,'d'=>$this->json(['latest_action'=>$action])]);}$status=$failed===0?'passed':'failed';$this->db->execute("UPDATE test_instances SET verification_status=:s,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['s'=>$status,'id'=>$instanceId]);$this->db->execute("UPDATE test_runs SET result=:s,after_summary_json=:a,finished_at=UTC_TIMESTAMP() WHERE id=:id",['s'=>$status,'a'=>$this->json(['assertions'=>count($checks),'failed'=>$failed,'latest_action'=>$action]),'id'=>$runId]);return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic,'mode'=>'measurement_flow','latest_real_action'=>$action];}
    /** @param mixed $v */private function json($v): string{$j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($j)?$j:'{}';}
}


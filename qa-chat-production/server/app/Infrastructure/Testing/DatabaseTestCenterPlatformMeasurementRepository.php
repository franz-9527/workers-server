<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 平台同单测量的投影、阶段断言和限定在施工预约前后的清理。 */
final class DatabaseTestCenterPlatformMeasurementRepository implements TestCenterRepository
{
    private const SCENARIO=DatabaseTestCenterPlatformMeasurementActionRunner::SCENARIO;
    private const OWNED=['order_categories','order_addresses','order_answers','order_platform_terms','order_measurement_states','order_measurement_spaces','order_events','quotes','assignments','assignment_history','commission_rule_versions','appointment_records','order_pricing_items','completion_submissions','order_deadlines','payment_orders','refunds','platform_order_refund_reviews','platform_order_manual_receipts'];
    private Database $db;
    private TestCenterRepository $inner;
    public function __construct(Database $db,TestCenterRepository $inner){$this->db=$db;$this->inner=$inner;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array
    {
        $detail=$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);
        if(!TestingPlatformScenario::supports((string)$scenario['key']))return$detail;
        $public=(string)$detail['instance']['public_id'];$this->verify($public,$adminId);
        return$this->instanceDetail($public);
    }
    public function instanceDetail(string $publicId): array
    {
        $d=$this->inner->instanceDetail($publicId);
        if(!TestingPlatformScenario::supports((string)($d['instance']['scenario_key']??'')))return$d;
        $id=(int)($d['instance']['order_id']??0);
        if($id>0){
            $d['measurement_state']=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$id]);
            $d['platform_appointments']=$this->rows('appointment_records',$id);
        }
        $d['cleanup']=$this->cleanupPreview($publicId);
        return$d;
    }

    public function verify(string $publicId,int $adminId): array
    {
        $i=$this->instance($publicId);
        if(!TestingPlatformScenario::supports((string)$i['scenario_key']))return$this->inner->verify($publicId,$adminId);
        $this->testing();$o=$this->order($i);$id=(int)$o['id'];
        $s=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$id]);
        $t=$this->db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id',['id'=>$id]);
        $a=$this->db->fetch('SELECT * FROM assignments WHERE id=:id AND order_id=:order',['id'=>(int)($o['current_assignment_id']??0),'order'=>$id]);
        $reviews=$this->events($id,'platform_measurement_pricing_reviewed');
        $confirmations=$this->events($id,'platform_measurement_pricing_confirmed');
        $review=count($reviews)===1?$reviews[0]:null;$confirmed=count($confirmations)===1?$confirmations[0]:null;
        $appointments=$this->rows('appointment_records',$id);$service=0;$archived=0;$ownedAppointments=true;
        foreach($appointments as$r){if($r['context_type']==='service')$service++;elseif($r['context_type']==='measurement')$archived++;else$ownedAppointments=false;if((int)$r['worker_id']!==(int)($o['assigned_worker_id']??0))$ownedAppointments=false;}
        $checks=[
            ['structure','platform_test_domain','同一 demo 平台订单 / single_grab',(int)$o['is_demo']===1&&$o['order_source_type']==='platform'&&$o['dispatch_mode']==='single_grab'&&(string)$o['public_no']===(string)$i['order_no_snapshot'],(string)$o['public_no']],
            ['funds','external_prepayment_only','平台外部预付款；无普通支付链',$t&&$t['customer_flow_mode']==='platform_managed'&&$o['payment_status']==='not_required'&&(int)$o['booking_fee_cents']===0&&(int)$this->db->fetchValue("SELECT COUNT(*) FROM payment_orders WHERE order_id=:id AND business_type IN ('order_booking','booking_fee')",['id'=>$id])===0,'payments='.$this->count('payment_orders',$id)],
            ['funds','customer_snapshot','客户金额与平台条款一致；扣除预付款和工费不形成负差额',$t&&(int)$o['final_amount_cents']===(int)$t['customer_contract_total_cents']&&(int)$t['booking_paid_cents']>=0&&(int)$t['worker_labor_cents']>0&&($t['worker_settlement_mode']==='platform_balance'||(int)$t['worker_labor_cents']+(int)$t['booking_paid_cents']<=(int)$t['customer_contract_total_cents']),'customer='.(int)$o['final_amount_cents']],
            ['funds','no_measurement_income','平台测量不产生普通测量冻结收入',$s&&$s['settlement_status']==='not_applicable'&&(int)($s['worker_net_cents']??0)===0&&(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=:id AND business_type='order_measurement'",['id'=>$id])===0,'ledger='.$this->ledgerCount($id)],
            ['structure','no_second_order','不建立 measurement_construction 或其它跨单关系',empty($o['pending_measurement_order_id'])&&$this->relationCount($id)===0,'relations='.$this->relationCount($id)],
            ['safety','no_external_outbox','demo 不产生真实渠道发件箱',$this->outboxCount($id,(string)$o['public_no'])===0,'outbox='.$this->outboxCount($id,(string)$o['public_no'])],
            ['progress','rework_once','平台测量最多退回补测一次',count($this->events($id,'measurement_rework_requested'))<=1,'reworks='.count($this->events($id,'measurement_rework_requested'))],
            ['progress','appointments_owned','测量/施工预约属于原成交师傅',$ownedAppointments&&$service<=1&&$archived<=1,'service='.$service.'; measurement='.$archived],
        ];
        if($a){
            $participant=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id WHERE tp.instance_id=:instance AND tp.worker_id=:worker AND tp.expected_status='approved' AND w.cloud_record_mode='demo_local' AND w.verification_status='approved'",['instance'=>(int)$i['id'],'worker'=>(int)$o['assigned_worker_id']]);
            $q=$this->db->fetch('SELECT * FROM quotes WHERE id=:id AND order_id=:order',['id'=>(int)$o['selected_quote_id'],'order'=>$id]);
            $quoteVersion=$q?(int)$this->db->fetchValue('SELECT COUNT(*) FROM quote_versions WHERE quote_id=:id AND version_no=:v',['id'=>(int)$q['id'],'v'=>(int)$q['current_version']]):0;
            $grabEvents=$this->events($id,'platform_single_grab_accepted');$grab=count($grabEvents)===1?json_decode((string)$grabEvents[0]['payload_json'],true):[];
            $checks[]=['progress','accepted_original_worker','唯一正式成交关系、测试师傅与原抢单事件一致',$this->count('assignments',$id)===1&&in_array($a['status'],['accepted','completed'],true)&&(int)$a['worker_id']===(int)$o['assigned_worker_id']&&$participant===1&&(string)($grab['assignment_id']??'')===(string)$a['public_id']&&$o['quote_status']==='selected'&&$o['assignment_status']==='accepted'&&$o['publish_status']==='hidden','assignments='.$this->count('assignments',$id)];
            $checks[]=['progress','selected_quote_version','正式 selected quote 及其版本保留',$q&&$q['status']==='selected'&&(int)$q['worker_id']===(int)$a['worker_id']&&$quoteVersion===1,'quote='.(int)($q['id']??0)];
            $checks[]=['funds','worker_labor_snapshot','工费快照与平台条款一致，服务费为零',$t&&(int)$a['final_amount_cents_snapshot']===(int)$t['worker_labor_cents']&&(int)$a['estimated_net_income_cents']===(int)$t['worker_labor_cents']&&(int)$a['estimated_commission_cents']===0,'labor='.(int)($t['worker_labor_cents']??0)];
        }else{
            $checks[]=['progress','waiting_unassigned','起点未成交，未制造报价或 assignment',$o['workflow_profile']==='measurement'&&$o['quote_status']==='waiting'&&$o['assignment_status']==='unassigned'&&empty($o['assigned_worker_id'])&&empty($o['current_assignment_id'])&&empty($o['selected_quote_id'])&&$this->count('assignments',$id)===0&&$this->count('quotes',$id)===0,'quote_status='.(string)$o['quote_status']];
        }
        if($s&&$s['result_status']==='accepted'){
            $spaces=$this->db->fetchAll("SELECT s.id,(SELECT COUNT(*) FROM media_links ml JOIN test_media_facts tm ON tm.media_file_id=ml.media_file_id AND tm.instance_id=:instance WHERE ml.entity_type='order_measurement_space' AND ml.entity_id=s.id AND tm.purpose='measurement_result') owned_images FROM order_measurement_spaces s WHERE s.order_id=:id AND s.status='active'",['instance'=>(int)$i['id'],'id'=>$id]);
            $allImages=count($spaces)>0;foreach($spaces as$space)if((int)$space['owned_images']<1)$allImages=false;
            $checks[]=['media','measurement_evidence','每个空间至少一张明确归属的 testing 成果图',$allImages,'spaces='.count($spaces)];
            $checks[]=['progress','auto_confirmed_result','成果自动确认且订单保持 active',!empty($s['accepted_at'])&&in_array($o['lifecycle_status'],['active','completed'],true)&&count($this->events($id,'platform_measurement_auto_confirmed'))>=1,'lifecycle='.(string)$o['lifecycle_status']];
        }
        if($o['workflow_profile']==='construction'){
            $rp=$review?json_decode((string)$review['payload_json'],true):[];$cp=$confirmed?json_decode((string)$confirmed['payload_json'],true):[];
            $checks[]=['progress','same_order_construction','同一订单核价后进入施工且保持原 assignment',$s&&$s['result_status']==='accepted'&&$a&&in_array($o['lifecycle_status'],['active','completed'],true)&&in_array((string)$o['fulfillment_status'],['not_started','scheduled','in_progress','submitted','accepted'],true),'order='.$id.'; assignment='.(int)($a['id']??0)];
            $checks[]=['progress','pricing_auto_confirmation','管理员核价 + system 自动确认，事件关联正确',$review&&$confirmed&&$review['actor_type']==='admin'&&(int)$review['actor_id']>0&&$confirmed['actor_type']==='system'&&($rp['requires_customer_confirmation']??null)===false&&($cp['confirmation_mode']??'')==='platform_managed_auto'&&(int)($cp['pricing_review_event_id']??0)===(int)$review['id'],'reviews='.count($reviews).'; confirmations='.count($confirmations)];
            $checks[]=['funds','reviewed_amounts','核价事件、订单、assignment 工费快照一致',$t&&(int)($rp['new_customer_total_cents']??0)===(int)$t['customer_contract_total_cents']&&(int)($rp['new_worker_labor_cents']??0)===(int)$t['worker_labor_cents']&&(int)$o['expected_fee_cents']===(int)$t['worker_labor_cents'],'expected_fee='.(int)$o['expected_fee_cents']];
            $checks[]=['progress','separate_appointments','测量预约归档；施工预约另存一条',$archived===1&&$service===($o['fulfillment_status']==='not_started'?0:1),'measurement='.$archived.'; service='.$service];
        }else{
            $checks[]=['progress','measurement_stage','核价前仍在测量阶段，无核价确认事件',$o['workflow_profile']==='measurement'&&count($reviews)===0&&count($confirmations)===0,(string)$o['workflow_profile']];
        }
        $finance=(new TestingPlatformFinanceOwnership($this->db))->inspect($i,$o,$t?:[]);$checks[]=['funds','platform_finance_owned','所有财务凭证与台账属于当前测试实例',empty($finance['blocking']),$this->json($finance['blocking'])];
        $releaseRun=$this->db->fetch("SELECT actor_role,operation_mode,result FROM test_runs WHERE instance_id=:id AND action_key='system_platform_balance_release' ORDER BY id DESC LIMIT 1",['id'=>(int)$i['id']]);
        if($releaseRun&&$releaseRun['result']==='passed'){
            $credit=(int)($t['worker_balance_credit_cents']??0);
            $release=$this->db->fetch("SELECT * FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id AND entry_type='platform_order_income_release' AND idempotency_key=:key AND status='effective' LIMIT 1",['id'=>$id,'key'=>'platform-order:'.$id.':worker-income-release']);
            $sum=$this->db->fetch("SELECT COALESCE(SUM(available_delta_cents),0) available,COALESCE(SUM(frozen_delta_cents),0) frozen FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id AND status='effective'",['id'=>$id]);
            $event=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:id AND event_type='platform_worker_income_released' AND event_key=:key AND actor_type='system'",['id'=>$id,'key'=>'test-platform-balance-release:'.(int)$i['id'].':'.$id]);
            $globalClaims=$this->db->tableExists('scheduled_job_claims')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE job_name='platform_balance_release' AND business_key=:key",['key'=>'order:'.$id]):0;
            $checks[]=['audit','platform_balance_release_system_action','平台余额释放以 system / scoped_scheduler 记录',$releaseRun['actor_role']==='system'&&$releaseRun['operation_mode']==='scoped_scheduler','role='.$releaseRun['actor_role'].'; mode='.$releaseRun['operation_mode']];
            $checks[]=['funds','platform_balance_release_exact','冻结工费仅转移到可用余额，不改变总工费',$release&&(int)$release['worker_id']===(int)$o['assigned_worker_id']&&(int)$release['amount_cents']===$credit&&(int)$release['available_delta_cents']===$credit&&(int)$release['frozen_delta_cents']===-$credit&&(int)$sum['available']===$credit&&(int)$sum['frozen']===0,'credit='.$credit.'; available='.(int)$sum['available'].'; frozen='.(int)$sum['frozen']];
            $checks[]=['event','platform_balance_release_event','实例释放事件只属于当前 test_instance',$event===1,'events='.$event];
            $checks[]=['safety','platform_balance_release_no_global_claim','scoped 平台余额释放不伪造全局 scheduler claim',$globalClaims===0,'claims='.$globalClaims];
            $checks[]=['funds','platform_income_status_unchanged','工费解冻不伪造平台收入款到账',$t&&in_array((string)$t['settlement_status'],['awaiting_platform_income','settled'],true),'settlement_status='.(string)($t['settlement_status']??'')];
        }
        if($o['lifecycle_status']==='completed'&&$t){$expected=(new \WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy())->settlement((int)$t['customer_contract_total_cents'],(int)$t['worker_labor_cents'],(int)$t['booking_paid_cents'],$t['worker_settlement_mode']);$ok=$a&&$a['status']==='completed'&&$o['fulfillment_status']==='accepted';foreach($expected as$key=>$value)if((int)($t[$key]??-1)!==$value)$ok=false;$checks[]=['funds','platform_settlement_exact','验收后结算与共享领域规则完全一致',$ok,$this->json($expected)];}
        return$this->storeChecks($i,$checks,$adminId);
    }

    public function cleanupPreview(string $publicId): array
    {
        $i=$this->instance($publicId);if(!TestingPlatformScenario::supports((string)$i['scenario_key']))return$this->inner->cleanupPreview($publicId);
        if(empty($i['order_id']))return['allowed'=>false,'reason'=>'实例业务数据已清理。','counts'=>[],'plan_digest'=>''];
        $o=$this->order($i);$id=(int)$o['id'];$blocking=[];$facts=[];
        if($o['order_source_type']!=='platform'||$o['payment_status']!=='not_required'||!in_array($o['lifecycle_status'],['active','completed'],true)||!in_array((string)$o['fulfillment_status'],['not_started','scheduled','in_progress','submitted','accepted'],true))$blocking['platform_stage_unadapted']=1;
        // 所有直接 order_id 依赖逐表检查：未登记的未来扩展关系保持阻断。
        $tables=$this->db->fetchAll("SELECT TABLE_NAME table_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='order_id' ORDER BY TABLE_NAME");
        foreach($tables as$row){
            $table=(string)$row['table_name'];if(preg_match('/^[A-Za-z0-9_]+$/D',$table)!==1)continue;
            $rows=$this->rows($table,$id);if(!$rows)continue;
            if($table==='test_instances'){
                if(count($rows)!==1||(int)$rows[0]['id']!==(int)$i['id'])$blocking['test_instances_shared']=count($rows);
                continue;
            }
            $facts[$table]=$rows;
            if(!in_array($table,self::OWNED,true))$blocking['unadapted:'.$table]=count($rows);
        }
        if($this->relationCount($id)>0)$blocking['order_relations']=$this->relationCount($id);
        if(!empty($o['pending_measurement_order_id']))$blocking['pending_measurement_relation']=1;
        $terms=$this->db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id',['id'=>$id]);$finance=(new TestingPlatformFinanceOwnership($this->db))->inspect($i,$o,$terms?:[]);$blocking=array_merge($blocking,$finance['blocking']);$facts['finance']=$finance['facts'];
        $outbox=$this->outboxCount($id,(string)$o['public_no']);if($outbox>0)$blocking['message_outbox']=$outbox;
        foreach(['scheduled_job_claims','scheduled_job_failures']as$table)if($this->db->tableExists($table)&&$this->db->columnExists($table,'business_key')){
            $n=(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE business_key=:exact OR business_key LIKE :prefix OR business_key LIKE :number",['exact'=>'order:'.$id,'prefix'=>'order:'.$id.':%','number'=>'%'.$o['public_no'].'%']);
            if($n>0)$blocking[$table]=$n;
        }
        $facts['test_participants']=$this->db->fetchAll('SELECT * FROM test_participants WHERE instance_id=:id ORDER BY id',['id'=>(int)$i['id']]);
        $workerIds=array_map('intval',array_column($facts['test_participants'],'worker_id'));
        $validWorkers=(int)$this->db->fetchValue("SELECT COUNT(*) FROM test_participants tp JOIN worker_profiles w ON w.id=tp.worker_id AND w.user_id=tp.user_id WHERE tp.instance_id=:id AND tp.expected_status='approved' AND w.cloud_record_mode='demo_local'",['id'=>(int)$i['id']]);
        if($validWorkers!==count($facts['test_participants']))$blocking['participant_unowned']=1;
        foreach(['quotes','assignments','appointment_records','assignment_history']as$table)foreach($facts[$table]??[]as$r)if(!in_array((int)($r['worker_id']??0),$workerIds,true))$blocking[$table.'_worker_unowned']=1;
        foreach($facts['appointment_records']??[]as$r)if(!in_array((string)$r['context_type'],['service','measurement'],true)||!empty($r['after_sale_id']))$blocking['appointment_unowned']=1;
        $facts['quote_versions']=$this->db->fetchAll('SELECT v.* FROM quote_versions v JOIN quotes q ON q.id=v.quote_id WHERE q.order_id=:id ORDER BY v.id',['id'=>$id]);
        $facts['media']=$this->db->fetchAll('SELECT tm.*,mf.storage_disk,mf.storage_key FROM test_media_facts tm JOIN media_files mf ON mf.id=tm.media_file_id WHERE tm.instance_id=:id ORDER BY tm.id',['id'=>(int)$i['id']]);
        if($this->count('test_media_facts',(int)$i['id'],'instance_id')!==count($facts['media']))$blocking['media_fact_orphaned']=1;
        $foreignPointers=(int)$this->db->fetchValue('SELECT COUNT(*) FROM orders WHERE id<>:id AND ((selected_quote_id IS NOT NULL AND selected_quote_id IN (SELECT id FROM quotes WHERE order_id=:id)) OR (current_assignment_id IS NOT NULL AND current_assignment_id IN (SELECT id FROM assignments WHERE order_id=:id)) OR (commission_rule_version_id IS NOT NULL AND commission_rule_version_id IN (SELECT id FROM commission_rule_versions WHERE order_id=:id)))',['id'=>$id]);
        if($foreignPointers>0)$blocking['foreign_order_pointers']=$foreignPointers;
        $foreignAssignments=(int)$this->db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id<>:id AND (quote_id IN (SELECT id FROM quotes WHERE order_id=:id) OR commission_rule_version_id IN (SELECT id FROM commission_rule_versions WHERE order_id=:id))',['id'=>$id]);
        if($foreignAssignments>0)$blocking['foreign_assignment_pointers']=$foreignAssignments;
        $spaceIds=array_map('intval',array_column($facts['order_measurement_spaces']??[],'id'));$mediaIds=array_map('intval',array_column($facts['media'],'media_file_id'));
        $facts['media_links']=[];
        foreach($facts['media']as$m){
            if($m['storage_disk']!=='private_local'||strpos((string)$m['storage_key'],'test/test-center/'.(int)$i['id'].'/')!==0||strpos((string)$m['storage_key'],'..')!==false||$m['storage_key']!==$m['storage_key_snapshot'])$blocking['media_storage_unowned']=1;
            $links=$this->db->fetchAll('SELECT * FROM media_links WHERE media_file_id=:id ORDER BY id',['id'=>(int)$m['media_file_id']]);
            foreach($links as$l){if(!(($l['entity_type']==='order'&&(int)$l['entity_id']===$id)||($l['entity_type']==='order_measurement_space'&&in_array((int)$l['entity_id'],$spaceIds,true))))$blocking['media_shared']=1;$facts['media_links'][]=$l;}
        }
        $businessLinks=$this->db->fetchAll("SELECT ml.* FROM media_links ml WHERE (ml.entity_type='order' AND ml.entity_id=:id) OR (ml.entity_type='order_measurement_space' AND ml.entity_id IN (SELECT id FROM order_measurement_spaces WHERE order_id=:id)) ORDER BY ml.id",['id'=>$id]);
        foreach($businessLinks as$l)if(!in_array((int)$l['media_file_id'],$mediaIds,true))$blocking['media_link_unowned']=1;
        $facts['notifications']=$this->notifications($id);
        foreach($facts['notifications']as$n)if($n['channel']!=='in_app'||!(($n['recipient_type']==='user'&&(int)$n['recipient_id']===(int)$o['user_id'])||($n['recipient_type']==='worker'&&in_array((int)$n['recipient_id'],$workerIds,true))))$blocking['notification_unowned']=1;
        $counts=[];foreach($facts as$k=>$rows)$counts[$k]=count($rows);
        $plan=['instance_version'=>(int)$i['version'],'order'=>$o,'facts'=>$facts,'blocking'=>$blocking];
        return['allowed'=>!$blocking,'reason'=>$blocking?'发现未适配或无法证明归属的平台测量关系，拒绝清理。':'平台同单测量、核价和两次预约的归属已核对。','counts'=>$counts,'blocking_relations'=>$blocking,'plan_digest'=>hash('sha256',$this->json($plan)),'instance_version'=>(int)$i['version']];
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        $i=$this->instance($publicId);if(!TestingPlatformScenario::supports((string)$i['scenario_key']))return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        $this->testing();
        $media=$this->db->fetchAll('SELECT storage_key_snapshot FROM test_media_facts WHERE instance_id=:id',['id'=>(int)$i['id']]);
        $result=$this->db->transaction(function(Database $db)use($publicId,$i,$adminId,$expectedPlanDigest):array{
            $db->fetch('SELECT id FROM test_instances WHERE id=:id FOR UPDATE',['id'=>(int)$i['id']]);
            $db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE',['id'=>(int)$i['order_id']]);
            $p=$this->cleanupPreview($publicId);
            if(empty($p['allowed']))throw new TestCenterException((string)$p['reason'],'TEST_CLEANUP_BLOCKED',409,$p);
            if(!hash_equals((string)$p['plan_digest'],$expectedPlanDigest))throw new TestCenterException('清理预览已变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409);
            $id=(int)$i['order_id'];$instance=(int)$i['id'];$workerId=(int)$db->fetchValue('SELECT assigned_worker_id FROM orders WHERE id=:id',['id'=>$id]);
            $db->execute("UPDATE test_instances SET build_status='cleaning' WHERE id=:id",['id'=>$instance]);
            foreach($this->notifications($id)as$n)$db->execute('DELETE FROM notifications WHERE id=:id',['id'=>(int)$n['id']]);
            $db->execute('DELETE ml FROM media_links ml JOIN test_media_facts tm ON tm.media_file_id=ml.media_file_id WHERE tm.instance_id=:id',['id'=>$instance]);
            $mediaIds=array_map('intval',array_column($db->fetchAll('SELECT media_file_id FROM test_media_facts WHERE instance_id=:id',['id'=>$instance]),'media_file_id'));
            $db->execute('DELETE FROM test_media_facts WHERE instance_id=:id',['id'=>$instance]);
            foreach($mediaIds as$mid)$db->execute('DELETE FROM media_files WHERE id=:id',['id'=>$mid]);
            $db->execute('DELETE v FROM quote_versions v JOIN quotes q ON q.id=v.quote_id WHERE q.order_id=:id',['id'=>$id]);
            $db->execute("DELETE FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=:id",['id'=>$id]);
            $db->execute('DELETE FROM test_payment_facts WHERE instance_id=:id',['id'=>$instance]);
            // 先解除主单成交指针，再按依赖逆序删除，保留 test_runs/断言/tombstone。
            $db->execute('UPDATE orders SET selected_quote_id=NULL,current_assignment_id=NULL,commission_rule_version_id=NULL WHERE id=:id',['id'=>$id]);
            foreach(['platform_order_manual_receipts','platform_order_refund_reviews','refunds','payment_orders','completion_submissions','order_deadlines','order_pricing_items','appointment_records','assignment_history','assignments','quotes','commission_rule_versions','order_measurement_spaces','order_measurement_states','order_platform_terms','order_answers','order_addresses','order_categories','order_events']as$table)if($db->tableExists($table))$db->execute("DELETE FROM `{$table}` WHERE order_id=:id",['id'=>$id]);
            $db->execute('DELETE FROM orders WHERE id=:id',['id'=>$id]);
            if($workerId>0)$db->execute('UPDATE worker_profiles SET completed_orders=(SELECT COUNT(*) FROM orders o WHERE o.assigned_worker_id=:worker AND o.accepted_at IS NOT NULL),updated_at=UTC_TIMESTAMP() WHERE id=:id',['worker'=>$workerId,'id'=>$workerId]);
            $db->execute("UPDATE test_instances SET order_id=NULL,build_status='deleted',verification_status='pending',deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$instance]);
            $db->execute("UPDATE test_batches SET status='cleaned',cleanup_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:batch AND NOT EXISTS(SELECT 1 FROM test_instances WHERE batch_id=:batch AND order_id IS NOT NULL)",['batch'=>(int)$i['batch_id']]);
            \audit('test_center.platform_measurement_cleanup','test_instance',$instance,'清理同单平台测量/核价/施工预约事实');
            return['ok'=>true,'public_id'=>$publicId,'order_no'=>(string)$i['order_no_snapshot'],'deleted_counts'=>$p['counts'],'operator_admin_id'=>$adminId];
        });
        foreach($media as$m){$key=(string)$m['storage_key_snapshot'];if(strpos($key,'test/test-center/'.(int)$i['id'].'/')===0&&strpos($key,'..')===false)@unlink(\base_path('storage/private/'.$key));}
        return$result;
    }

    private function instance(string $public): array{$i=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id',['id'=>trim($public)]);if(!$i)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);return$i;}
    private function order(array $i): array
    {
        $o=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)($i['order_id']??0)]);
        if(!$o)throw new TestCenterException('测试订单已清理。','TEST_INSTANCE_DELETED',409);
        if((int)$o['is_demo']!==1||(string)$o['public_no']!==(string)$i['order_no_snapshot']||strpos((string)$o['public_no'],'BXDEMOHALL')===0)throw new TestCenterException('测试订单归属不符。','TEST_ORDER_BOUNDARY',409);
        return$o;
    }
    private function rows(string $table,int $id): array{if(!$this->db->tableExists($table))return[];$sort=$this->db->columnExists($table,'id')?'id':($table==='order_categories'?'order_id,category_id':'order_id');return$this->db->fetchAll("SELECT * FROM `{$table}` WHERE order_id=:id ORDER BY {$sort}",['id'=>$id]);}
    private function count(string $table,int $id,string $column='order_id'): int{return$this->db->tableExists($table)?(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=:id",['id'=>$id]):0;}
    private function events(int $id,string $type): array{return$this->db->fetchAll('SELECT * FROM order_events WHERE order_id=:id AND event_type=:type ORDER BY id',['id'=>$id,'type'=>$type]);}
    private function relationCount(int $id): int
    {
        $n=$this->db->tableExists('order_relations')?(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_relations WHERE parent_order_id=:id OR child_order_id=:id',['id'=>$id]):0;
        if($this->db->columnExists('orders','pending_measurement_order_id'))$n+=(int)$this->db->fetchValue('SELECT COUNT(*) FROM orders WHERE pending_measurement_order_id=:id',['id'=>$id]);
        return$n;
    }
    private function ledgerCount(int $id): int{return$this->db->tableExists('worker_balance_ledger')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=:id AND business_type IN ('order_measurement','platform_order','platform_order_income','platform_order_settlement')",['id'=>$id]):0;}
    private function outboxCount(int $id,string $no): int{return$this->db->tableExists('message_outbox')?(int)$this->db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(payload_json),payload_json,'{}'),'$.order_id'))=:id OR business_key LIKE :key",['id'=>(string)$id,'key'=>'%'.$no.'%']):0;}
    private function notifications(int $id): array{return$this->db->tableExists('notifications')?$this->db->fetchAll("SELECT * FROM notifications WHERE JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(data_json),data_json,'{}'),'$.order_id'))=:id ORDER BY id",['id'=>(string)$id]):[];}
    private function storeChecks(array $i,array $checks,int $adminId): array
    {
        $public=\public_id();$run=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,'verify_platform_measurement','admin','inspect',:key,:digest,:request,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public,'instance'=>(int)$i['id'],'key'=>'verify-platform:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$this->json($checks)),'request'=>\request_id(),'admin'=>$adminId]);
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>(int)$i['id']]);$failed=0;
        foreach($checks as$c){[$g,$k,$e,$passed,$actual]=$c;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,:g,:k,:status,:expected,:actual,'{}',UTC_TIMESTAMP())",['instance'=>(int)$i['id'],'run'=>$run,'g'=>$g,'k'=>$k,'status'=>$passed?'passed':'failed','expected'=>$e,'actual'=>$actual]);}
        $status=$failed===0?'passed':'failed';$this->db->execute('UPDATE test_instances SET verification_status=:status,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id',['status'=>$status,'id'=>(int)$i['id']]);
        $this->db->execute('UPDATE test_runs SET result=:status,after_summary_json=:summary,finished_at=UTC_TIMESTAMP() WHERE id=:id',['status'=>$status,'summary'=>$this->json(['assertions'=>count($checks),'failed'=>$failed]),'id'=>$run]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$public,'mode'=>'platform_measurement_flow'];
    }
    private function testing(): void{if(!TestingEnvironmentPolicy::active())throw new TestCenterException('平台测量测试只允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);}
    private function json(array $value): string{return(string)json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}

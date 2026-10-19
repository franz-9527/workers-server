<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Testing;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Persistence\OrderFormMediaRules;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/** 普通测量测试场景的起点构建、初始断言与基础清理。 */
final class DatabaseTestCenterMeasurementRepository implements TestCenterRepository
{
    private const SCENARIO='ordinary.measurement.multi_quote.waiting.zero_quotes';
    private Database $db;
    private PrivateValueCipher $cipher;
    private TestCenterRepository $inner;

    public function __construct(Database $db,PrivateValueCipher $cipher,TestCenterRepository $inner){$this->db=$db;$this->cipher=$cipher;$this->inner=$inner;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function rebuildSource(string $publicId): array{return$this->inner->rebuildSource($publicId);}

    public function instanceDetail(string $publicId): array
    {
        $detail=$this->inner->instanceDetail($publicId);
        if((string)($detail['instance']['scenario_key']??'')!==self::SCENARIO)return$detail;
        $instanceId=(int)($detail['instance']['id']??0);$orderId=(int)($detail['instance']['order_id']??0);
        if($orderId>0){
            $detail['measurement_state']=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$orderId]);
            $detail['measurement_spaces']=$this->db->fetchAll('SELECT id,public_id,name,status,sort_order FROM order_measurement_spaces WHERE order_id=:id ORDER BY sort_order,id',['id'=>$orderId]);
            $detail['measurement_relation']=$this->db->fetch("SELECT r.*,c.public_no child_order_no,c.lifecycle_status child_lifecycle_status,c.publish_status child_publish_status FROM order_relations r JOIN orders c ON c.id=r.child_order_id WHERE r.parent_order_id=:id AND r.relation_type='measurement_construction' LIMIT 1",['id'=>$orderId]);
        }
        $detail['cleanup']=$this->cleanupPreview($publicId);
        return$detail;
    }

    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array
    {
        if((string)($scenario['key']??'')!==self::SCENARIO)return$this->inner->build($scenario,$params,$adminId,$requestKey,$sourceInstanceId,$rebuiltFromInstanceId);
        $this->assertWritable();
        $digest=$this->digest(['scenario'=>$scenario['key'],'version'=>$scenario['version'],'params'=>$params,'source'=>$sourceInstanceId,'rebuilt'=>$rebuiltFromInstanceId]);
        $existing=$this->db->fetch('SELECT id,request_digest FROM test_batches WHERE created_by_admin_id=:admin AND create_request_key=:request LIMIT 1',['admin'=>$adminId,'request'=>$requestKey]);
        if($existing){
            if(!hash_equals((string)$existing['request_digest'],$digest))throw new TestCenterException('同一创建请求键已经用于不同参数，请刷新后重试。','TEST_REQUEST_IDEMPOTENCY_CONFLICT',409);
            $row=$this->db->fetch('SELECT public_id FROM test_instances WHERE batch_id=:batch ORDER BY id LIMIT 1',['batch'=>(int)$existing['id']]);
            if($row)return$this->instanceDetail((string)$row['public_id']);
        }
        $transient=0;
        try{
            $created=$this->db->transaction(function(Database $db)use($scenario,$params,$adminId,$requestKey,$digest,$sourceInstanceId,$rebuiltFromInstanceId,&$transient):array{
                $batchId=$db->insert("INSERT INTO test_batches(public_id,create_request_key,request_digest,code_sha,app_version,environment,status,created_by_admin_id,created_at,updated_at) VALUES(:public_id,:request,:digest,:sha,:version,'testing','active',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\public_id(),'request'=>$requestKey,'digest'=>$digest,'sha'=>$this->codeSha(),'version'=>(string)\app_config('app.version',\app_config('version','2.6.0')),'admin'=>$adminId]);
                $paramsJson=$this->json($params);$public=\public_id();
                $instanceId=$db->insert("INSERT INTO test_instances(public_id,batch_id,scenario_key,scenario_version,params_json,params_digest,build_mode,build_status,verification_status,source_instance_id,rebuilt_from_instance_id,created_by_admin_id,created_at,updated_at) VALUES(:public_id,:batch,:scenario,:scenario_version,:params,:digest,'preset','building','pending',:source_id,:rebuilt_id,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>$public,'batch'=>$batchId,'scenario'=>self::SCENARIO,'scenario_version'=>(int)$scenario['version'],'params'=>$paramsJson,'digest'=>hash('sha256',$paramsJson),'source_id'=>$sourceInstanceId,'rebuilt_id'=>$rebuiltFromInstanceId,'admin'=>$adminId]);
                $transient=$instanceId;
                $order=(new TestingOrdinaryWaitingQuoteBuilder($db,$this->cipher))->build($instanceId,$scenario,$params,$adminId,$public);
                $db->execute("UPDATE test_instances SET order_id=:order_id,order_no_snapshot=:order_no,build_status='built',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['order_id'=>$order['id'],'order_no'=>$order['public_no'],'id'=>$instanceId]);
                return['public_id'=>$public];
            });
        }catch(\Throwable $exception){if($transient>0)$this->cleanupTransientMediaDirectory($transient);throw$exception;}
        $this->verify((string)$created['public_id'],$adminId);
        return$this->instanceDetail((string)$created['public_id']);
    }

    public function verify(string $publicId,int $adminId): array
    {
        $instance=$this->instance($publicId,false);if((string)$instance['scenario_key']!==self::SCENARIO)return$this->inner->verify($publicId,$adminId);
        if(empty($instance['order_id']))throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);
        $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order)throw new TestCenterException('测试订单已经不存在。','TEST_ORDER_NOT_FOUND',404);
        $state=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:id',['id'=>$orderId]);
        $payment=$this->db->fetch("SELECT p.*,tf.simulated_provider FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND tf.business_type='order_booking' LIMIT 1",['instance'=>(int)$instance['id']]);
        $participants=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_participants WHERE instance_id=:id',['id'=>(int)$instance['id']]);
        $schemaId=(int)($order['form_schema_version_id']??0);$mediaReady=OrderFormMediaRules::readiness($this->db,$orderId,$schemaId,'measurement');
        $workflowAnswer=$this->db->fetchValue("SELECT display_value FROM order_answers WHERE order_id=:id AND BINARY field_key_snapshot=BINARY 'workflow_profile' ORDER BY id DESC LIMIT 1",['id'=>$orderId]);
        $checks=[
            ['structure','test_domain','is_demo=1',(int)($order['is_demo']??0)===1,'is_demo='.(int)($order['is_demo']??0)],
            ['structure','measurement_workflow','consumer / measurement / multi_quote',(string)$order['order_source_type']==='consumer'&&(string)$order['workflow_profile']==='measurement'&&(string)$order['dispatch_mode']==='multi_quote',implode('/',[(string)$order['order_source_type'],(string)$order['workflow_profile'],(string)$order['dispatch_mode']])],
            ['structure','workflow_answer','动态表单 workflow_profile=measurement',(string)$workflowAnswer==='measurement',(string)$workflowAnswer],
            ['funds','booking_payment_owned','TEST_INTERNAL order_booking paid',$payment&&(string)$payment['status']==='paid'&&(string)$payment['simulated_provider']==='internal_test',$payment?'status='.$payment['status'].'; provider='.$payment['simulated_provider']:'missing'],
            ['funds','no_wechat_ids','内部预约费无微信标识',$payment&&empty($payment['wechat_prepay_id'])&&empty($payment['wechat_transaction_id']),'prepay='.(string)($payment['wechat_prepay_id']??'').'; tx='.(string)($payment['wechat_transaction_id']??'')],
            ['state','measurement_editing','unpaid / editing / none',$state&&(string)$state['service_payment_status']==='unpaid'&&(string)$state['result_status']==='editing'&&(string)$state['settlement_status']==='none',$state?implode('/',[(string)$state['service_payment_status'],(string)$state['result_status'],(string)$state['settlement_status']]):'missing'],
            ['state','published_waiting','approved/published/waiting/unassigned/active',(string)$order['audit_status']==='approved'&&(string)$order['publish_status']==='published'&&in_array((string)$order['quote_status'],['waiting','quoting'],true)&&(string)$order['assignment_status']==='unassigned'&&(string)$order['lifecycle_status']==='active',implode('/',[(string)$order['audit_status'],(string)$order['publish_status'],(string)$order['quote_status'],(string)$order['assignment_status'],(string)$order['lifecycle_status']])],
            ['state','no_quotes','起点没有有效师傅报价',(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",['id'=>$orderId])===0,'active_quotes='.(int)$this->db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=:id AND status='active'",['id'=>$orderId])],
            ['participants','fixture_pool','至少 3 approved + 1 pending slot',$participants>=4,'slots='.$participants],
            ['media','form_media_ready','测量下单表单图片规则满足',!empty($mediaReady['ready']),(string)($mediaReady['reason']??'ready')],
            ['safety','not_review_sample','订单号不得进入 BXDEMOHALL 命名空间',strpos((string)$order['public_no'],'BXDEMOHALL')!==0,(string)$order['public_no']],
        ];
        return$this->storeChecks((int)$instance['id'],$checks,$adminId,'verify_measurement_preset');
    }

    public function cleanupPreview(string $publicId): array
    {
        $instance=$this->instance($publicId,false);if((string)$instance['scenario_key']!==self::SCENARIO)return$this->inner->cleanupPreview($publicId);
        $orderId=(int)($instance['order_id']??0);if($orderId<1)return['allowed'=>false,'reason'=>'实例业务数据已清理。','counts'=>[],'plan_digest'=>''];
        $order=$this->db->fetch('SELECT id,public_no,is_demo,order_source_type,workflow_profile FROM orders WHERE id=:id',['id'=>$orderId]);
        if(!$order||(int)$order['is_demo']!==1||(string)$order['order_source_type']!=='consumer'||(string)$order['workflow_profile']!=='measurement'||strpos((string)$order['public_no'],'BXDEMOHALL')===0)return['allowed'=>false,'reason'=>'目标不是普通测量测试订单。','counts'=>[],'plan_digest'=>''];
        $blocking=[];
        foreach(['assignments','quote_selections','commission_rule_versions','appointment_records','change_requests','refunds','disputes','after_sales','reviews','order_deadlines','worker_balance_ledger','order_relations','order_measurement_spaces'] as$table){
            if(!$this->db->tableExists($table))continue;
            if($table==='worker_balance_ledger')$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='order_measurement' AND business_id=:id",['id'=>$orderId]);
            elseif($table==='order_relations')$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_relations WHERE relation_type='measurement_construction' AND (parent_order_id=:id OR child_order_id=:id)",['id'=>$orderId]);
            elseif($this->db->columnExists($table,'order_id'))$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);else$count=0;
            if($count>0)$blocking[$table]=$count;
        }
        $paymentCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE order_id=:id',['id'=>$orderId]);
        $ownedPayment=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND p.order_id=:order_id AND p.wechat_prepay_id IS NULL AND p.wechat_transaction_id IS NULL',['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
        if($paymentCount!==$ownedPayment)$blocking['payment_orders_unowned']=$paymentCount-$ownedPayment;
        $counts=['orders'=>1,'payment_orders'=>$paymentCount,'test_payment_facts'=>$ownedPayment,'order_measurement_states'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_measurement_states WHERE order_id=:id',['id'=>$orderId])];
        $plan=['instance'=>(int)$instance['id'],'version'=>(int)$instance['version'],'order'=>$orderId,'counts'=>$counts,'blocking'=>$blocking];
        return['allowed'=>empty($blocking),'reason'=>empty($blocking)?'普通测量起点仅含当前实例可证明归属的预约费、表单和主档事实。':'测量流程已进入成交/履约/关联阶段，需由测量深层清理器证明归属后清理。','counts'=>$counts,'blocking_relations'=>$blocking,'plan_digest'=>$this->digest($plan),'instance_version'=>(int)$instance['version']];
    }

    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
    {
        $instance=$this->instance($publicId,false);if((string)$instance['scenario_key']!==self::SCENARIO)return$this->inner->cleanup($publicId,$adminId,$expectedPlanDigest);
        $preview=$this->cleanupPreview($publicId);if(empty($preview['allowed']))throw new TestCenterException((string)$preview['reason'],'TEST_CLEANUP_BLOCKED',409,$preview);
        if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('清理范围在预览后发生变化，请重新预览。','TEST_CLEANUP_PREVIEW_STALE',409);
        $orderId=(int)$instance['order_id'];$instanceId=(int)$instance['id'];$orderNo=(string)$instance['order_no_snapshot'];
        $mediaRows=$this->db->fetchAll('SELECT media_file_id,storage_key_snapshot FROM test_media_facts WHERE instance_id=:id',['id'=>$instanceId]);
        $result=$this->db->transaction(function(Database $db)use($instance,$instanceId,$orderId,$orderNo,$adminId):array{
            $order=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);if(!$order||(int)$order['is_demo']!==1||(string)$order['workflow_profile']!=='measurement')throw new TestCenterException('最终测量清理边界校验失败。','TEST_CLEANUP_SAFETY_REJECTED',409);
            if($db->tableExists('quote_versions'))$db->execute('DELETE qv FROM quote_versions qv JOIN quotes q ON q.id=qv.quote_id WHERE q.order_id=:id',['id'=>$orderId]);
            if($db->tableExists('quotes'))$db->execute('DELETE FROM quotes WHERE order_id=:id',['id'=>$orderId]);
            $db->execute("DELETE l FROM media_links l JOIN test_media_facts tm ON tm.media_file_id=l.media_file_id AND tm.instance_id=:instance WHERE l.entity_type='order' AND l.entity_id=:order",['instance'=>$instanceId,'order'=>$orderId]);
            $db->execute('DELETE mf FROM media_files mf JOIN test_media_facts tm ON tm.media_file_id=mf.id WHERE tm.instance_id=:instance',['instance'=>$instanceId]);
            $db->execute('DELETE FROM test_media_facts WHERE instance_id=:instance',['instance'=>$instanceId]);
            $db->execute('DELETE p FROM payment_orders p JOIN test_payment_facts tf ON tf.payment_order_id=p.id WHERE tf.instance_id=:instance AND p.order_id=:order',['instance'=>$instanceId,'order'=>$orderId]);
            $db->execute('DELETE FROM test_payment_facts WHERE instance_id=:instance',['instance'=>$instanceId]);
            foreach(['order_answers','order_pricing_items','order_measurement_states','order_addresses','order_categories','order_events']as$table){if($db->tableExists($table)&&$db->columnExists($table,'order_id'))$db->execute("DELETE FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);}
            $db->execute('DELETE FROM orders WHERE id=:id',['id'=>$orderId]);
            $db->execute("UPDATE test_instances SET order_id=NULL,order_no_snapshot=:no,build_status='deleted',verification_status='pending',deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['no'=>$orderNo,'id'=>$instanceId]);
            \audit('test_center.cleanup','test_instance',$instanceId,'清理普通测量测试起点 '.$orderNo);
            return['ok'=>true,'public_id'=>(string)$instance['public_id'],'order_no'=>$orderNo,'operator_admin_id'=>$adminId];
        });
        foreach($mediaRows as$media){$key=str_replace('\\','/',(string)$media['storage_key_snapshot']);if(strpos($key,'test/')!==0)continue;$path=\base_path('storage/private/'.$key);if(is_file($path))@unlink($path);}return$result;
    }

    private function storeChecks(int $instanceId,array $checks,int $adminId,string $action): array
    {
        $runPublic=\public_id();$started=microtime(true);$runId=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,:action,'admin','inspect',:idem,:digest,:request,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$runPublic,'instance'=>$instanceId,'action'=>$action,'idem'=>$action.':'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$action.'|'.$instanceId),'request'=>\request_id(),'admin'=>$adminId]);
        $this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>$instanceId]);$failed=0;
        foreach($checks as$check){[$group,$key,$expected,$passed,$actual]=$check;if(!$passed)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,created_at) VALUES(:instance,:run,:group_name,:assertion_key,:status,:expected,:actual,UTC_TIMESTAMP())",['instance'=>$instanceId,'run'=>$runId,'group_name'=>$group,'assertion_key'=>$key,'status'=>$passed?'passed':'failed','expected'=>$expected,'actual'=>$actual]);}
        $status=$failed===0?'passed':'failed';$this->db->execute("UPDATE test_instances SET verification_status=:status,build_status=IF(:failed=0,build_status,'build_invalid'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['status'=>$status,'failed'=>$failed,'id'=>$instanceId]);
        $this->db->execute("UPDATE test_runs SET result=:result,after_summary_json=:summary,duration_ms=:duration,finished_at=UTC_TIMESTAMP() WHERE id=:id",['result'=>$status,'summary'=>$this->json(['assertions'=>count($checks),'failed'=>$failed]),'duration'=>(int)round((microtime(true)-$started)*1000),'id'=>$runId]);
        return['status'=>$status,'total'=>count($checks),'failed'=>$failed,'run_public_id'=>$runPublic];
    }
    private function instance(string $publicId,bool $must): array{$row=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>trim($publicId)]);if(!$row)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);if($must&&empty($row['order_id']))throw new TestCenterException('测试实例业务数据已经清理。','TEST_INSTANCE_DELETED',409);return$row;}
    private function assertWritable(): void{if(!TestingEnvironmentPolicy::active())throw new TestCenterException('测试中心写功能仅允许 testing 环境。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);}
    private function cleanupTransientMediaDirectory(int $id): void{$dir=\base_path('storage/private/test/test-center/'.$id);if(!is_dir($dir))return;foreach(glob($dir.'/*')?:[]as$path)if(is_file($path))@unlink($path);@rmdir($dir);}
    /** @param mixed $value */ private function json($value): string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
    /** @param mixed $value */ private function digest($value): string{return hash('sha256',$this->json($value));}
    private function codeSha(): string{return substr(trim((string)\app_config('app.commit_sha','')),0,64);}
}

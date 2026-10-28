<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Testing;

use Throwable;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;

/** 普通代录 flow 场景；草稿、客户确认与付款保持三个独立业务事实。 */
final class DatabaseTestCenterProxyOrderRepository implements TestCenterRepository
{
    private Database $db;private TestCenterRepository $inner;private DatabaseTestCenterProxyOrderActionRunner $actions;
    public function __construct(Database $db,TestCenterRepository $inner,DatabaseTestCenterProxyOrderActionRunner $actions){$this->db=$db;$this->inner=$inner;$this->actions=$actions;}
    public function environmentDiagnostics(): array{return$this->inner->environmentDiagnostics();}
    public function listInstances(array $filters=[]): array{return$this->inner->listInstances($filters);}
    public function rebuildSource(string $id): array{return$this->inner->rebuildSource($id);}
    public function instanceDetail(string $id): array{return$this->inner->instanceDetail($id);}
    public function cleanupPreview(string $id): array{return$this->inner->cleanupPreview($id);}
    public function build(array $s,array $params,int $admin,string $request,?int $source=null,?int $rebuilt=null): array
    {
        if($s['key']!==DatabaseTestCenterProxyOrderActionRunner::SCENARIO)return$this->inner->build($s,$params,$admin,$request,$source,$rebuilt);
        if(!TestingEnvironmentPolicy::active()||empty($this->environmentDiagnostics()['writable']))throw new TestCenterException('普通代录测试安全预检未通过。','TEST_CENTER_PREFLIGHT_FAILED',403);
        $digest=hash('sha256',$this->json([$s['key'],$s['version'],$params,$source,$rebuilt]));
        $old=$this->db->fetch('SELECT * FROM test_batches WHERE created_by_admin_id=:admin AND create_request_key=:key',['admin'=>$admin,'key'=>$request]);
        if($old){if(!hash_equals((string)$old['request_digest'],$digest))throw new TestCenterException('创建请求键已用于其它参数。','TEST_REQUEST_IDEMPOTENCY_CONFLICT',409);$id=(string)$this->db->fetchValue('SELECT public_id FROM test_instances WHERE batch_id=:id',['id'=>(int)$old['id']]);return$this->instanceDetail($id);}
        $transient=0;
        try{$public=$this->db->transaction(function(Database $db)use($s,$params,$admin,$request,$source,$rebuilt,$digest,&$transient):string{
            $batch=$db->insert("INSERT INTO test_batches(public_id,create_request_key,request_digest,code_sha,app_version,environment,status,created_by_admin_id,created_at,updated_at) VALUES(:public,:key,:digest,:sha,'2.6.0','testing','active',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>\public_id(),'key'=>$request,'digest'=>$digest,'sha'=>(string)\app_config('app.commit_sha',''),'admin'=>$admin]);$public=\public_id();
            $instance=$db->insert("INSERT INTO test_instances(public_id,batch_id,scenario_key,scenario_version,params_json,params_digest,build_mode,build_status,verification_status,source_instance_id,rebuilt_from_instance_id,created_by_admin_id,created_at,updated_at) VALUES(:public,:batch,:scenario,:version,:params,:digest,'flow','building','pending',:source,:rebuilt,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public,'batch'=>$batch,'scenario'=>$s['key'],'version'=>$s['version'],'params'=>$this->json($params),'digest'=>hash('sha256',$this->json($params)),'source'=>$source,'rebuilt'=>$rebuilt,'admin'=>$admin]);$transient=$instance;
            foreach(['customer'=>'测试代录客户','proxy_operator'=>'测试代录管理员']as$slot=>$name){$u='01KTEST'.strtoupper(substr(hash('sha256',$public.':'.$slot),0,19));$user=$db->insert("INSERT INTO users(public_id,nickname,mobile_masked,status,created_at,updated_at) VALUES(:public,:name,'139****0001','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$u,'name'=>$name]);$db->insert("INSERT INTO test_participants(instance_id,slot_key,actor_type,user_id,expected_status,created_at) VALUES(:instance,:slot,:type,:user,'active',UTC_TIMESTAMP())",['instance'=>$instance,'slot'=>$slot,'type'=>$slot==='customer'?'user':'admin','user'=>$user]);if($slot==='customer')$customer=$u;}
            $pool=new TestingParticipantPool($db,new GlobalPrivateValueCipher());$pool->attach($instance,$pool->ensure($admin),['worker_1','worker_2','worker_3','pending_worker']);
            $schema=(int)$db->fetchValue("SELECT v.id FROM form_schema_versions v JOIN form_schemas s ON s.id=v.schema_id WHERE s.schema_key='order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1");
            $category=$db->fetch("SELECT id,name FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id LIMIT 1");if(!$schema||!$category)throw new TestCenterException('当前表单或类目不可用。','TEST_FORM_SCHEMA_MISSING',409);
            $prepared=(new TestingOrderFormFixture($db))->prepare($schema,(int)$category['id'],(string)$category['name'],30.3192,120.1506,'construction');if($prepared['unsupported'])throw new TestCenterException('当前表单存在无法安全构造的必填项。','TEST_FORM_FIXTURE_INCOMPATIBLE',409,$prepared);$answers=[];foreach($prepared['rows']as$row)$answers[$row['field_key']]=json_decode((string)$row['value'],true);
            $created=$this->actions->createDraft($instance,['client_request_id'=>'TESTPROXY_'.substr(hash('sha256',$public),0,32),'customer_ref'=>$customer,'schema_version_id'=>$schema,'answers'=>$answers,'dispatch_mode'=>'multi_quote','phone_role_confirm'=>'owner','address'=>['province'=>'浙江省','city'=>'杭州市','district'=>'拱墅区','detail'=>'测试路88号（虚构地址）','contact_name'=>'测试客户','contact_mobile'=>'13900000001','lat'=>30.3192,'lng'=>120.1506]]);
            $order=$db->fetch('SELECT id,public_no FROM orders WHERE public_no=:no',['no'=>$created['id']]);if(!$order)throw new TestCenterException('正式代录入口未产生草稿。','TEST_PROXY_DRAFT_MISSING',500);
            (new TestingMediaFixtureFactory($db))->attachRequired($instance,(int)$order['id'],$schema,$admin);
            $db->execute("UPDATE test_instances SET build_status='built',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$instance]);return$public;
        });}catch(Throwable $e){if($transient>0){$dir=\base_path('storage/private/test/test-center/'.$transient);foreach(glob($dir.'/*.png')?:[]as$file)if(is_file($file))@unlink($file);if(is_dir($dir))@rmdir($dir);}throw$e;}
        $this->verify($public,$admin);return$this->instanceDetail($public);
    }
    public function verify(string $public,int $admin): array
    {
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$public]);if(!$i||$i['scenario_key']!==DatabaseTestCenterProxyOrderActionRunner::SCENARIO)return$this->inner->verify($public,$admin);
        if(!TestingEnvironmentPolicy::active())throw new TestCenterException('仅允许 testing 验证。','TEST_CENTER_NOT_AVAILABLE_IN_PRODUCTION',403);
        $o=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]);if(!$o)throw new TestCenterException('草稿已清理。','TEST_INSTANCE_DELETED',409);
        $customer=(int)$this->db->fetchValue("SELECT user_id FROM test_participants WHERE instance_id=:id AND slot_key='customer'",['id'=>(int)$i['id']]);
        $created=$this->event((int)$o['id'],'mini_ordinary_created');$ready=$this->event((int)$o['id'],'mini_ordinary_ready');$terms=$this->event((int)$o['id'],'order_terms_accepted');
        $payments=(int)$this->db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE order_id=:id',['id'=>(int)$o['id']]);
        $checks=[['proxy_domain',(int)$o['is_demo']===1&&(int)$o['user_id']===$customer&&$o['order_source_type']==='consumer','独占测试客户的普通 demo 订单'],['admin_only_records',$created&&$created['actor_type']==='mini_admin'&&(int)$created['actor_id']!==$customer,'管理员代录事件与客户身份分离'],['customer_consent',$terms?($ready&&$terms['actor_type']==='user'&&(int)$terms['actor_id']===$customer):in_array($o['payment_status'],['unpaid','awaiting_payment'],true),'交付资料后仅客户确认协议'],['payment_after_terms',$payments===0||($terms&&$ready&&$o['payment_status']==='paid'),'协议确认后才允许内部预约费到账'],['unpaid_hidden',$o['payment_status']==='paid'||($o['publish_status']==='hidden'&&$o['audit_status']==='draft'),'未付款草稿不得审核发布']];
        if($payments>0){$owned=(int)$this->db->fetchValue("SELECT COUNT(*) FROM payment_orders p JOIN test_payment_facts tf ON tf.payment_order_id=p.id AND tf.instance_id=:instance WHERE p.order_id=:order AND p.business_type='order_booking' AND p.app_id='TEST_INTERNAL' AND p.mch_id='TEST_INTERNAL' AND p.wechat_transaction_id IS NULL AND p.wechat_prepay_id IS NULL AND p.status='paid' AND p.amount_cents=:amount AND tf.simulated_provider='internal_test'",['instance'=>(int)$i['id'],'order'=>(int)$o['id'],'amount'=>(int)$o['booking_fee_cents']]);$checks[]=['internal_payment_owned',$payments===1&&$owned===1,'预约费只能是本实例 TEST_INTERNAL 事实'];}
        $publicRun=\public_id();$run=$this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,request_id,result,created_by_admin_id,started_at,created_at) VALUES(:public,:instance,'verify_proxy','admin','inspect',:key,:digest,:request,'running',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$publicRun,'instance'=>(int)$i['id'],'key'=>'verify-proxy:'.bin2hex(random_bytes(8)),'digest'=>hash('sha256',$this->json($checks)),'request'=>\request_id(),'admin'=>$admin]);$this->db->execute('DELETE FROM test_assertion_results WHERE instance_id=:id',['id'=>(int)$i['id']]);$failed=0;
        foreach($checks as[$key,$ok,$expected]){if(!$ok)$failed++;$this->db->insert("INSERT INTO test_assertion_results(instance_id,run_id,assertion_group,assertion_key,status,expected_text,actual_text,details_json,created_at) VALUES(:instance,:run,'proxy',:key,:status,:expected,:actual,'{}',UTC_TIMESTAMP())",['instance'=>(int)$i['id'],'run'=>$run,'key'=>$key,'status'=>$ok?'passed':'failed','expected'=>$expected,'actual'=>$ok?'符合':'不符合']);}
        $status=$failed?'failed':'passed';$this->db->execute('UPDATE test_runs SET result=:status,finished_at=UTC_TIMESTAMP() WHERE id=:id',['status'=>$status,'id'=>$run]);$this->db->execute('UPDATE test_instances SET verification_status=:status,version=version+1 WHERE id=:id',['status'=>$status,'id'=>(int)$i['id']]);if($failed===0&&$o['quote_status']==='selected')return$this->inner->verify($public,$admin);return['status'=>$status,'failed'=>$failed,'total'=>count($checks),'run_public_id'=>$publicRun];
    }
    public function cleanup(string $public,int $admin,string $digest): array
    {
        $i=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$public]);if(!$i||$i['scenario_key']!==DatabaseTestCenterProxyOrderActionRunner::SCENARIO)return$this->inner->cleanup($public,$admin,$digest);
        return$this->db->transaction(function()use($public,$admin,$digest,$i):array{
            $o=$this->db->fetch('SELECT id,user_id,public_no FROM orders WHERE id=:id FOR UPDATE',['id'=>(int)$i['order_id']]);if(!$o)throw new TestCenterException('测试订单已清理。','TEST_INSTANCE_DELETED',409);
            $keys=$this->db->fetchAll("SELECT id,response_body FROM idempotency_keys WHERE actor_type='user' AND actor_id=:user AND action='orders.create'",['user'=>(int)$o['user_id']]);
            // 独占测试账号仍按返回订单号逐项匹配；不按账号整批删除其它业务请求。
            $result=$this->inner->cleanup($public,$admin,$digest);
            foreach($keys as$key){$response=json_decode((string)$key['response_body'],true);if((string)($response['id']??'')===(string)$o['public_no'])$this->db->execute('DELETE FROM idempotency_keys WHERE id=:id',['id'=>(int)$key['id']]);}
            return$result;
        });
    }
    private function event(int $order,string $key): ?array{return$this->db->fetch('SELECT * FROM order_events WHERE order_id=:id AND event_type=:key ORDER BY id LIMIT 1',['id'=>$order,'key'=>$key])?:null;}
    private function json(array $v): string{return(string)json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
}

<?php

declare(strict_types=1);

use WorkerAdmin\Contracts\TestCenterActionRunner;
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Testing\TestCenterException;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionSafetyRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAdminRefundReplayRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupSafetyRepository;

$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require_once$file;});
if(!function_exists('app_config')){function app_config(string $key,$default=null){$map=['app.env'=>'testing','app.timezone'=>'Asia/Shanghai'];return array_key_exists($key,$map)?$map[$key]:$default;}}

function v260SafetySame($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");exit(1);}fwrite(STDOUT,'[PASS] '.$label."\n");}
function v260SafetyTrue(bool $actual,string $label):void{v260SafetySame($actual,true,$label);}
function v260SafetyJson($value):string{$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}

final class V260SafetyActionStub implements TestCenterActionRunner
{
    private Database $db;public int $calls=0;
    public function __construct(Database $db){$this->db=$db;}
    public function available(string $instancePublicId):array{return['items'=>['worker_quote'=>['key'=>'worker_quote']],'message'=>'stub'];}
    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey):array
    {
        $this->calls++;$instance=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$instancePublicId]);$public='RUNSAFETY'.str_pad((string)$this->calls,8,'0',STR_PAD_LEFT);
        $this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,result,created_by_admin_id,created_at) VALUES(:public_id,:instance,:action,'worker_a','real_service',:idem,:digest,'passed',:admin,UTC_TIMESTAMP())",['public_id'=>$public,'instance'=>(int)$instance['id'],'action'=>$actionKey,'idem'=>$requestKey,'digest'=>hash('sha256',$requestKey),'admin'=>$adminId]);
        return['replayed'=>false,'run_public_id'=>$public,'result'=>'passed','operation_mode'=>'real_service'];
    }
}

final class V260AdminRefundStub implements TestCenterActionRunner
{
    private Database $db;public int $calls=0;
    public function __construct(Database $db){$this->db=$db;}
    public function available(string $instancePublicId):array{return['items'=>['admin_refund_approve'=>['key'=>'admin_refund_approve']],'message'=>'stub'];}
    public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey):array
    {
        $this->calls++;$instance=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$instancePublicId]);$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]);
        $note=mb_substr(trim((string)($input['note']??'测试中心：平台核对后同意按当前可退预约费执行退款登记')),0,800);$normalized=['note'=>$note,'amount_cents'=>5000];
        $digest=hash('sha256',v260SafetyJson(['action'=>$actionKey,'input'=>$normalized,'order_id'=>(int)$order['id'],'admin_id'=>$adminId]));$public='RUNREFUND00000001';
        $this->db->insert("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,before_summary_json,after_summary_json,result,created_by_admin_id,created_at) VALUES(:public_id,:instance,:action,'admin','real_service',:idem,:digest,:before_json,:after_json,'passed',:admin,UTC_TIMESTAMP())",['public_id'=>$public,'instance'=>(int)$instance['id'],'action'=>$actionKey,'idem'=>$requestKey,'digest'=>$digest,'before_json'=>v260SafetyJson(['facts'=>['before'=>true]]),'after_json'=>v260SafetyJson(['business'=>['ok'=>true]]),'admin'=>$adminId]);
        $this->db->insert("INSERT INTO refunds(payment_order_id,order_id,amount_cents,status,requested_by_admin_id,created_at) VALUES(1,:order_id,5000,'pending_review',:admin,UTC_TIMESTAMP())",['order_id'=>(int)$order['id'],'admin'=>$adminId]);
        return['replayed'=>false,'run_public_id'=>$public,'result'=>'passed','operation_mode'=>'real_service'];
    }
}

final class V260CleanupStub implements TestCenterRepository
{
    private Database $db;public int $cleanupCalls=0;
    public function __construct(Database $db){$this->db=$db;}
    public function environmentDiagnostics():array{return[];}public function listInstances(array $filters=[]):array{return[];}
    public function instanceDetail(string $publicId):array{$i=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$publicId]);return['instance'=>$i,'order'=>null];}
    public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{return[];}
    public function verify(string $publicId,int $adminId):array{return[];}
    public function cleanupPreview(string $publicId):array{return['allowed'=>true,'reason'=>'stub','counts'=>['orders'=>1],'blocking_relations'=>[],'plan_digest'=>hash('sha256','inner-plan')];}
    public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array{$this->cleanupCalls++;return['ok'=>true,'public_id'=>$publicId];}
    public function rebuildSource(string $publicId):array{return[];}
}

if(getenv('V260_M2_SAFETY_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V260_DB_NAME')!=='worker_test'){fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);}
$config=['host'=>(string)(getenv('V260_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V260_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V260_DB_USER')?:'root'),'password'=>(string)(getenv('V260_DB_PASSWORD')?:'root')];
$rootDb=new Database($config);v260SafetyTrue(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,'MySQL 5.7 connected');$dbName='worker_v260_m2_safety_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);$db2=new Database($config);
$ddl=[
"CREATE TABLE test_instances(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,order_id BIGINT UNSIGNED NULL,build_status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL DEFAULT 1,archived_at DATETIME NULL,deleted_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE test_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,instance_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(120) NOT NULL,actor_role VARCHAR(32) NOT NULL,operation_mode VARCHAR(24) NOT NULL,idempotency_key VARCHAR(96) NOT NULL,request_digest CHAR(64) NOT NULL,before_summary_json TEXT NULL,after_summary_json TEXT NULL,result VARCHAR(24) NOT NULL,error_code VARCHAR(80) NULL,error_message VARCHAR(1000) NULL,created_by_admin_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_test_run_instance_idem(instance_id,idempotency_key)) ENGINE=InnoDB",
"CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,is_demo TINYINT(1) NOT NULL,order_source_type VARCHAR(24) NOT NULL,workflow_profile VARCHAR(24) NOT NULL,booking_fee_cents BIGINT UNSIGNED NOT NULL,dispute_status VARCHAR(24) NOT NULL DEFAULT 'none') ENGINE=InnoDB",
"CREATE TABLE payment_orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,payer_openid VARCHAR(128) NULL,app_id VARCHAR(64) NULL,mch_id VARCHAR(64) NULL,wechat_prepay_id VARCHAR(128) NULL,wechat_transaction_id VARCHAR(128) NULL) ENGINE=InnoDB",
"CREATE TABLE test_payment_facts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,simulated_provider VARCHAR(32) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_order_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
"CREATE TABLE worker_balance_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,amount_cents BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,entry_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE commission_receivables(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB"
];foreach($ddl as$sql)$db->pdo()->exec($sql);
$db->execute("INSERT INTO orders(id,public_no,is_demo,order_source_type,workflow_profile,booking_fee_cents,dispute_status) VALUES(1,'BXTESTSAFETY01',1,'consumer','construction',5000,'none'),(2,'BXTESTREFUND02',1,'consumer','construction',5000,'none')");
$db->execute("INSERT INTO test_instances(id,public_id,order_id,build_status,version) VALUES(1,'TINSTSAFETY01',1,'built',1),(2,'TINSTREFUND02',2,'built',1)");
$db->execute("INSERT INTO payment_orders(id,public_no,order_id,business_type,status,amount_cents,payer_openid,app_id,mch_id) VALUES(1,'PTESTREFUND02',2,'order_booking','paid',5000,'TEST_INTERNAL:2:100','TEST_INTERNAL','TEST_INTERNAL')");
$db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,amount_cents,simulated_provider) VALUES(2,1,5000,'internal_test')");

$stub=new V260SafetyActionStub($db);$safe=new DatabaseTestCenterActionSafetyRunner($db,$stub);$result=$safe->run('TINSTSAFETY01','worker_quote',[],77,'safety:1');
v260SafetySame((string)$db->fetchValue("SELECT actor_role FROM test_runs WHERE public_id=:id",['id'=>$result['run_public_id']]),'worker','worker slot is normalized to canonical actor_role=worker');
$db->execute("UPDATE test_instances SET build_status='cleaning' WHERE id=1");$blocked=false;try{$safe->run('TINSTSAFETY01','worker_quote',[],77,'safety:2');}catch(TestCenterException $e){$blocked=$e->businessCode()==='TEST_ACTION_INSTANCE_NOT_WRITABLE';}v260SafetyTrue($blocked,'cleaning instance blocks new business action');
$db->execute("UPDATE test_instances SET build_status='built' WHERE id=1");$db->execute("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,result,created_by_admin_id,created_at) VALUES('RUNNING000000001',1,'worker_complete','worker','real_service','other:running',REPEAT('a',64),'running',77,UTC_TIMESTAMP())");$busy=false;try{$safe->run('TINSTSAFETY01','worker_quote',[],77,'safety:3');}catch(TestCenterException $e){$busy=$e->businessCode()==='TEST_ACTION_INSTANCE_BUSY';}v260SafetyTrue($busy,'another running action blocks new action');$db->execute("UPDATE test_runs SET result='failed' WHERE public_id='RUNNING000000001'");
$lock='test_center_instance_1';v260SafetySame((int)$db2->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$lock]),1,'second connection acquires instance lock');$locked=false;try{$safe->run('TINSTSAFETY01','worker_quote',[],77,'safety:4');}catch(TestCenterException $e){$locked=$e->businessCode()==='TEST_ACTION_INSTANCE_BUSY';}v260SafetyTrue($locked,'named lock blocks concurrent action');$db2->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lock]);

$refundStub=new V260AdminRefundStub($db);$replay=new DatabaseTestCenterAdminRefundReplayRunner($db,$refundStub);$input=['note'=>'测试中心：管理员同意退款并验证幂等重放'];$first=$replay->run('TINSTREFUND02','admin_refund_approve',$input,77,'admin-refund:1');v260SafetySame($refundStub->calls,1,'first admin refund approval reaches business runner');$stored=$db->fetchValue("SELECT before_summary_json FROM test_runs WHERE public_id=:id",['id'=>$first['run_public_id']]);v260SafetyTrue(strpos((string)$stored,'"request"')!==false,'first admin refund approval persists normalized replay request');$second=$replay->run('TINSTREFUND02','admin_refund_approve',$input,77,'admin-refund:1');v260SafetyTrue(!empty($second['replayed']),'same admin refund request replays after refund allocation changed current state');v260SafetySame($refundStub->calls,1,'idempotent replay does not invoke business runner twice');$conflict=false;try{$replay->run('TINSTREFUND02','admin_refund_approve',['note'=>'测试中心：不同说明必须冲突'],77,'admin-refund:1');}catch(TestCenterException $e){$conflict=$e->businessCode()==='TEST_ACTION_IDEMPOTENCY_CONFLICT';}v260SafetyTrue($conflict,'same admin refund request key with changed normalized input conflicts');

$cleanupStub=new V260CleanupStub($db);$cleanup=new DatabaseTestCenterCleanupSafetyRepository($db,$cleanupStub);$db->execute("INSERT INTO test_runs(public_id,instance_id,action_key,actor_role,operation_mode,idempotency_key,request_digest,result,created_by_admin_id,created_at) VALUES('RUNNINGCLEAN0001',1,'worker_complete','worker','real_service','cleanup:running',REPEAT('b',64),'running',77,UTC_TIMESTAMP())");$preview=$cleanup->cleanupPreview('TINSTSAFETY01');v260SafetySame(!empty($preview['allowed']),false,'cleanup preview is blocked while test action is running');v260SafetySame((int)($preview['blocking_relations']['test_runs_running']??0),1,'cleanup preview exposes running action blocker');$db->execute("UPDATE test_runs SET result='failed' WHERE public_id='RUNNINGCLEAN0001'");$preview=$cleanup->cleanupPreview('TINSTSAFETY01');v260SafetyTrue(!empty($preview['allowed']),'cleanup preview becomes allowed after running action finishes');v260SafetySame((int)$db2->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$lock]),1,'second connection reacquires instance lock for cleanup contention');$cleanupBusy=false;try{$cleanup->cleanup('TINSTSAFETY01',77,(string)$preview['plan_digest']);}catch(TestCenterException $e){$cleanupBusy=$e->businessCode()==='TEST_CLEANUP_INSTANCE_BUSY';}v260SafetyTrue($cleanupBusy,'named lock blocks cleanup while action lock is held');$db2->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lock]);$preview=$cleanup->cleanupPreview('TINSTSAFETY01');$cleaned=$cleanup->cleanup('TINSTSAFETY01',77,(string)$preview['plan_digest']);v260SafetyTrue(!empty($cleaned['ok']),'cleanup delegates after acquiring shared instance lock');v260SafetySame($cleanupStub->cleanupCalls,1,'cleanup inner repository executes exactly once');

$rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');echo"V2.6.0 test center M2 safety MySQL 5.7 E2E: OK\n";

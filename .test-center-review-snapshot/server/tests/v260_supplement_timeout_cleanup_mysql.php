<?php
declare(strict_types=1);

namespace {
function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
function audit(...$args):void{}
}

namespace {
use WorkerAdmin\Contracts\TestCenterRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSupplementTimeoutCleanupRepository;

$root=dirname(__DIR__,2);
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Contracts/TestCenterRepository.php';
require_once $root.'/server/app/Domain/Testing/TestCenterException.php';
require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
require_once $root.'/server/app/Infrastructure/Testing/DatabaseTestCenterSupplementTimeoutCleanupRepository.php';

function scSame($actual,$expected,string $label):void{
 if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");exit(1);}
 fwrite(STDOUT,'[PASS] '.$label."\n");
}

final class SupplementCleanupInner implements TestCenterRepository {
 private Database $db;private int $instanceId;public bool $cleanupCalled=false;
 public function __construct(Database $db,int $instanceId){$this->db=$db;$this->instanceId=$instanceId;}
 public function environmentDiagnostics():array{return[];} public function listInstances(array $filters=[]):array{return[];}
 public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{return[];}
 public function verify(string $publicId,int $adminId):array{return[];} public function rebuildSource(string $publicId):array{return[];}
 public function instanceDetail(string $publicId):array{$i=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);$o=$i?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$i['order_id']]):null;return['instance'=>$i?:[],'order'=>$o?:null];}
 public function cleanupPreview(string $publicId):array{
  $i=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);$order=(int)($i['order_id']??0);$blocking=[];
  foreach(['refunds','order_cancel_requests','order_cancel_decisions'] as $table){$n=$order>0?(int)$this->db->fetchValue('SELECT COUNT(*) FROM '.$table.' WHERE order_id=:id',['id'=>$order]):0;if($n>0)$blocking[$table]=$n;}
  $deadlines=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=:id AND deadline_type<>'quote_expiry'",['id'=>$order]);if($deadlines>0)$blocking['order_deadlines_unadapted']=$deadlines;
  return['allowed'=>empty($blocking),'reason'=>empty($blocking)?'inner clean':'business rows block','blocking_relations'=>$blocking,'counts'=>[],'plan_digest'=>empty($blocking)?'inner-clean':'inner-blocked'];
 }
 public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array{if($expectedPlanDigest!=='inner-clean')throw new \RuntimeException('unexpected digest');$this->cleanupCalled=true;return['ok'=>true,'inner_cleanup'=>true];}
}

if(getenv('V260_SUPPLEMENT_CLEANUP_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){fwrite(STDERR,"restricted\n");exit(2);}
$config=['host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V250_DB_USER')?:'root'),'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root')];
$rootDb=new Database($config);scSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'cleanup MySQL 5.7 connected');
$dbName='worker_v260_supplement_cleanup_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);$rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);
foreach([
"CREATE TABLE orders(id BIGINT PRIMARY KEY,public_no VARCHAR(80),is_demo TINYINT,order_source_type VARCHAR(24),workflow_profile VARCHAR(24)) ENGINE=InnoDB",
"CREATE TABLE test_instances(id BIGINT PRIMARY KEY,public_id VARCHAR(80),scenario_key VARCHAR(128),order_id BIGINT,order_no_snapshot VARCHAR(80),version INT) ENGINE=InnoDB",
"CREATE TABLE test_runs(id BIGINT AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT,action_key VARCHAR(80),actor_role VARCHAR(24),operation_mode VARCHAR(40),result VARCHAR(24),after_summary_json LONGTEXT) ENGINE=InnoDB",
"CREATE TABLE payment_orders(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,business_type VARCHAR(32),status VARCHAR(24),amount_cents BIGINT,app_id VARCHAR(80),mch_id VARCHAR(80),payer_openid VARCHAR(160),wechat_prepay_id VARCHAR(128),wechat_transaction_id VARCHAR(128)) ENGINE=InnoDB",
"CREATE TABLE test_payment_facts(id BIGINT AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT,payment_order_id BIGINT,simulated_provider VARCHAR(32)) ENGINE=InnoDB",
"CREATE TABLE order_deadlines(id BIGINT PRIMARY KEY,order_id BIGINT,deadline_type VARCHAR(80),status VARCHAR(24),due_at DATETIME,processed_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE order_cancel_requests(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,applicant_type VARCHAR(24),applicant_id BIGINT NULL,request_type VARCHAR(48),reason_code VARCHAR(64),status VARCHAR(24),requested_refund_cents BIGINT,closed_at DATETIME NULL) ENGINE=InnoDB",
"CREATE TABLE refunds(id BIGINT AUTO_INCREMENT PRIMARY KEY,payment_order_id BIGINT,order_id BIGINT,cancel_request_id BIGINT,amount_cents BIGINT,status VARCHAR(24),requested_by_admin_id BIGINT NULL,wechat_refund_id VARCHAR(128) NULL) ENGINE=InnoDB",
"CREATE TABLE order_cancel_decisions(id BIGINT AUTO_INCREMENT PRIMARY KEY,cancel_request_id BIGINT,order_id BIGINT,decision VARCHAR(24),responsibility VARCHAR(48),refund_amount_cents BIGINT,run_empty_fee_cents BIGINT,refund_id BIGINT NULL,decided_by BIGINT NULL) ENGINE=InnoDB"
] as $sql)$db->pdo()->exec($sql);

$db->execute("INSERT INTO orders VALUES(1,'BX-SUPP-CLEAN',1,'consumer','construction')");
$db->execute("INSERT INTO test_instances VALUES(11,'SUPP-CLEAN-11','ordinary.construction.proxy.draft',1,'BX-SUPP-CLEAN',3)");
$db->execute("INSERT INTO test_runs(instance_id,action_key,actor_role,operation_mode,result) VALUES(11,'system_supplement_timeout','system','real_service','passed')");
$pay=$db->insert("INSERT INTO payment_orders(order_id,business_type,status,amount_cents,app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id) VALUES(1,'order_booking','paid',5000,'TEST_INTERNAL','TEST_INTERNAL','TEST_INTERNAL:11:1',NULL,NULL)");
$db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(11,:p,'internal_test')",['p'=>$pay]);
$req=$db->insert("INSERT INTO order_cancel_requests(order_id,applicant_type,applicant_id,request_type,reason_code,status,requested_refund_cents,closed_at) VALUES(1,'system',NULL,'supplement_timeout','supplement_timeout','approved',5000,NULL)");
$ref=$db->insert("INSERT INTO refunds(payment_order_id,order_id,cancel_request_id,amount_cents,status,requested_by_admin_id,wechat_refund_id) VALUES(:p,1,:r,5000,'pending_review',NULL,NULL)",['p'=>$pay,'r'=>$req]);
$db->execute("INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,refund_amount_cents,run_empty_fee_cents,refund_id,decided_by) VALUES(:r,1,'approved','platform',5000,0,:f,NULL)",['r'=>$req,'f'=>$ref]);

$db->execute("INSERT INTO order_deadlines VALUES(71,1,'audit_supplement_timeout','cancelled','2026-10-18 00:00:00',UTC_TIMESTAMP()),(72,2,'audit_supplement_timeout','cancelled','2026-10-18 00:00:00',UTC_TIMESTAMP())");
$db->execute("UPDATE test_runs SET after_summary_json=:summary WHERE instance_id=11",['summary'=>json_encode(['business'=>['scope'=>'test_instance','test_instance_id'=>11,'order_id'=>1,'deadline_id'=>71,'deadline_at'=>'2026-10-18 00:00:00']])]);
$inner=new SupplementCleanupInner($db,11);$cleanup=new DatabaseTestCenterSupplementTimeoutCleanupRepository($db,$inner);$preview=$cleanup->cleanupPreview('SUPP-CLEAN-11');
scSame((bool)$preview['allowed'],true,'owned supplement timeout facts adapt out of blockers');
scSame((int)$preview['supplement_timeout_cleanup']['request_id'],$req,'preview binds exact request');
$originalDigest=$preview['plan_digest'];
$db->execute("UPDATE order_deadlines SET status='pending' WHERE id=71");
scSame($cleanup->cleanupPreview('SUPP-CLEAN-11')['allowed'],false,'pending supplement deadline cannot be erased');
$db->execute("UPDATE order_deadlines SET status='cancelled',due_at='2026-10-19 00:00:00' WHERE id=71");
scSame($cleanup->cleanupPreview('SUPP-CLEAN-11')['allowed'],false,'changed deadline must match successful action snapshot');
$db->execute("UPDATE order_deadlines SET due_at='2026-10-18 00:00:00' WHERE id=71");
$db->execute("INSERT INTO order_deadlines VALUES(73,1,'unknown_external','cancelled','2026-10-18 00:00:00',UTC_TIMESTAMP())");
scSame($cleanup->cleanupPreview('SUPP-CLEAN-11')['allowed'],false,'unknown deadline remains a cleanup blocker');
$db->execute('DELETE FROM order_deadlines WHERE id=73');
$preview=$cleanup->cleanupPreview('SUPP-CLEAN-11');
scSame($preview['plan_digest'],$originalDigest,'restored owned deadline reproduces exact preview');
$result=$cleanup->cleanup('SUPP-CLEAN-11',9,(string)$preview['plan_digest']);
scSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_deadlines WHERE id=71'),0,'owned cancelled supplement deadline removed');
scSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_deadlines WHERE id=72'),1,'neighbor deadline remains untouched');
scSame(!empty($result['inner_cleanup']),true,'owned facts deletion continues to inner cleanup');
scSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=1'),0,'owned supplement facts deleted');
scSame((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE id=:id',['id'=>$pay]),1,'internal payment preserved for downstream cleanup');

$db->execute("INSERT INTO orders VALUES(2,'BX-SUPP-WX',1,'consumer','construction')");
$db->execute("INSERT INTO test_instances VALUES(12,'SUPP-CLEAN-12','ordinary.construction.proxy.draft',2,'BX-SUPP-WX',1)");
$db->execute("INSERT INTO test_runs(instance_id,action_key,actor_role,operation_mode,result) VALUES(12,'system_supplement_timeout','system','real_service','passed')");
$pay2=$db->insert("INSERT INTO payment_orders(order_id,business_type,status,amount_cents,app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id) VALUES(2,'order_booking','paid',3000,'TEST_INTERNAL','TEST_INTERNAL','TEST_INTERNAL:12:2',NULL,NULL)");
$db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(12,:p,'internal_test')",['p'=>$pay2]);
$req2=$db->insert("INSERT INTO order_cancel_requests(order_id,applicant_type,applicant_id,request_type,reason_code,status,requested_refund_cents,closed_at) VALUES(2,'system',NULL,'supplement_timeout','supplement_timeout','approved',3000,NULL)");
$ref2=$db->insert("INSERT INTO refunds(payment_order_id,order_id,cancel_request_id,amount_cents,status,requested_by_admin_id,wechat_refund_id) VALUES(:p,2,:r,3000,'pending_review',NULL,'WX-EXTERNAL')",['p'=>$pay2,'r'=>$req2]);
$db->execute("INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,refund_amount_cents,run_empty_fee_cents,refund_id,decided_by) VALUES(:r,2,'approved','platform',3000,0,:f,NULL)",['r'=>$req2,'f'=>$ref2]);
$blocked=new DatabaseTestCenterSupplementTimeoutCleanupRepository($db,new SupplementCleanupInner($db,12));$bp=$blocked->cleanupPreview('SUPP-CLEAN-12');
scSame((bool)$bp['allowed'],false,'wechat refund marker blocks supplement timeout cleanup');
scSame(isset($bp['blocking_relations']['supplement_timeout_refund_unowned']),true,'cleanup reports external refund marker');

$rootDb->pdo()->exec('DROP DATABASE '.$dbName);
echo "V2.6.0 supplement timeout cleanup MySQL 5.7 integration: OK\n";
}

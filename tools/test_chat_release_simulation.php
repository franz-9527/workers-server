<?php
declare(strict_types=1);
// Run only in the independent test repository against its ephemeral MySQL service.
if(getenv('CHAT_RELEASE_TEST')!=='1'||basename(dirname(__DIR__))==='workers')throw new RuntimeException('Explicit isolated test repository required');
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'WorkerAdmin\\')){$p=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatSimulation;
use WorkerAdmin\Application\Chat\ChatReleaseSettings;
use WorkerAdmin\Support\SqlScriptRunner;
$env='testing';function app_config($key,$fallback=null){global $env;return ['app.env'=>$env,'app.key'=>str_repeat('k',40)][$key]??$fallback;}
function check($ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,int $status=403):void{try{$fn();}catch(RuntimeException $e){check($e->getCode()===$status,'wrong denial: '.$e->getMessage());return;}throw new RuntimeException('unexpected permission');}
$port=(int)getenv('MYSQL_PORT');check($port>0,'isolated port required');
$name='bixuan_test_release_'.bin2hex(random_bytes(5));$root=new PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$root->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try{
 $db=new Database(['host'=>'127.0.0.1','port'=>$port,'database'=>$name,'username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4']);
 foreach([
 "CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(32),is_public INT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
 "CREATE TABLE admin_users(id BIGINT PRIMARY KEY,role VARCHAR(32),status VARCHAR(12),must_change_password INT) ENGINE=InnoDB",
 "INSERT INTO admin_users VALUES(1,'super_admin','active',0),(2,'operator','active',0)",
 "CREATE TABLE users(id BIGINT PRIMARY KEY,status VARCHAR(16)) ENGINE=InnoDB",
 "INSERT INTO users VALUES(11,'active'),(22,'active'),(33,'active'),(99,'active')",
 "CREATE TABLE orders(id BIGINT PRIMARY KEY,user_id BIGINT,public_no VARCHAR(64),is_demo INT,workflow_profile VARCHAR(24)) ENGINE=InnoDB",
 "INSERT INTO orders VALUES(10,11,'BXTESTREL01',1,'construction'),(20,11,'BXTESTREL02',1,'construction')",
 "CREATE TABLE test_instances(id BIGINT PRIMARY KEY,public_id VARCHAR(64),order_id BIGINT,order_no_snapshot VARCHAR(64),build_status VARCHAR(24),archived_at DATETIME NULL,deleted_at DATETIME NULL) ENGINE=InnoDB",
 "INSERT INTO test_instances VALUES(1,'INSTANCE1',10,'BXTESTREL01','built',NULL,NULL),(2,'INSTANCE2',20,'BXTESTREL02','built',NULL,NULL)",
 "CREATE TABLE worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT,display_name VARCHAR(64),cloud_record_mode VARCHAR(24)) ENGINE=InnoDB",
 "INSERT INTO worker_profiles VALUES(1,22,'模拟师傅甲','demo_local'),(2,33,'模拟师傅乙','demo_local')",
 "CREATE TABLE test_participants(id BIGINT PRIMARY KEY,instance_id BIGINT,user_id BIGINT,worker_id BIGINT,slot_key VARCHAR(64),actor_type VARCHAR(16)) ENGINE=InnoDB",
 "INSERT INTO test_participants VALUES(1,1,22,1,'worker_a','worker'),(2,1,33,2,'worker_b','worker'),(3,2,22,1,'worker_a','worker')",
 "CREATE TABLE login_sessions(id BIGINT PRIMARY KEY,user_id BIGINT,token_hash CHAR(64),status VARCHAR(16),expires_at DATETIME,test_actor_expires_at DATETIME NULL,test_actor_user_id BIGINT NULL,test_instance_id BIGINT NULL,demo_actor_user_id BIGINT NULL) ENGINE=InnoDB"
 ] as $sql)$db->execute($sql);
 $migration=(string)file_get_contents(dirname(__DIR__).'/server/database/upgrades/2.7.1.sql');$runner=new SqlScriptRunner();$runner->run($db->pdo(),$migration,'2.7.1.sql');$runner->run($db->pdo(),$migration,'2.7.1.sql');
 $release=new ChatReleaseSettings($db);check($release->enabled(),'new release is open');denied(fn()=>$release->save(['enabled'=>'0'],2,'operator'),0);
 $release->save(['enabled'=>'0'],1,'super_admin');check(!$release->enabled(),'switch close persisted');$release->save(['enabled'=>'1'],1,'super_admin');
 foreach([['a',11,1],['b',22,1],['c',33,1],['d',22,2]] as $n=>$row){$db->execute("INSERT INTO login_sessions VALUES(:id,99,:hash,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP()+INTERVAL 10 MINUTE,:actor,:instance,NULL)",['id'=>$n+1,'hash'=>hash('sha256',str_repeat($row[0],64)),'actor'=>$row[1],'instance'=>$row[2]]);}
 $service=new ChatSimulation($db);$customer=$service->identity(str_repeat('a',64));$worker=$service->identity(str_repeat('b',64));$other=$service->identity(str_repeat('c',64));$cross=$service->identity(str_repeat('d',64));
 check($customer['role']==='customer'&&$worker['role']==='worker','test identity role');$cred=$service->credential($customer);check($cred['transport']==='simulation'&&!$cred['calls_enabled']&&$cred['access_token']==='simulation','no real credential');

 $recovery=new class implements \WorkerAdmin\Contracts\ChatRecoveryReadiness{public int $calls=0;public function ensureReady(array $config,string $environment):void{$this->calls++;throw new RuntimeException('Real Go service deliberately absent');}};
 $cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher{public function hmac(string $plainText,string $context):string{return hash('sha256',$plainText);}public function encrypt(string $plainText,string $context):?string{return $plainText;}public function decrypt(string $cipherText,string $context):string{return $cipherText;}};
 $issuer=new \WorkerAdmin\Application\Chat\ChatCredentialIssuer($db,[],'testing',$cipher,$recovery);
 check($issuer->availabilityMini(str_repeat('a',64))===['enabled'=>true,'transport'=>'simulation'],'test identity availability is simulation');
 check($issuer->exchangeMini(str_repeat('a',64))['transport']==='simulation'&&$recovery->calls===0,'simulation works with no Go service or recovery config');
 $db->execute("INSERT INTO login_sessions VALUES(9,99,:hash,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,NULL,NULL,NULL,11)",['hash'=>hash('sha256',str_repeat('e',64))]);
 check($issuer->availabilityMini(str_repeat('e',64))===['enabled'=>false,'reason'=>'demo_identity'],'generic demo does not borrow administrator chat');
 $db->execute("UPDATE login_sessions SET demo_actor_user_id=NULL WHERE id=9");
 check($issuer->availabilityMini(str_repeat('e',64))===['enabled'=>true,'transport'=>'go'],'restoring real administrator restores Go chat gate');
 denied(fn()=>$issuer->availabilityMini(str_repeat('f',64)),401);
 $release->save(['enabled'=>'0'],1,'super_admin');check(!$issuer->availabilityMini(str_repeat('a',64))['enabled'],'simulation honors global close');denied(fn()=>$issuer->exchangeMini(str_repeat('a',64)),503);$release->save(['enabled'=>'1'],1,'super_admin');

 $sent=$service->adminSend(1,'worker_a','customer','预约明天上午施工','admin:first');$again=$service->adminSend(1,'worker_a','customer','预约明天上午施工','admin:first');check($sent===$again,'admin idempotency');
 denied(fn()=>$service->adminSend(1,'worker_a','customer','changed','admin:first'),409);
 $digests=$service->execute($worker,'conversations/digests',[]);check(count($digests['items'])===1&&$digests['badge_count']===1,'worker unread digest');$cid=$digests['items'][0]['conversation_id'];
 $page=$service->execute($worker,'conversations/bootstrap',['conversation_id'=>$cid]);check($page['messages'][0]['text']==='预约明天上午施工'&&$page['viewer_actor_id']==='22','shared mini history');
 denied(fn()=>$service->execute($other,'conversations/bootstrap',['conversation_id'=>$cid]));denied(fn()=>$service->execute($cross,'conversations/bootstrap',['conversation_id'=>$cid]));
 $service->execute($worker,'conversations/read',['conversation_id'=>$cid,'cursor'=>$page['sync_cursor']]);check($service->execute($worker,'conversations/unread',['conversation_id'=>$cid])['unread_count']===0,'explicit read');
 $profiles=$service->participants($worker,[['conversation_id'=>$cid,'policy_version'=>'1']]);check(count($profiles['items'][0]['members'])===2,'bounded test participants');
 $reply=$service->execute($worker,'messages/send',['conversation_id'=>$cid,'client_message_id'=>'mini:first','message_type'=>'text','text'=>'收到，我会准时到达']);check($reply['state']==='accepted','native send response');
 $sync=$service->execute($customer,'conversations/bootstrap',['conversation_id'=>$cid]);check(count($sync['messages'])===2,'customer sees mini reply');
 denied(fn()=>$service->execute($customer,'conversations/read',['conversation_id'=>$cid,'cursor'=>$page['sync_cursor']]),400);
 $recalled=$service->execute($worker,'messages/recall',['conversation_id'=>$cid,'message_id'=>$reply['message_id']]);check($recalled['state']==='recalled'&&!isset($recalled['text']),'recall clears body');
 $prefs=$service->execute($worker,'conversations/preference',['conversation_id'=>$cid,'member_version'=>'1','field'=>'pinned','value'=>true,'expected_version'=>'0']);check($prefs['pinned']&&$prefs['pinned_version']==='1','preference update');
 denied(fn()=>$service->execute($worker,'conversations/preference',['conversation_id'=>$cid,'member_version'=>'1','field'=>'pinned','value'=>false,'expected_version'=>'0']),409);
 for($n=0;$n<105;$n++)$service->adminSend(1,'worker_a','customer','分页测试 '.$n,'page:'.$n);
 $latest=$service->execute($worker,'conversations/bootstrap',['conversation_id'=>$cid]);$history=$service->execute($worker,'messages/history',['conversation_id'=>$cid,'cursor'=>$latest['history_cursor']]);check(count($latest['messages'])===50&&count($history['messages'])===50&&$history['messages'][49]['seq']<$latest['messages'][0]['seq'],'history pagination without gaps');
 $admin=$service->adminView(1,'worker_a');check(count($admin['messages'])===50&&$admin['more'],'backend paging');
 $root->query("SELECT GET_LOCK('test_center_instance_1',0)")->fetchColumn();denied(fn()=>$service->adminSend(1,'worker_a','customer','blocked by cleanup','busy'),409);$root->query("SELECT RELEASE_LOCK('test_center_instance_1')");
 if(!is_dir(dirname(__DIR__).'/test-artifacts'))mkdir(dirname(__DIR__).'/test-artifacts');file_put_contents(dirname(__DIR__).'/test-artifacts/native-simulation.json',json_encode(['credential'=>$service->credential($worker),'digests'=>$service->execute($worker,'conversations/digests',[]),'page'=>$latest,'sent'=>$reply,'recalled'=>$recalled],JSON_THROW_ON_ERROR));
 $release->save(['enabled'=>'0'],1,'super_admin');denied(fn()=>$service->execute($worker,'messages/send',['conversation_id'=>$cid]),503);$release->save(['enabled'=>'1'],1,'super_admin');
 $env='production';denied(fn()=>$service->identity(str_repeat('a',64)));denied(fn()=>$service->adminSend(1,'worker_a','customer','blocked','blocked'));$env='testing';
 $db->execute('UPDATE login_sessions SET test_actor_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=2');denied(fn()=>$service->identity(str_repeat('b',64)));
 $service->adminSend(2,'worker_a','customer','另一个实例保留','other:1');
 $db->execute('ALTER TABLE test_instances ADD version INT NOT NULL DEFAULT 1');
 $inner=new class($db) implements \WorkerAdmin\Contracts\TestCenterRepository{
  public function __construct(private Database $db){}
  public function environmentDiagnostics():array{return [];}
  public function listInstances(array $filters=[]):array{return [];}
  public function instanceDetail(string $publicId):array{return [];}
  public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{return [];}
  public function verify(string $publicId,int $adminId):array{return [];}
  public function cleanupPreview(string $publicId):array{return ['allowed'=>true,'plan_digest'=>'base-fixture','blocking_relations'=>[]];}
  public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array{$this->db->execute("UPDATE test_instances SET order_id=NULL,deleted_at=UTC_TIMESTAMP(),build_status='deleted' WHERE public_id=:id",['id'=>$publicId]);return ['public_id'=>$publicId];}
  public function rebuildSource(string $publicId):array{return [];}
 };
 $cleanup=new \WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupSafetyRepository($db,$inner);$preview=$cleanup->cleanupPreview('INSTANCE1');$cleanup->cleanup('INSTANCE1',1,$preview['plan_digest']);
 check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_test_conversations WHERE instance_id=1')===0,'cleanup removed current conversations');
 check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_test_messages')===1&&(int)$db->fetchValue('SELECT COUNT(*) FROM chat_test_conversations WHERE instance_id=2')===1,'cleanup removed current messages and preserved other instance');
 denied(fn()=>$service->execute($customer,'conversations/digests',[]));
 check(!$db->tableExists('chat_messages')&&!$db->tableExists('message_outbox'),'no real message channel or chat tables used');
 echo "PASS release, real MySQL simulation, native payloads, identity and instance isolation, idempotency, unread/read, recall, preference CAS, history and admin pagination, production/expiry/cleanup denial\n";
}finally{$root->exec('DROP DATABASE `'.$name.'`');}

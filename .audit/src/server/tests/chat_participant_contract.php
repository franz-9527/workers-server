<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Core/Database.php';
require_once dirname(__DIR__).'/app/Contracts/PrivateValueCipher.php';
require_once dirname(__DIR__).'/app/Domain/Identity/MobileNumber.php';
require_once dirname(__DIR__).'/app/Application/Chat/ChatParticipantProfiles.php';
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Application\Chat\ChatParticipantProfiles;
final class ParticipantCipher implements PrivateValueCipher {
 public function encrypt(string $plainText,string $context): ?string { return $plainText===''?null:'fixture:'.$plainText; }
 public function decrypt(string $cipherText,string $context): string { return strpos($cipherText,'fixture:')===0?substr($cipherText,8):''; }
 public function hmac(string $plainText,string $context): string { return hash('sha256',$context.':'.$plainText); }
}
function checkProfile(bool $value,string $name): void { if(!$value)throw new RuntimeException($name); }
$cipher=new ParticipantCipher();
$dummy=new class extends Database {public function __construct(){}};
$service=new ChatParticipantProfiles($dummy,$cipher);
$person=['actor_id'=>'10202','public_id'=>'01K2FIXTUREUSER000000ABC123','mobile_ciphertext'=>'fixture:13800138000',
 'mobile_hmac'=>$cipher->hmac('13800138000','user-mobile'),'nickname'=>'自改昵称13800138000','avatar_url'=>'https://untrusted.invalid/me.jpg'];
checkProfile($service->display($person,'user')['display_name']==='用户138****8000','user masked phone');
checkProfile($service->display($person,'merchant')['display_name']==='商家138****8000','merchant masked phone');
checkProfile($service->display($person,'worker')['display_name']==='师傅ABC123','unverified worker uses user ID');
$person['real_name_status']='approved';$person['real_name_ciphertext']='fixture:王明';
checkProfile($service->display($person,'worker')['display_name']==='王明','verified worker uses approved name');
$person['real_name_status']='pending';
checkProfile($service->display($person,'worker')['display_name']==='师傅ABC123','pending name never disclosed');
$person['mobile_hmac']='invalid';
checkProfile($service->display($person,'user')['display_name']==='用户ABC123','unverified mobile fallback');
$person['mobile_hmac']=null;$person['mobile_ciphertext']=null;
checkProfile($service->display($person,'merchant')['display_name']==='商家ABC123','merchant user ID fallback');
$person['real_name_status']='approved';$person['real_name_ciphertext']='broken';
checkProfile($service->display($person,'worker')['display_name']==='师傅ABC123','broken approved cipher never uses editable nickname');
foreach(['user','merchant','worker','platform'] as $kind){
 $profile=$service->display($person,$kind);
 checkProfile($profile['avatar']===$kind&&!isset($profile['avatar_url']),'fixed avatar identity');
 checkProfile(strpos(json_encode($profile),'untrusted')===false&&strpos(json_encode($profile),'13800138000')===false,'no raw mobile/nickname/avatar');
}
foreach([[],[['conversation_id'=>'1','policy_version'=>'1','nickname'=>'hack']],array_fill(0,21,['conversation_id'=>'1','policy_version'=>'1'])] as $requests){
 try{$service->read('10202',str_repeat('a',64),$requests);throw new RuntimeException('invalid request accepted');}
 catch(RuntimeException $error){checkProfile($error->getCode()===400,'bounded display requests');}
}
echo "Participant display, masked phone, verified name, immutable avatars and request boundaries passed\n";
if(($argv[1]??'')!=='--mysql')exit;
$address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');$password=(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD');
if(strpos($address,'127.0.0.1:')!==0)exit(2);
$port=(int)substr($address,10);
$admin=new PDO('mysql:host=127.0.0.1;port='.$port,'root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='bixuan_test_participants_'.bin2hex(random_bytes(6));$admin->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try{
 $db=new Database(['host'=>'127.0.0.1','port'=>$port,'database'=>$name,'username'=>'root','password'=>$password,'charset'=>'utf8mb4']);
 $ddl=[
 "users(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(26),status VARCHAR(24),mobile_ciphertext TEXT,mobile_hmac CHAR(64))",
 "login_sessions(id BIGINT PRIMARY KEY,user_id BIGINT,token_hash CHAR(64),status VARCHAR(24),expires_at DATETIME,demo_actor_user_id BIGINT,test_actor_user_id BIGINT,test_instance_id BIGINT,test_actor_expires_at DATETIME)",
 "orders(id BIGINT PRIMARY KEY,is_demo INT,order_source_type VARCHAR(24))",
 "chat_policy_guards(scope_key VARCHAR(96) PRIMARY KEY,order_id BIGINT,policy_version BIGINT,snapshot_hash CHAR(64),snapshot_json MEDIUMTEXT)",
 "chat_conversations(id BIGINT PRIMARY KEY,scope_key VARCHAR(96),conversation_type VARCHAR(24),snapshot_hash CHAR(64),policy_version BIGINT,sync_floor_seq BIGINT,last_seq BIGINT)",
 "chat_members(conversation_id BIGINT,actor_id BIGINT,actor_kind VARCHAR(16),can_read INT,role VARCHAR(24))",
 "chat_messages(conversation_id BIGINT,sender_id BIGINT,policy_version BIGINT,seq BIGINT)",
 "chat_member_intervals(conversation_id BIGINT,actor_id BIGINT,start_version BIGINT,end_version BIGINT)",
 "assignments(id BIGINT PRIMARY KEY,worker_id BIGINT)",
 "worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT)",
 "worker_real_name_verifications(worker_id BIGINT PRIMARY KEY,status VARCHAR(24),real_name_ciphertext TEXT)",
 "chat_staff_authority_versions(user_id BIGINT PRIMARY KEY,authority_version BIGINT)"];
 foreach($ddl as $sql)$db->pdo()->exec('CREATE TABLE '.$sql.' ENGINE=InnoDB');
 foreach([['10202','01K2CUSTOMER00000000ABC123'],['10203','01K2WORKER0000000000DEF456'],['10204','01K2UNKNOWN000000000GHI789']] as $row){
  $db->execute("INSERT INTO users VALUES(:id,:public,'active','fixture:13800138000',:hmac)",['id'=>$row[0],'public'=>$row[1],'hmac'=>$cipher->hmac('13800138000','user-mobile')]);
 }
 $tokenHash=hash('sha256',str_repeat('a',64));
 $db->execute("INSERT INTO login_sessions VALUES(1,10202,:hash,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,0,0,0,NULL)",['hash'=>$tokenHash]);
 foreach(["INSERT INTO orders VALUES(401,0,'merchant')","INSERT INTO assignments VALUES(801,501)","INSERT INTO worker_profiles VALUES(501,10203)","INSERT INTO worker_real_name_verifications VALUES(501,'approved','fixture:王明')"] as $sql)$db->pdo()->exec($sql);
 $scope='order:401:assignment:801';
 $snapshot=['schema_version'=>1,'scope_key'=>$scope,'order_id'=>'401','assignment_id'=>'801','episode_key'=>'assignment:801:service','state'=>'open','can_invite'=>false,
  'members'=>[['actor_id'=>'10202','kind'=>'mini','role'=>'customer','can_read'=>true,'can_send'=>true],['actor_id'=>'10203','kind'=>'mini','role'=>'worker','can_read'=>true,'can_send'=>true]]];
 $body=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$body);
 $db->execute('INSERT INTO chat_policy_guards VALUES(:scope,401,1,:hash,:body)',['scope'=>$scope,'hash'=>$hash,'body'=>$body]);
 $db->execute("INSERT INTO chat_conversations VALUES(9,:scope,'order',:hash,1,0,10)",['scope'=>$scope,'hash'=>$hash]);
 $db->pdo()->exec("INSERT INTO chat_members VALUES(9,10202,'mini',1,'customer'),(9,10203,'mini',1,'worker'),(9,10204,'mini',0,'platform_staff')");
 $service=new ChatParticipantProfiles($db,$cipher);$requests=[['conversation_id'=>'9','policy_version'=>'1','sender_ids'=>['10204']]];
 $data=$service->read('10202',$tokenHash,$requests);$names=array_column($data['items'][0]['members'],'display_name','actor_id');
 checkProfile(($names['10202']??'')==='商家138****8000'&&($names['10203']??'')==='王明'&&!isset($names['10204']),'current authorized roster only');
 $db->pdo()->exec('INSERT INTO chat_messages VALUES(9,10204,1,1)');
 checkProfile(count($service->read('10202',$tokenHash,$requests)['items'][0]['members'])===2,'invisible historical author is hidden');
 $db->pdo()->exec('INSERT INTO chat_member_intervals VALUES(9,10202,1,NULL)');
 checkProfile(count($service->read('10202',$tokenHash,$requests)['items'][0]['members'])===3,'visible historical author bounded by interval');
 $db->pdo()->exec('UPDATE chat_conversations SET sync_floor_seq=1');
 checkProfile(count($service->read('10202',$tokenHash,$requests)['items'][0]['members'])===2,'expired history does not reveal old author');
 $db->pdo()->exec('UPDATE chat_conversations SET sync_floor_seq=0');
 checkProfile($service->read('10202',$tokenHash,[['conversation_id'=>'9','policy_version'=>'2']])['items']===[],'stale policy denied');
 $db->pdo()->exec('UPDATE chat_members SET can_read=0 WHERE actor_id=10202');
 checkProfile($service->read('10202',$tokenHash,$requests)['items']===[],'revoked membership denied');
 $db->pdo()->exec('UPDATE chat_members SET can_read=1 WHERE actor_id=10202');
 $db->pdo()->exec("UPDATE chat_policy_guards SET snapshot_hash=REPEAT('b',64)");
 checkProfile($service->read('10202',$tokenHash,$requests)['items']===[],'changed guard denied');
 $db->execute('UPDATE chat_policy_guards SET snapshot_hash=:hash',['hash'=>$hash]);
 $db->pdo()->exec("UPDATE worker_real_name_verifications SET status='pending'");
 $names=array_column($service->read('10202',$tokenHash,$requests)['items'][0]['members'],'display_name','actor_id');
 checkProfile(($names['10203']??'')==='师傅DEF456','withdrawn real-name approval falls back');
 $db->pdo()->exec("UPDATE login_sessions SET status='revoked'");
 try{$service->read('10202',$tokenHash,$requests);throw new RuntimeException('revoked login accepted');}
 catch(RuntimeException $error){checkProfile($error->getCode()===401,'revoked login denied');}
 echo "Real MySQL participant authorization, historical intervals, stale guards and approval changes passed\n";
}finally{$admin->exec('DROP DATABASE '.$name);}

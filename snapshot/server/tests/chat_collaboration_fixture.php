<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void{
 $prefix='WorkerAdmin\\';if(!str_starts_with($class,$prefix))return;
 $file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;
});
function app_config($key,$default=null){return in_array($key,['chat.order_policy_enabled','chat.collaboration_enabled'],true)?true:$default;}
function public_id():string{return strtoupper(bin2hex(random_bytes(13)));}
function private_value_hmac(string $plain,string $context):string{return hash_hmac('sha256',$context."\n".$plain,str_repeat('s24-only',8));}
function encrypt_private_value(string $plain,string $context):string{return base64_encode($context."\0".$plain);}
function decrypt_private_value(string $encrypted,string $context):string{$plain=base64_decode($encrypted,true);$prefix=$context."\0";return is_string($plain)&&str_starts_with($plain,$prefix)?substr($plain,strlen($prefix)):'';}
require_once __DIR__.'/chat_platform_contract.php';

use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatCollaboration;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;

if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1'||preg_match('/^127\\.0\\.0\\.1:([0-9]+)$/D',(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR'),$endpoint)!==1)throw new RuntimeException('Explicit loopback fixture required');
$name=(string)getenv('CHAT_POLICY_FIXTURE_DATABASE');$action=$argv[1]??'';
if(!in_array($action,['prepare','sync','invite','join','role','leave','remove','reassign','source-change','phone-change','close','profiles','self-test'],true))throw new RuntimeException('Unknown fixture action');
$config=['host'=>'127.0.0.1','port'=>(int)$endpoint[1],'database'=>$name,'username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
$owns=$action==='self-test';
if($owns){$name='bixuan_test_policy_platform_'.bin2hex(random_bytes(6));$config['database']=$name;$pdo=new PDO('mysql:host=127.0.0.1;port='.(int)$endpoint[1],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}
if(preg_match('/^bixuan_test_policy_platform_[a-z0-9]+$/D',$name)!==1)throw new RuntimeException('Isolated fixture name required');
$db=new Database($config);$service=new ChatCollaboration($db,'test',str_repeat('s24-fixture-key',4));
function s24CID(Database $db):string{return (string)$db->fetchValue("SELECT CAST(id AS CHAR) FROM chat_conversations WHERE scope_key='order:401:collaboration'");}
function s24Sync(Database $db):void{$db->transaction(static function()use($db):void{(new DatabaseOrderConversationBridge($db,true))->synchronize(401);});}
function s24Prepare(Database $db):void{
 foreach(array_merge([10204],range(10300,10320)) as $id){
  $db->execute("INSERT INTO users(id,public_id,status,created_at,updated_at) VALUES(:id,:public,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status='active'",['id'=>$id,'public'=>public_id()]);
 }
 foreach(array_merge([10201,10202,10203,10204],range(10300,10320)) as $id){
  $db->execute("INSERT INTO login_sessions(id,user_id,token_hash,status,expires_at,created_at) VALUES(:id,:id,:hash,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),status='active',expires_at=VALUES(expires_at)",['id'=>$id,'hash'=>hash('sha256','login:'.$id)]);
 }
 $mobile='13800138001';
 $db->execute('UPDATE users SET mobile_ciphertext=:cipher,mobile_hmac=:hmac WHERE id=10204',['cipher'=>encrypt_private_value($mobile,'user-mobile'),'hmac'=>private_value_hmac($mobile,'user-mobile')]);
 $db->transaction(static function()use($db,$mobile):void{
  $db->execute("UPDATE orders SET user_id=10201,order_source_type='merchant',payment_status='paid',version=version+1 WHERE id=401");
  $db->execute("INSERT INTO order_addresses(order_id,contact_mobile_ciphertext,contact_mobile_hmac,created_at,updated_at) VALUES(401,:cipher,:hmac,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE contact_mobile_ciphertext=VALUES(contact_mobile_ciphertext),contact_mobile_hmac=VALUES(contact_mobile_hmac)",['cipher'=>encrypt_private_value($mobile,'order-contact-mobile'),'hmac'=>private_value_hmac($mobile,'order-contact-mobile')]);
  (new DatabaseOrderConversationBridge($db,true))->synchronize(401);
 });
}
function s24Call(ChatCollaboration $service,string $actor,string $action,array $input):array {return $service->execute($actor,hash('sha256','login:'.$actor),$action,$input);}
function s24Token(Database $db,string $inviter='10201'):string{
 $row=$db->fetch('SELECT * FROM chat_collaboration_invites WHERE order_id=401 AND inviter_actor_id=:id',['id'=>$inviter]);
 return substr(hash_hmac('sha256',"test\n401\n".$inviter."\n".$row['generation']."\n".$row['seed'],str_repeat('s24-fixture-key',4)),0,32);
}
try{
 if($owns){
  (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');chatPlatformSeed($db);s24Prepare($db);
  // No Go process is required for PHP authority tests; a projected binding is
  // created only in this explicitly isolated fixture, never by production PHP.
  $db->execute("INSERT INTO chat_conversations(conversation_type,scope_key,order_id,assignment_id,policy_version,snapshot_hash,episode_key,state,created_at,updated_at) SELECT 'order',scope_key,order_id,NULL,policy_version,snapshot_hash,'order:401:collaboration:service',state,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM chat_policy_guards WHERE scope_key='order:401:collaboration'");
 }
 if($action==='prepare')s24Prepare($db);
 elseif($action==='sync')s24Sync($db);
 elseif($action==='invite'){
  $actor=(string)($argv[2]??'10201');$generation=(string)($db->fetchValue('SELECT CAST(generation AS CHAR) FROM chat_collaboration_invites WHERE order_id=401 AND inviter_actor_id=:id',['id'=>$actor])??'0');
  s24Call($service,$actor,'invite',['conversation_id'=>s24CID($db),'expected_generation'=>$generation]);
 }elseif($action==='join')s24Call($service,(string)($argv[2]??'10203'),'join',['token'=>s24Token($db,(string)($argv[4]??'10201')),'display_role'=>(string)($argv[3]??'designer')]);
 elseif($action==='role'){
  $actor=(string)($argv[2]??'10203');$v=(string)$db->fetchValue('SELECT CAST(display_role_version AS CHAR) FROM chat_collaboration_members WHERE order_id=401 AND actor_id=:id',['id'=>$actor]);
  s24Call($service,$actor,'role',['conversation_id'=>s24CID($db),'display_role'=>(string)($argv[3]??'merchant'),'expected_display_version'=>$v]);
 }elseif($action==='leave'||$action==='remove'){
  $target=(string)($argv[2]??'10203');$v=(string)$db->fetchValue('SELECT CAST(membership_generation AS CHAR) FROM chat_collaboration_members WHERE order_id=401 AND actor_id=:id',['id'=>$target]);
  s24Call($service,$action==='leave'?$target:'10201',$action,['conversation_id'=>s24CID($db),'expected_member_version'=>$v]+($action==='remove'?['target_actor_id'=>$target]:[]));
 }elseif($action==='source-change')$db->execute('UPDATE orders SET version=version+1 WHERE id=401');
 elseif($action==='reassign'){
  $db->execute("INSERT IGNORE INTO worker_profiles(id,user_id,public_id,display_name,verification_status,created_at,updated_at) VALUES(31,10301,:public,'新师傅','approved',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>public_id()]);
  $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accepted_at,created_at,updated_at) SELECT 1401,:public,order_id,31,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,'accepted',offered_at,accepted_at,created_at,updated_at FROM assignments WHERE id=401",['public'=>public_id()]);
  $db->transaction(static function()use($db):void{$db->execute("UPDATE orders SET current_assignment_id=1401,assigned_worker_id=31,version=version+1 WHERE id=401");(new DatabaseOrderConversationBridge($db,true))->synchronize(401);});
 }elseif($action==='phone-change'){
  $phone='13800138009';$db->execute('UPDATE users SET mobile_ciphertext=:c,mobile_hmac=:h WHERE id=10309',['c'=>encrypt_private_value($phone,'user-mobile'),'h'=>private_value_hmac($phone,'user-mobile')]);
  $db->execute('UPDATE order_addresses SET contact_mobile_ciphertext=:c,contact_mobile_hmac=:h WHERE order_id=401',['c'=>encrypt_private_value($phone,'order-contact-mobile'),'h'=>private_value_hmac($phone,'order-contact-mobile')]);
 }elseif($action==='close')$db->transaction(static function()use($db):void{$db->execute("UPDATE orders SET lifecycle_status='completed',version=version+1 WHERE id=401");(new DatabaseOrderConversationBridge($db,true))->synchronize(401);});
 elseif($action==='profiles'){
  $cid=s24CID($db);$version=(string)$db->fetchValue("SELECT CAST(policy_version AS CHAR) FROM chat_policy_guards WHERE scope_key='order:401:collaboration'");
  $profiles=(new \WorkerAdmin\Application\Chat\ChatParticipantProfiles($db,new \WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher()))->read('10201',hash('sha256','login:10201'),[['conversation_id'=>$cid,'policy_version'=>$version,'sender_ids'=>['10203']]]);
  $encoded=json_encode($profiles,JSON_THROW_ON_ERROR);if(str_contains($encoded,'13800138001')||count($profiles['items'])!==1||count($profiles['items'][0]['members'])!==4)throw new RuntimeException('group identity privacy regression');
 }
 elseif($action==='self-test'){
  $checks=0;$check=static function(bool $ok,string $name)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($name);};
  $cid=s24CID($db);$info=s24Call($service,'10201','info',['conversation_id'=>$cid]);$check(count($info['members'])===3,'merchant worker automatic owner');
  $invite=s24Call($service,'10201','invite',['conversation_id'=>$cid,'expected_generation'=>'0']);
  $joined=s24Call($service,'10203','join',['token'=>$invite['token'],'display_role'=>'merchant']);
  $check(count($joined['members'])===4,'invite joins once');
  $check(count(s24Call($service,'10203','join',['token'=>$invite['token'],'display_role'=>'owner'])['members'])===4,'join is idempotent');
  try{s24Call($service,'10203','invite',['conversation_id'=>$cid,'expected_generation'=>'0']);$check(false,'label escalated authority');}catch(RuntimeException $err){$check($err->getMessage()==='CHAT_FORBIDDEN','label does not grant invitation rights');}
  $before=$db->fetch('SELECT * FROM chat_collaboration_members WHERE order_id=401 AND actor_id=10203');
  s24Call($service,'10203','role',['conversation_id'=>$cid,'display_role'=>'team_worker','expected_display_version'=>(string)$before['display_role_version']]);
  $after=$db->fetch('SELECT * FROM chat_collaboration_members WHERE order_id=401 AND actor_id=10203');
  $check($before['membership_generation']===$after['membership_generation']&&(int)$after['display_role_version']===(int)$before['display_role_version']+1,'display changes preserve authority generation');
  $db->execute('UPDATE chat_collaboration_settings SET member_limit=3 WHERE id=1');
  $info=s24Call($service,'10201','info',['conversation_id'=>$cid]);$check(count($info['members'])===4,'lowering limit preserves members');
  try{s24Call($service,'10300','join',['token'=>$invite['token'],'display_role'=>'other']);$check(false,'group overflow');}catch(RuntimeException $err){$check($err->getMessage()==='CHAT_GROUP_FULL','only ordinary admission blocked');}
  s24Call($service,'10201','remove',['conversation_id'=>$cid,'target_actor_id'=>'10203','expected_member_version'=>(string)$after['membership_generation']]);
  try{s24Call($service,'10203','join',['token'=>$invite['token'],'display_role'=>'designer']);$check(false,'removed member reused share');}catch(RuntimeException $err){$check($err->getMessage()==='CHAT_INVITE_REGENERATE_REQUIRED','removal needs fresh invitation');}
  $next=s24Call($service,'10201','invite',['conversation_id'=>$cid,'expected_generation'=>'1']);
  try{s24Call($service,'10300','preview',['token'=>$invite['token']]);$check(false,'old token survived regeneration');}catch(RuntimeException $err){$check(in_array($err->getMessage(),['CHAT_INVITE_INVALID'],true),'old share rejected');}
  $db->execute('UPDATE chat_collaboration_settings SET member_limit=4 WHERE id=1');
  s24Call($service,'10203','join',['token'=>$next['token'],'display_role'=>'designer']);
  $last=$db->fetch('SELECT membership_generation FROM chat_collaboration_members WHERE order_id=401 AND actor_id=10203');
  $check((int)$last['membership_generation']>(int)$after['membership_generation'],'rejoin advances persisted generation');
  $directory=sys_get_temp_dir().'/s24-recovery-'.bin2hex(random_bytes(6));mkdir($directory,0700);
  $checkpoint=new \WorkerAdmin\Infrastructure\Chat\ChatRecoveryCheckpoint($db->pdo(),'test',str_repeat('k',32),$directory,str_repeat('a',64));
  $beforeRevoke=$checkpoint->facts();
  s24Call($service,'10201','revoke',['conversation_id'=>$cid,'expected_generation'=>'2']);
  try{s24Call($service,'10300','preview',['token'=>$next['token']]);$check(false,'revoked token survived');}catch(RuntimeException $err){$check($err->getMessage()==='CHAT_INVITE_INVALID','revoked share rejected');}
  $afterRevoke=$checkpoint->facts();
  $check($beforeRevoke['chat_collaboration_invites']!==$afterRevoke['chat_collaboration_invites'],'recovery fingerprints fence revoked invites');
  $check(isset($afterRevoke['chat_collaboration_members'],$afterRevoke['chat_collaboration_settings'],$afterRevoke['chat_collaboration_groups'],$afterRevoke['chat_collaboration_events']),'recovery covers complete group authority');
  rmdir($directory);
  $db->execute('UPDATE chat_collaboration_settings SET member_limit=10 WHERE id=1');
  $fresh=s24Call($service,'10201','invite',['conversation_id'=>$cid,'expected_generation'=>'2']);
  foreach(range(10300,10305) as $actor)s24Call($service,(string)$actor,'join',['token'=>$fresh['token'],'display_role'=>'other']);
  $check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_collaboration_members WHERE order_id=401 AND active=1')===10,'default ten-member boundary');
  try{s24Call($service,'10306','join',['token'=>$fresh['token'],'display_role'=>'other']);$check(false,'eleventh member admitted');}catch(RuntimeException $err){$check($err->getMessage()==='CHAT_GROUP_FULL','eleventh member rejected without consuming invitation');}
  $db->execute('UPDATE chat_collaboration_invites SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE order_id=401');
  try{s24Call($service,'10306','preview',['token'=>$fresh['token']]);$check(false,'expired share survived');}catch(RuntimeException $err){$check($err->getMessage()==='CHAT_INVITE_INVALID','expired invitation rejected');}
  echo 'S24 PHP/MySQL: '.$checks." assertions passed\n";
 }
 if(!$owns)echo "committed\n";
}finally{if($owns)$pdo->exec('DROP DATABASE IF EXISTS `'.$name.'`');}

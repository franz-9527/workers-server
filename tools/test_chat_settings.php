<?php
declare(strict_types=1);
set_error_handler(function(int $level,string $message,string $file,int $line):bool{throw new ErrorException($message,0,$level,$file,$line);});
spl_autoload_register(function(string $class):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$p=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require $p;});
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn,string $why):void{try{$fn();}catch(RuntimeException $e){return;}throw new LogicException($why);}
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($r,$p=[]):string{return '/admin/?'.http_build_query(['route'=>$r]+$p);}
function csrf(){return new class{function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatCallCredentials;
use WorkerAdmin\Application\Chat\ChatCallAdministration;
$port=(string)getenv('MYSQL_PORT');check(ctype_digit($port)&&(int)$port>0,'isolated MySQL required');
$config=['host'=>'127.0.0.1','port'=>(int)$port,'database'=>'mysql','username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4'];
$root=new Database($config);$name='test_chat_settings_'.bin2hex(random_bytes(4));$root->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');$config['database']=$name;$db=new Database($config);
$cipher=new class implements WorkerAdmin\Contracts\PrivateValueCipher{
 public bool $fail=false;
 function hmac(string $p,string $c):string{return hash_hmac('sha256',$p,$c);}
 function encrypt(string $p,string $c):?string{if($this->fail)return null;$iv=random_bytes(12);$tag='';$raw=openssl_encrypt($p,'aes-256-gcm',hash('sha256','fixture-encryption-key',true),OPENSSL_RAW_DATA,$iv,$tag,$c);return base64_encode($iv.$tag.$raw);}
 function decrypt(string $v,string $c):string{$b=base64_decode($v,true);return (string)openssl_decrypt(substr($b,28),'aes-256-gcm',hash('sha256','fixture-encryption-key',true),OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16),$c);}
};
$gateway=new class implements WorkerAdmin\Contracts\ChatInternalGateway{function eligible(string $v):bool{return false;}function smsCandidates(array $q):array{return [];}function confirmCallStopped(string $c,string $e,string $h):void{throw new LogicException('real calls forbidden');}};
try{
$db->execute("CREATE TABLE admin_users(id INT PRIMARY KEY,role VARCHAR(40),status VARCHAR(20),must_change_password INT)");
$db->execute("INSERT INTO admin_users VALUES(1,'super_admin','active',0),(2,'admin','active',0)");
$db->execute("CREATE TABLE system_settings(setting_key VARCHAR(128) PRIMARY KEY,setting_value LONGTEXT,value_type VARCHAR(20),group_name VARCHAR(40),is_public TINYINT,description TEXT,updated_by INT NULL,created_at DATETIME,updated_at DATETIME)");
$db->execute("CREATE TABLE chat_call_settings(id INT PRIMARY KEY,revision INT,config_json LONGTEXT,updated_at DATETIME(6))");
$settings=['enabled'=>false,'audio_enabled'=>true,'video_enabled'=>true,'allow_fallback'=>false,'audio_priority'=>['wechat','shengwang'],'video_priority'=>['shengwang','wechat'],'window_seconds'=>120,'stop_margin_seconds'=>30,'heartbeat_seconds'=>15,'max_active_calls'=>10];
$db->execute('INSERT INTO chat_call_settings VALUES(1,1,:json,UTC_TIMESTAMP(6))',['json'=>json_encode($settings)]);
$db->execute("CREATE TABLE chat_call_resource_pools(id BIGINT AUTO_INCREMENT PRIMARY KEY,provider VARCHAR(20),scope_key VARCHAR(128),cycle_key VARCHAR(128),revision INT,facts_json LONGTEXT,meter_json LONGTEXT,official_used BIGINT,observation_at BIGINT,updated_at DATETIME(6),reserved_units BIGINT DEFAULT 0,local_uncovered BIGINT DEFAULT 0)");
$db->execute("CREATE TABLE chat_call_sessions(id BIGINT PRIMARY KEY,state VARCHAR(20))");
$db->execute("CREATE TABLE chat_call_admin_audit(id BIGINT AUTO_INCREMENT PRIMARY KEY,admin_id INT,action VARCHAR(40),reason TEXT,details_json LONGTEXT,created_at DATETIME(6))");
$db->execute("CREATE TABLE chat_call_alerts(id INT PRIMARY KEY,acknowledged_at DATETIME(6),admin_id INT)");
$creds=new ChatCallCredentials($db,$cipher);$service=new ChatCallAdministration($db,$gateway,$creds);
check($creds->runtime()===['schema'=>1,'managed'=>false],'legacy fallback marker');
$input=['_action'=>'credentials','revision'=>'1','shengwang_app_id'=>str_repeat('a',32),'certificate'=>str_repeat('b',32),'customer_id'=>'fixture-rest-customer','customer_secret'=>'fixture-rest-password','publish_privileges_verified'=>'1'];
denied(fn()=>$service->change($input,2,'admin'),'non-super administrator saved secret');
$service->change($input,1,'super_admin');
$runtime=$creds->runtime();check($runtime['certificate']===$input['certificate']&&$runtime['customer_secret']===$input['customer_secret']&&$runtime['managed'],'encrypted credentials round trip');
$raw=$db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.calls.provider_credentials'");
foreach(['certificate','customer_id','customer_secret'] as $key)check(!str_contains($raw,$input[$key]),'plaintext stored: '.$key);
$row=$db->fetch("SELECT is_public,group_name FROM system_settings WHERE setting_key='chat.calls.provider_credentials'");check((int)$row['is_public']===0&&$row['group_name']==='chat_private','private group');
$meta=json_encode($creds->current());check(!str_contains($meta,$input['customer_secret'])&&!str_contains($meta,$input['certificate']),'form metadata leak');
$audit=$db->fetchValue('SELECT details_json FROM chat_call_admin_audit ORDER BY id DESC LIMIT 1');foreach(['certificate','customer_id','customer_secret'] as $key)check(!str_contains($audit,$input[$key]),'audit leak');
$blank=$input;foreach(['certificate','customer_id','customer_secret'] as $key)$blank[$key]='';$blank['revision']='2';$service->change($blank,1,'super_admin');
check($creds->runtime()['certificate']===$input['certificate'],'blank overwrote saved key');
denied(fn()=>$service->change($blank,1,'super_admin'),'stale revision accepted');
$blank['revision']='3';$different=$blank;$different['shengwang_app_id']=str_repeat('c',32);denied(fn()=>$service->change($different,1,'super_admin'),'new app reused old keys');
$db->execute("INSERT INTO chat_call_sessions VALUES(1,'stopping')");
denied(fn()=>$service->change($blank,1,'super_admin'),'active call rotation allowed');
$db->execute('DELETE FROM chat_call_sessions');
$db->execute("INSERT INTO chat_call_resource_pools(provider,reserved_units) VALUES('shengwang',1)");
denied(fn()=>$service->change($blank,1,'super_admin'),'reserved exposure rotation allowed');
$db->execute("UPDATE chat_call_resource_pools SET reserved_units=0,local_uncovered=1");
denied(fn()=>$service->change($blank,1,'super_admin'),'unreconciled exposure rotation allowed');
$db->execute('DELETE FROM chat_call_resource_pools');
$cipher->fail=true;$broken=$input;$broken['revision']='3';denied(fn()=>$service->change($broken,1,'super_admin'),'encryption failure saved keys');$cipher->fail=false;
check((int)$db->fetchValue('SELECT revision FROM chat_call_settings WHERE id=1')===3,'failed writes changed revision');
$service->change(['_action'=>'ack_alert','revision'=>'3','alert_id'=>'1'],1,'super_admin');
check($db->fetchValue("SELECT reason FROM chat_call_admin_audit WHERE action='ack_alert'")==='确认已查看资源提醒','routine reason automatic');
denied(fn()=>$service->change(['_action'=>'budget','revision'=>'3'],1,'super_admin'),'sensitive operation lost explanation');
$credentials=$creds->current();$current=['settings'=>['config'=>$settings,'revision'=>'3'],'pools'=>[],'alerts'=>[],'trend'=>[],'recent'=>[],'audit'=>[],'active'=>[]];$notice='';$canManage=true;
@mkdir(dirname(__DIR__).'/test-artifacts',0777,true);
foreach(['register','credentials','settings'] as $section){
 ob_start();require dirname(__DIR__).'/server/views/admin/chat-calls.php';$body=ob_get_clean();
 check(!str_contains($body,$input['certificate'])&&!str_contains($body,$input['customer_secret']),'secret rendered');
 $css=file_get_contents(dirname(__DIR__).'/server/assets/admin.css');
 $js=file_get_contents(dirname(__DIR__).'/server/assets/chat-call-settings.js');
 $html='<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.$css.'</style></head><body class="admin-body"><main style="max-width:1200px;margin:24px auto;padding:16px">'.$body.'</main><script>'.$js.'</script></body></html>';
 file_put_contents(dirname(__DIR__).'/test-artifacts/chat-'.$section.'.html',$html);
}
// Verify existing signed internal transport rejects replay and cross-environment requests.
$db->execute('CREATE TABLE chat_internal_nonces(nonce_hash CHAR(64) PRIMARY KEY,expires_at DATETIME)');
$auth=new WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth($db,'test','fixture',str_repeat('k',32));
$body='{"action":"provider_credentials"}';$headers=[];
foreach($auth->sign('/chat-calls.php',$body) as $line){[$key,$value]=explode(': ',$line,2);$headers[strtolower(substr($key,7))]=$value;}
denied(fn()=>$auth->verify('/chat-calls.php',$body,[]),'unsigned credential request accepted');
$auth->verify('/chat-calls.php',$body,$headers);
denied(fn()=>$auth->verify('/chat-calls.php',$body,$headers),'internal credential request replay accepted');
$foreign=$headers;$foreign['environment']='production';denied(fn()=>$auth->verify('/chat-calls.php',$body,$foreign),'cross-environment secret request accepted');

// Storage routine notes are optional; applying history still requires a cleaning basis.
$db->execute('CREATE TABLE chat_storage_policy(id INT PRIMARY KEY,revision INT,policy_json LONGTEXT,apply_through_id BIGINT,updated_by INT,updated_at DATETIME)');
$db->execute('CREATE TABLE chat_storage_policy_audit(revision INT,admin_id INT,policy_json LONGTEXT,apply_through_id BIGINT,reason TEXT,created_at DATETIME)');
$db->execute('CREATE TABLE chat_storage_applications(revision INT,state VARCHAR(20),last_message_id INT,errors INT,diagnostic TEXT)');
$db->execute('CREATE TABLE chat_collaboration_settings(id INT PRIMARY KEY,member_limit INT,revision INT,updated_by INT,updated_at DATETIME)');
$db->execute('INSERT INTO chat_collaboration_settings VALUES(1,10,1,NULL,UTC_TIMESTAMP())');
$db->execute('CREATE TABLE chat_collaboration_events(order_id BIGINT NULL,actor_id INT,event_type VARCHAR(40),display_role_version INT,reason TEXT,created_at DATETIME)');
$db->execute('CREATE TABLE chat_messages(id BIGINT PRIMARY KEY)');
$form=['revision'=>'1','apply_existing'=>'0','tier_enabled'=>'0','tier_images'=>'1','tier_audio'=>'1','tier_videos'=>'1','tier_age_days'=>'30','tier_idle_days'=>'1','tier_remaining_days'=>'32','tier_batch'=>'20','tier_min_kb'=>'64','video_count'=>'10','video_mb'=>'20'];
foreach(['text','image','audio','video'] as $kind){$form[$kind.'_mode']='permanent';$form[$kind.'_days']='60';}
$policy=WorkerAdmin\Application\Chat\ChatStorageSettings::fromForm($form);
$db->execute('INSERT INTO chat_storage_policy VALUES(1,1,:json,0,NULL,UTC_TIMESTAMP())',['json'=>json_encode($policy)]);
$storage=new WorkerAdmin\Application\Chat\ChatStorageSettings($db,str_repeat('fixture',8));
$preview=$storage->preview($form,1);$storage->save($form+['preview_token'=>$preview['preview_token']],1,'super_admin');
check($db->fetchValue('SELECT reason FROM chat_storage_policy_audit')==='更新新消息的保存与上传设置','new message setting still requires manual reason');
$history=$form;$history['revision']='2';$history['apply_existing']='1';$preview=$storage->preview($history,1);
denied(fn()=>$storage->save($history+['preview_token'=>$preview['preview_token']],1,'super_admin'),'history applied without cleaning basis');
$storage->saveGroupLimit(['group_revision'=>'1','member_limit'=>'15'],1,'super_admin');
check((int)$db->fetchValue('SELECT member_limit FROM chat_collaboration_settings WHERE id=1')===15,'optional group note blocked normal update');
$current=$storage->current();$preview=null;$section='policy';$canManageGroups=true;$groupOrderNo='';$groupMembers=[];
ob_start();require dirname(__DIR__).'/server/views/admin/chat-storage.php';$body=ob_get_clean();
file_put_contents(dirname(__DIR__).'/test-artifacts/chat-storage.html','<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.$css.'</style></head><body><main style="max-width:1200px;margin:24px auto;padding:16px">'.$body.'</main></body></html>');
echo "签名内部接口鉴权与防重放、新消息保存及历史清理说明、群人数上限通过\n";
$service->change(['_action'=>'credentials','revision'=>'3','clear_credentials'=>'1'],1,'super_admin');
check($creds->runtime()['managed']&&$creds->runtime()['app_id']==='','clear must disable legacy fallback');
check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_call_admin_audit')===4,'rejected actions committed audit');
echo "通话密钥加密、角色与版本校验、活动通话保护、留空保留、显式清除、审计脱敏和真实页面渲染通过\n";
}finally{$root->execute('DROP DATABASE '.$name);}

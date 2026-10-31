<?php
declare(strict_types=1);
// Execute exclusively in the independent public test repository with synthetic credentials.
if(getenv('CHAT_RUNTIME_TEST')!=='1'||basename(dirname(__DIR__,2))!=='workers-server')throw new RuntimeException('Independent test repository required');
$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{if(str_starts_with($class,'WorkerAdmin\\')){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Application\Chat\ChatGoRuntimeConfig;
use WorkerAdmin\Application\Chat\ChatMediaCallbackSettings;
use WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider;
$config=['app.env'=>'testing','app.key'=>str_repeat('fixture-app-key-',4),'app.url'=>'https://testing.invalid'];
$settings=[];$count=0;
function app_config($key,$fallback=null){global $config;if(array_key_exists($key,$config))return $config[$key];$parts=explode('.',$key);$value=$config;foreach($parts as $part){if(!is_array($value)||!array_key_exists($part,$value))return $fallback;$value=$value[$part];}return $value;}
function setting($key,$fallback=null){global $settings;return $settings[$key]??$fallback;}
function base_path(string $path=''):string{global $root;return $root.'/server'.($path!==''?'/'.$path:'');}
function check(bool $ok,string $label):void{global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS ".$label."\n";}
$port=(int)getenv('MYSQL_PORT');if(!$port)throw new RuntimeException('Ephemeral MySQL port required');
$name='bixuan_test_runtime_'.bin2hex(random_bytes(4));
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$cipher=new class implements PrivateValueCipher{
 public function hmac(string $p,string $c):string{return hash_hmac('sha256',$p,$c);}
 public function encrypt(string $p,string $c):?string{return 'fixture:'.base64_encode($c.'|'.$p);}
 public function decrypt(string $p,string $c):string{$v=base64_decode(substr($p,8),true);if(!is_string($v)||!str_starts_with($v,$c.'|'))throw new RuntimeException('bad fixture cipher');return substr($v,strlen($c)+1);}
};
try{
 $database=['host'=>'127.0.0.1','port'=>$port,'database'=>$name,'username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4'];
 $config['database']=$database;$config['chat']=['recovery_gate_file'=>$root.'/chat/bin/state/fixture-gate.json','recovery_epoch'=>str_repeat('a',64),'internal_key_env'=>'CHAT_INTERNAL_KEY','internal_key_id'=>'chat-internal-v1'];
 $db=new Database($database);
 $db->execute("CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(32),is_public INT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
 $db->execute("CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(32),status VARCHAR(12),must_change_password INT) ENGINE=InnoDB");
 $db->execute("INSERT INTO admin_users VALUES(1,'super_admin','active',0)");
 // Exercise real shipping DDL, without importing unrelated business data/triggers.
 $db->execute('SET FOREIGN_KEY_CHECKS=0');
 $sql=file_get_contents($root.'/server/database/upgrades/2.7.0.sql');
 preg_match_all('/CREATE TABLE IF NOT EXISTS\\s+`?(chat_[a-z0-9_]+)`?\\s*\\([\\s\\S]*?ENGINE=InnoDB[^;]*;/',$sql,$ddl,PREG_SET_ORDER);
 check(count($ddl)>30,'shipping chat schema discovered');
 foreach($ddl as $entry)$db->execute($entry[0]);
 $db->execute('SET FOREIGN_KEY_CHECKS=1');
 $db->execute("INSERT INTO chat_call_settings VALUES(1,1,:config,UTC_TIMESTAMP(6))",['config'=>json_encode(['enabled'=>false,'audio_enabled'=>true,'video_enabled'=>true,'stop_margin_seconds'=>30])]);
 @mkdir($root.'/chat/bin/state',0750,true);
 $signing=['configured'=>true,'public_keys'=>['fixture-v1'=>base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))]];
 $runtime=new ChatGoRuntimeConfig($db,$cipher);
 $put=static function(string $key,string $value)use($db):void{$db->execute("INSERT INTO system_settings(setting_key,setting_value) VALUES(:key,:value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",['key'=>$key,'value'=>$value]);};
 $validate=static function(array $result,string $label)use($root):void{
  check($result['config']['messages']['enabled']===true,$label.' preserves text');
  foreach($result['environment'] as $k=>$v)putenv($k.'='.$v);
  $path=$root.'/chat/bin/state/fixture-config.json';file_put_contents($path,json_encode($result['config'],JSON_THROW_ON_ERROR));chmod($path,0600);
  $pipes=[];$process=proc_open([getenv('CHATD_TEST_BINARY'),'check-config','--config',$path],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  check(proc_close($process)===0,$label.' accepted by real Go validator: '.$output);
 };
 $settings['wechat.miniapp_appid']='wx0000000000000001';
 $initial=$runtime->render($signing);$validate($initial,'missing providers');
 check(!$initial['config']['images']['enabled']&&!$initial['config']['av']['enabled']&&!$initial['config']['calls']['enabled']&&!$initial['config']['reminders']['paid_sms_enabled'],'missing optional credentials disable only optional features');
 $settings+=['wechat.miniapp_secret'=>'fixture-wechat-secret','media.storage_disk'=>'qiniu','qiniu.enabled'=>true,'qiniu.private_bucket'=>true,'qiniu.access_key'=>'fixture-qiniu-access','qiniu.secret_key'=>'fixture-qiniu-secret','qiniu.bucket'=>'fixture-private-chat','qiniu.domain'=>'https://media.example.com','qiniu.region'=>'z2','sms.enabled'=>true,'sms.provider'=>'aliyun','sms.access_key_id'=>'fixture-aliyun-access','sms.access_key_secret'=>'fixture-aliyun-secret','sms.sign_name'=>'测试签名','sms.template_codes'=>['chat_unread_nudge'=>'SMS_FIXTURE1'],'testing.sms_enabled'=>true,'testing.allowed_sms_mobiles'=>['13800000000']];
 $put('chat.go_asr_api_name','FixtureAuthorizedASR');
 $put('chat.go_python_binary',(string)getenv('CHAT_TEST_PYTHON'));
 $put('chat.go_calls_php_endpoint','https://private.example.com/chat-calls.php');
 $callback=new ChatMediaCallbackSettings($db,$cipher);
 $callbackInput=['wechat_callback_token'=>'FixtureCallbackToken1234','wechat_callback_aes_key'=>rtrim(base64_encode(str_repeat('a',32)),'='),'wechat_callback_confirmed'=>'1'];
 $callback->saveLocked($callbackInput,1,$settings['wechat.miniapp_appid'],$config['app.url']);
 $provider=new WeChatAccessTokenProvider('test',$settings['wechat.miniapp_appid'],$settings['wechat.miniapp_secret'],base_path('storage/private/wechat-tokens'),base_path('storage/private/chat-credentials'),static fn()=>['access_token'=>'fixture-token-never-sent','expires_in'=>7200],base_path('storage/private/chat-credential-rejections'));
 @mkdir(base_path('storage/private/chat-credential-rejections'),0700,true);chmod(base_path('storage/private/chat-credential-rejections'),0700);
 $provider->get();
 $credential=['schema'=>1,'managed'=>true,'app_id'=>str_repeat('1',32),'publish_privileges_verified'=>true];
 foreach(['certificate'=>str_repeat('2',32),'customer_id'=>'fixture-rest-id','customer_secret'=>'fixture-rest-secret'] as $k=>$v)$credential[$k]=$cipher->encrypt($v,'chat.calls.'.$k);
 $put('chat.calls.provider_credentials',json_encode($credential));
 $now=time();$facts=['schema_version'=>1,'provider'=>'shengwang','quota_scope_id'=>'fixture-scope','cycle_id'=>'fixture-cycle','config_revision'=>'1','unit'=>'shengwang_standard','enabled'=>true,'verified'=>true,'hard_stop_verified'=>true,'healthy'=>true,'valid_from'=>$now-60,'expires_at'=>$now+3600,'verified_at'=>$now-10,'usage_observed_at'=>$now,'max_age_seconds'=>3600,'free_total'=>'100000','budget_limit'=>'100000','official_used'=>'0','local_uncovered'=>'0','reserved'=>'0','safety_margin'=>'100','external_uncertainty'=>'0'];
 $meter=['app_id'=>str_repeat('1',32),'audio'=>true,'video'=>true];
 $db->execute("INSERT INTO chat_call_resource_pools(provider,scope_key,cycle_key,revision,facts_json,meter_json,observation_at,updated_at) VALUES('shengwang','fixture-scope','fixture-cycle',1,:facts,:meter,:now,UTC_TIMESTAMP(6))",['facts'=>json_encode($facts),'meter'=>json_encode($meter),'now'=>$now]);
 $full=$runtime->render($signing);$validate($full,'complete synthetic configuration');
 check($full['config']['images']['enabled']&&$full['config']['av']['enabled']&&$full['config']['av']['audio']&&$full['config']['av']['video']&&$full['config']['reminders']['paid_sms_enabled']&&$full['config']['calls']['enabled'],'all requested capabilities generated when genuinely ready locally');
 check($full['environment']['CHAT_WECHAT_CALLBACK_TOKEN']===$callbackInput['wechat_callback_token'],'callback uses existing configured credential');
 check(!isset($full['environment']['CHAT_SHENGWANG_CERTIFICATE'])&&$full['config']['calls']['shengwang_app_id']==='','Shengwang secrets stay in authenticated PHP provider');
 $status=$runtime->status($signing);$public=json_encode($status);
 foreach(['fixture-qiniu-secret','fixture-aliyun-secret','fixture-wechat-secret','FixtureCallbackToken1234','fixture-rest-secret',$callbackInput['wechat_callback_aes_key'],$full['environment']['CHAT_INTERNAL_KEY']] as $secret)check(!str_contains($public,$secret),'admin diagnostic contains no provider/internal secret');
 $cache=$root.'/chat/bin/state/media-preflight.json';$before=file_get_contents($cache);
 $runtime->status($signing);check(file_get_contents($cache)===$before,'admin GET does not execute or rewrite CLI probe');
 check((int)$db->fetchValue('SELECT COUNT(*) FROM chat_call_alerts')===0,'runtime diagnostics do not write resource alerts');
 check($status['images_enabled']&&$status['av_enabled']&&$status['calls_enabled'],'read-only status agrees with generated capabilities');
 $settings['qiniu.domain']='https://127.0.0.1:8443';$bad=$runtime->render($signing);$validate($bad,'invalid storage origin');
 check(!$bad['config']['images']['enabled']&&!$bad['config']['av']['enabled']&&!isset($bad['environment']['CHAT_QINIU_SECRET_KEY']),'invalid storage cannot poison Go startup or export secrets');$settings['qiniu.domain']='https://media.example.com';
 $settings['qiniu.upload_host']='https://uploads.example.com';$bad=$runtime->render($signing);check(!$bad['config']['images']['enabled'],'nonofficial upload host is rejected before Go startup');unset($settings['qiniu.upload_host']);
 $put('chat.go_ffmpeg_binary','/missing/ffmpeg');$bad=$runtime->render($signing);$validate($bad,'missing FFmpeg');check($bad['config']['images']['enabled']&&!$bad['config']['av']['enabled'],'FFmpeg missing preserves image capability');$put('chat.go_ffmpeg_binary','/usr/bin/ffmpeg');
 $put('chat.go_python_binary','/missing/python3');$bad=$runtime->render($signing);$validate($bad,'missing Python');check(!$bad['config']['images']['enabled']&&!$bad['config']['av']['enabled'],'Python missing closes media only');$put('chat.go_python_binary',(string)getenv('CHAT_TEST_PYTHON'));
 $callback->saveLocked(['wechat_callback_confirmed'=>'0'],1,$settings['wechat.miniapp_appid'],$config['app.url']);$bad=$runtime->render($signing);$validate($bad,'unconfirmed callback');check(!$bad['config']['av']['enabled']&&$bad['config']['images']['enabled']&&$bad['config']['safety']['credential_file']==='','unconfirmed callback does not inject AV credentials into text safety');
 $callback->saveLocked(['wechat_callback_confirmed'=>'1'],1,$settings['wechat.miniapp_appid'],$config['app.url']);
 check(!$callback->runtime('wx0000000000000002',$config['app.url'])['ready']&&!$callback->runtime($settings['wechat.miniapp_appid'],'https://changed.invalid')['ready'],'AppID or site changes invalidate callback confirmation');
 $provider->rejectGeneration($provider->metadata()['generation']);$bad=$runtime->render($signing);$validate($bad,'expired/revoked WeChat export');check(!$bad['config']['av']['enabled']&&$bad['config']['images']['enabled'],'missing real WeChat credential closes AV only');$provider->get();
 foreach(['sms.access_key_secret'=>'','testing.sms_enabled'=>false,'testing.allowed_sms_mobiles'=>[]] as $key=>$value){$prior=$settings[$key];$settings[$key]=$value;$bad=$runtime->render($signing);check(!$bad['config']['reminders']['paid_sms_enabled']&&$bad['config']['images']['enabled'],'SMS prerequisite missing: '.$key);$settings[$key]=$prior;}
 $put('chat.calls.provider_credentials',json_encode(['schema'=>1,'managed'=>false]));$bad=$runtime->render($signing);$validate($bad,'missing call provider');check(!$bad['config']['calls']['enabled'],'missing call credentials cannot open new calls');$put('chat.calls.provider_credentials',json_encode($credential));
 $db->execute('UPDATE chat_call_resource_pools SET official_used=100000');$bad=$runtime->render($signing);check(!$bad['config']['calls']['enabled'],'exhausted resource pool prevents call admission');$db->execute('UPDATE chat_call_resource_pools SET official_used=0');
 $db->execute('UPDATE chat_call_resource_pools SET observation_at=1');$bad=$runtime->render($signing);check(!$bad['config']['calls']['enabled'],'stale official usage prevents call admission');$db->execute('UPDATE chat_call_resource_pools SET observation_at=:now',['now'=>$now]);
 $meter['app_id']=str_repeat('3',32);$db->execute('UPDATE chat_call_resource_pools SET meter_json=:meter',['meter'=>json_encode($meter)]);$bad=$runtime->render($signing);check(!$bad['config']['calls']['enabled'],'mismatched provider app prevents call admission');
 $db->execute("INSERT INTO chat_call_sessions(id,conversation_id,caller_id,callee_id,state,revision,attempt_epoch,model_json,resource_json,caller_member_key,callee_member_key,created_at,updated_at) VALUES(1,1,1,2,'stopping',1,1,'{}','{}','a','b',:now,:again)",['now'=>$now,'again'=>$now]);
 $bad=$runtime->render($signing);$validate($bad,'existing call recovery');
 check($bad['config']['calls']['enabled']&&$bad['summary']['calls_recovery_only']&&!$bad['summary']['calls'],'stop/query bridge survives closure of new call admission');$db->execute('DELETE FROM chat_call_sessions');
 $meter['app_id']=str_repeat('1',32);$db->execute('UPDATE chat_call_resource_pools SET meter_json=:meter',['meter'=>json_encode($meter)]);
 $explicit=base64_encode(str_repeat('z',32));putenv('CHAT_INTERNAL_KEY='.$explicit);$out=$runtime->render($signing);check($out['environment']['CHAT_INTERNAL_KEY']===$explicit,'Go reuses explicit PHP internal HMAC key');
 $headers=\WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured($db)->sign('/chat-calls.php','{}');$map=[];foreach($headers as $header){[$key,$value]=explode(': ',$header,2);$map[strtolower(substr($key,7))]=$value;}
 \WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured($db)->verify('/chat-calls.php','{}',$map);check(true,'existing PHP verifier accepts runtime internal signing identity');putenv('CHAT_INTERNAL_KEY');
 $db->execute('RENAME TABLE chat_image_items TO missing_image_items');$bad=$runtime->render($signing);check(!$bad['config']['images']['enabled']&&$bad['config']['av']['enabled'],'missing image table disables only images');$db->execute('RENAME TABLE missing_image_items TO chat_image_items');
 $config['app.env']='production';$prod=$runtime->render($signing);
 check(!$prod['config']['release']['staging']&&!$prod['config']['messages']['enabled']&&!$prod['config']['images']['enabled']&&!$prod['config']['av']['enabled']&&!$prod['config']['calls']['enabled']&&!$prod['config']['reminders']['paid_sms_enabled'],'production never inherits automatic staging enablement');
 check(!isset($prod['environment']['CHAT_QINIU_SECRET_KEY'],$prod['environment']['CHAT_WECHAT_CALLBACK_TOKEN']),'production closed capabilities export no optional secrets');
 // Render the real administrator view and verify callback controls belong to the save form.
 $config['app.env']='testing';$goRuntime=$runtime->status($signing);$enabled=true;$readiness=['ready'=>true];$canManage=true;$notice='';$goStatus=[];$signing=['configured'=>false];
 function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function admin_url($route,$query=[]){return '/admin/?route='.$route;}
 function csrf(){return new class{function input(){return '<input name="csrf" value="fixture">';}};}
 ob_start();require $root.'/server/views/admin/chat-settings.php';$html=ob_get_clean();
 $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($dom);
 foreach(['wechat_callback_token','wechat_callback_aes_key','wechat_callback_confirmed'] as $field)check($xpath->query('//form[.//input[@name="_action" and @value="save_go_runtime"]]//input[@name="'.$field.'"]')->length===1,'callback field submits in protected Go settings form: '.$field);
 check(!str_contains($html,'FixtureCallbackToken1234')&&!str_contains($html,$callbackInput['wechat_callback_aes_key']),'administrator view never pre-fills callback secrets');
 echo 'PASS TOTAL '.$count."\n";
}finally{$pdo->exec('DROP DATABASE `'.$name.'`');}

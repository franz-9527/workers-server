<?php
declare(strict_types=1);
// Run only against an explicitly provided isolated MySQL fixture.
spl_autoload_register(function(string $class):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $file=dirname(__DIR__).'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($file))require $file;
});
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function app_config($key,$default=null){return ['app.key'=>str_repeat('fixture-only-',4),'app.env'=>'testing'][$key]??$default;}
function setting($key,$default=null){return $GLOBALS['fixture_settings'][$key]??$default;}
function e($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]):string{return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function csrf(){return new class{function input(){return '<input name="csrf" value="fixture">';}};}
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore as Quota;
$port=(string)getenv('MYSQL_PORT');check(ctype_digit($port)&&(int)$port>0,'explicit isolated endpoint');
$config=['host'=>'127.0.0.1','port'=>(int)$port,'database'=>'mysql','username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4'];
$admin=new Database($config);$name='test_ui_notifications_'.bin2hex(random_bytes(4));
$admin->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$name;$db=new Database($config);
try{
$db->execute('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');$db->execute('INSERT INTO users VALUES(1)');
$db->execute("CREATE TABLE system_settings(setting_key VARCHAR(128) PRIMARY KEY,setting_value LONGTEXT,value_type VARCHAR(20),group_name VARCHAR(40),is_public TINYINT,description TEXT,updated_by INT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
$binding=static fn($id)=>['template_id'=>$id,'synced_template_id'=>$id,'template_title'=>$id,'template_fields'=>['character_string1','thing2','time3','thing4'],'field_map'=>['order_no'=>'character_string1','status'=>'thing2','time'=>'time3','notice_text'=>'thing4']];
$GLOBALS['fixture_settings']=['wechat.subscribe_enabled'=>true,'wechat.subscribe_template_ids'=>['user_order_update'=>$binding('user-progress-template'),'worker_order_update'=>$binding('worker-progress-template')]];
$defs=SubscriptionCatalog::subscriptionSceneDefinitions();
check($defs['fee_adjustment']['inherited_from']==='user_order_update'&&$defs['repair_progress']['template_id']==='user-progress-template','order events reuse progress template');
check($defs['worker_repair']['template_id']==='worker-progress-template','worker events reuse worker template');
Quota::saveSubscriptionPreference($db,1,'fee_adjustment',true,'accept');
$p=Quota::subscriptionPreferences($db,1);check($p['repair_progress']['remaining_count']===1&&$p['user_order_update']['remaining_count']===1,'one authorization shared once');
Quota::markSubscriptionConsumed($db,1,'repair_progress');check(Quota::subscriptionPreferences($db,1)['fee_adjustment']['remaining_count']===0,'consume shared once');
Quota::restoreSubscriptionConsumed($db,1,'repair_progress','user-progress-template');check(Quota::subscriptionPreferences($db,1)['fee_adjustment']['remaining_count']===1,'restore shared reservation');
Quota::saveSubscriptionPreference($db,1,'order_audit',false,'reject');check(Quota::subscriptionPreferences($db,1)['fee_adjustment']['remaining_count']===1,'reject preserves prior grants');
Quota::saveSubscriptionPreference($db,1,'worker_repair',true,'accept');Quota::markSubscriptionPermissionUnavailable($db,1,'fee_adjustment');
$p=Quota::subscriptionPreferences($db,1);check($p['repair_progress']['remaining_count']===0&&$p['worker_order_update']['remaining_count']===1,'invalidate one template only');
$legacy=['user_order_update'=>['template_id'=>'user-progress-template','remaining_count'=>2,'authorization_status'=>'accept','enabled'=>true],'fee_adjustment'=>['template_id'=>'user-progress-template','remaining_count'=>3,'authorization_status'=>'accept','enabled'=>true]];
$db->execute('UPDATE system_settings SET setting_value=:value WHERE setting_key=:key',['value'=>json_encode($legacy),'key'=>'wechat.subscription.user.1']);
check(Quota::subscriptionPreferences($db,1)['repair_progress']['remaining_count']===3,'legacy duplicate counts use max not sum');
Quota::saveSubscriptionPreference($db,1,'repair_progress',true,'accept');Quota::saveSubscriptionPreference($db,1,'fee_adjustment',true,'accept');
check(Quota::subscriptionPreferences($db,1)['user_order_update']['remaining_count']===5,'serial authorizations preserve increments');
$GLOBALS['fixture_settings']['wechat.subscribe_template_ids']['user_order_update']=$binding('changed-progress-template');
check(Quota::subscriptionPreferences($db,1)['fee_adjustment']['remaining_count']===0,'new template does not inherit obsolete grants');
echo "共享模板授权、扣减、恢复、失效与旧额度兼容通过\n";

$db->execute("CREATE TABLE chat_call_settings(id INT PRIMARY KEY,revision INT,config_json LONGTEXT)");
$callConfig=['enabled'=>false,'audio_enabled'=>false,'video_enabled'=>false,'allow_fallback'=>false,'audio_priority'=>['wechat','shengwang'],'video_priority'=>['wechat','shengwang'],'window_seconds'=>60,'stop_margin_seconds'=>10,'heartbeat_seconds'=>10,'max_active_calls'=>20];
$db->execute('INSERT INTO chat_call_settings VALUES(1,1,:json)',['json'=>json_encode($callConfig)]);
$db->execute('CREATE TABLE chat_call_resource_pools(id INT PRIMARY KEY,provider VARCHAR(30),cycle_key VARCHAR(40))');
$db->execute('CREATE TABLE chat_call_alerts(id INT PRIMARY KEY,pool_id INT,threshold_percent INT,acknowledged_at DATETIME,created_at DATETIME)');
$db->execute('CREATE TABLE chat_call_resource_ledger(pool_id INT,consumed_units INT,created_at DATETIME)');
$db->execute('CREATE TABLE chat_call_sessions(id INT PRIMARY KEY,conversation_id INT,state VARCHAR(30),model_json TEXT,created_at INT,attempt_epoch INT)');
$db->execute('CREATE TABLE chat_call_admin_audit(id INT PRIMARY KEY,admin_id INT,action VARCHAR(40),reason TEXT,created_at DATETIME)');
for($i=1;$i<=25;$i++){
$db->execute("INSERT INTO chat_call_sessions VALUES(:id,77,'active','{}',1000,1)",['id'=>$i]);
$db->execute("INSERT INTO chat_call_admin_audit VALUES(:id,1,'settings','fixture',UTC_TIMESTAMP())",['id'=>$i]);
}
$gateway=new class implements WorkerAdmin\Contracts\ChatInternalGateway{function eligible(string $operation):bool{return false;}function smsCandidates(array $query):array{return [];}function confirmCallStopped(string $call,string $epoch,string $proof):void{}};
$service=new WorkerAdmin\Application\Chat\ChatCallAdministration($db,$gateway);
$first=$service->current(1);$second=$service->current(2);
check(count($first['recent'])===20&&count($second['recent'])===5&&$first['pagination']['recent']['has_more']&&!$second['pagination']['recent']['has_more'],'call records have real DB pagination');
check(!array_intersect(array_column($first['recent'],'id'),array_column($second['recent'],'id')),'call pages do not repeat');
$current=$first;$notice='';$canManage=true;
foreach(['settings','resources','register','active','history','audit'] as $section){
ob_start();require dirname(__DIR__).'/server/views/admin/chat-calls.php';$html=ob_get_clean();
check(strpos($html,'通话管理分页')!==false,'call tabs render');
if($section==='history')check(strpos($html,'最近通话记录')!==false&&strpos($html,'登记新周期免费权益')===false,'history page contains no settings forms');
if($section==='settings')check(strpos($html,'最近通话记录')===false,'settings page contains no history pile');
}
$policy=['schema'=>1,'retention'=>[],'tiering'=>['enabled'=>false,'images'=>true,'audio'=>true,'videos'=>true,'age_days'=>30,'idle_days'=>1,'min_bytes'=>65536,'remaining_days'=>32,'batch'=>20],'quota'=>['video_count'=>10,'video_bytes'=>20971520]];
foreach(['text','image','audio','video'] as $kind)$policy['retention'][$kind]=['mode'=>'permanent','days'=>0];
$db->execute('CREATE TABLE chat_storage_policy(id INT PRIMARY KEY,revision INT,policy_json LONGTEXT,apply_through_id BIGINT,updated_at DATETIME)');
$db->execute('INSERT INTO chat_storage_policy VALUES(1,25,:json,0,UTC_TIMESTAMP())',['json'=>json_encode($policy)]);
$db->execute('CREATE TABLE chat_storage_applications(revision INT PRIMARY KEY,state VARCHAR(30),last_message_id INT,errors INT,diagnostic TEXT)');
$db->execute('CREATE TABLE chat_collaboration_settings(id INT PRIMARY KEY,member_limit INT,revision INT)');
$db->execute('INSERT INTO chat_collaboration_settings VALUES(1,10,1)');
for($i=1;$i<=25;$i++)$db->execute("INSERT INTO chat_storage_applications VALUES(:revision,'done',0,0,'')",['revision'=>$i]);
$storage=new WorkerAdmin\Application\Chat\ChatStorageSettings($db,str_repeat('fixture',8));$current=$storage->current(2);
check(count($current['applications'])===5&&!$current['pagination']['has_more'],'storage history pagination');
$preview=null;$canManageGroups=true;$groupOrderNo='';$groupMembers=[];
foreach(['policy','groups','history'] as $section){ob_start();require dirname(__DIR__).'/server/views/admin/chat-storage.php';$html=ob_get_clean();check(strpos($html,'聊天存储分页')!==false,'storage tabs render');if($section==='history')check(strpos($html,'云端内容保存期')===false&&strpos($html,'视频上传预算')===false,'history page contains no policy forms');}
echo "后台通话、存储分页查询与各分页渲染通过\n";
}finally{$admin->execute('DROP DATABASE '.$name);}

final class FixtureRepository implements WorkerAdmin\Contracts\AdminConfigurationRepository{
 public bool $allowed=true;
 function pageRows(array $groups,bool $messages=false):array{return [];}function ensureInternalDefaults():void{}function categories():array{return [];}function fieldDictionary():array{return [];}function settingsForGroup(string $group):array{return [];}function saveMany(array $updates,int $adminId):void{}function set(string $key,string $value,int $adminId):void{}function userForOpenid(string $openid):int{return 1;}function runtimeConfigRows():array{return [];}function assertSendBoundary():void{if(!$this->allowed)throw new RuntimeException('external sends blocked');}
}
$repo=new FixtureRepository();
$settings=new class implements WorkerAdmin\Contracts\SettingsProvider{
 public bool $enabled=true;
 function get(string $key,$default=null){return $key==='wecom.webhook_url'?'saved-fixture-webhook':$default;}
 function bool(string $key,bool $default=false):bool{return $this->enabled;}function int(string $key,int $default=0):int{return $default;}
};
$audit=new class implements WorkerAdmin\Contracts\AdminConfigurationAudit{public array $events=[];function record(array $arguments):void{$this->events[]=$arguments;}};
$alerts=new class implements WorkerAdmin\Contracts\AdminAlertGateway{
 public int $calls=0;public int $code=0;public bool $throw=false;public array $paths=[];
 function send(string $content,?string $actionPath=null):array{$this->calls++;$this->paths[]=$actionPath;check(strpos($content,'管理员通知测试')!==false,'test marker');if($this->throw)throw new RuntimeException('fixture-secret-webhook');return ['errcode'=>$this->code];}
};
$reflection=new ReflectionClass(WorkerAdmin\Application\Messaging\AdminMessageTest::class);$test=$reflection->newInstanceWithoutConstructor();
foreach(['repo'=>$repo,'settings'=>$settings,'audit'=>$audit,'adminAlerts'=>$alerts] as $key=>$value){$property=$reflection->getProperty($key);$property->setValue($test,$value);}
foreach(['order_submitted','worker_profile_submitted','merchant_auth_submitted','withdrawal_submitted'] as $scene){$result=$test->wecomSettingsTest(['test_scene'=>$scene],1);check($result['notices'][0][0]==='success','test scene accepted');}
$before=$alerts->calls;try{$test->wecomSettingsTest(['test_scene'=>'order_submitted'],0);throw new LogicException('unauthenticated accepted');}catch(RuntimeException $error){check($alerts->calls===$before,'unauthenticated never sends');}
$settings->enabled=false;try{$test->wecomSettingsTest(['test_scene'=>'order_submitted'],1);throw new LogicException('disabled accepted');}catch(RuntimeException $error){check($alerts->calls===$before,'disabled never sends');}$settings->enabled=true;
$repo->allowed=false;try{$test->wecomSettingsTest(['test_scene'=>'order_submitted'],1);throw new LogicException('boundary ignored');}catch(RuntimeException $error){check($alerts->calls===$before,'test boundary blocks external send');}$repo->allowed=true;
$alerts->code=1;$result=$test->wecomSettingsTest(['test_scene'=>'order_submitted'],1);check($result['notices'][0][0]==='warning','provider rejection not reported success');
$alerts->throw=true;$result=$test->wecomSettingsTest(['test_scene'=>'order_submitted'],1);check(strpos(json_encode($result),'fixture-secret')===false,'provider secrets not exposed');
echo "管理员通知测试四场景、权限、开关、外发阻断及受理结果通过（全部使用模拟网关）\n";

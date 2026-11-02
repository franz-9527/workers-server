<?php
declare(strict_types=1);
set_error_handler(function(int $level,string $message,string $file,int $line):bool{throw new ErrorException($message,0,$level,$file,$line);});
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

$binding=static fn($id)=>['template_id'=>$id,'synced_template_id'=>$id,'template_title'=>$id,'template_fields'=>['character_string1','thing2','time3'],'field_map'=>['order_no'=>'character_string1','notice_text'=>'thing2','time'=>'time3']];
$templates=[];
foreach(['user_order_update','user_financial_update','worker_order_update','worker_financial_update','appointment_update','worker_review'] as $key)$templates[$key]=$binding('fixture-'.$key);
$templates['chat_unread']=['template_id'=>'fixture-chat','field_map'=>['status'=>'thing1','time'=>'time2']];
$GLOBALS['fixture_settings']=['wechat.subscribe_enabled'=>true,'wechat.subscribe_template_ids'=>$templates];
$defs=SubscriptionCatalog::subscriptionSceneDefinitions();
foreach(['user_order_update','user_financial_update','worker_order_update','worker_financial_update','chat_unread'] as $key)check($defs[$key]['important']&&$defs[$key]['available'],'important root available '.$key);
check(!$defs['appointment_update']['important']&&!$defs['worker_review']['important'],'ordinary templates optional');
check($defs['fee_adjustment']['inherited_from']==='user_financial_update','fee adjustments route to finance');
check($defs['worker_repair']['inherited_from']==='worker_order_update','worker service route');
Quota::saveSubscriptionPreference($db,1,'fee_adjustment',true,'accept');
$p=Quota::subscriptionPreferences($db,1);check($p['user_financial_update']['remaining_count']===1&&$p['user_order_update']['remaining_count']===0,'separate order and money quota');
Quota::markSubscriptionConsumed($db,1,'user_financial_update');check(Quota::subscriptionPreferences($db,1)['fee_adjustment']['remaining_count']===0,'consume only money');
Quota::restoreSubscriptionConsumed($db,1,'fee_adjustment','fixture-user_financial_update');check(Quota::subscriptionPreferences($db,1)['user_financial_update']['remaining_count']===1,'restore failed reservation');
Quota::saveSubscriptionPreference($db,1,'fee_adjustment',false,'reject');check(Quota::subscriptionPreferences($db,1)['user_financial_update']['remaining_count']===1,'reject keeps existing grants');
$provider=new WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider();
$preferences=new WorkerAdmin\Application\Messaging\SubscriptionPreferences(new WorkerAdmin\Infrastructure\Messaging\DatabaseSubscriptionRepository($db),$provider);
$customer=['user_id'=>1,'worker_id'=>0];$worker=['user_id'=>1,'worker_id'=>88];
$status=$preferences->status($customer,false);check($status['schema']===3,'role schema');
check(!array_filter($status['items'],fn($i)=>$i['audience']==='worker'),'customer cannot see worker subscriptions');
check(count(array_filter($status['items'],fn($i)=>$i['important']))===3,'customer three important templates');
$status=$preferences->status($worker,false);
foreach(['user','worker'] as $role)check(count(array_filter($status['items'],fn($i)=>$i['important']&&in_array($i['audience'],[$role,'common'],true)))===3,$role.' three important including chat');
try{$preferences->save($customer,['key'=>'worker_financial_update','enabled'=>true,'authorization_status'=>'accept']);throw new RuntimeException('permission not enforced');}catch(WorkerAdmin\Domain\Messaging\MessageOperationException $e){check(str_contains($e->getMessage(),'师傅身份'),'worker permission guarded');}
$known=['wechat.subscribe_template_ids'=>['value_type'=>'json','setting_value'=>json_encode($templates+['order_audit'=>$binding('legacy-audit')])]];
$posted=['user_financial_update'=>$binding('changed-finance')];
$updates=WorkerAdmin\Domain\Messaging\SettingsValuePolicy::validate(['wechat.subscribe_template_ids'=>json_encode($posted)],$known,'notification','user_financial_update',$defs,SubscriptionCatalog::subscriptionSemanticLabels(),SubscriptionCatalog::reviewResultFixedTemplateFields());
$normalized=json_decode($updates['wechat.subscribe_template_ids'],true);
check($normalized['user_financial_update']['template_id']==='changed-finance','new finance saved');
check($normalized['worker_financial_update']['template_id']==='fixture-worker_financial_update'&&$normalized['order_audit']['template_id']==='legacy-audit','editing one preserves others and legacy');
// Existing deployment with just a shared order template must retain the pool, not multiply grants.
$GLOBALS['fixture_settings']['wechat.subscribe_template_ids']=['order_progress'=>$binding('old-shared-order'),'chat_unread'=>$templates['chat_unread']];
$db->execute('DELETE FROM system_settings');
Quota::saveSubscriptionPreference($db,1,'order_progress',true,'accept');
$p=Quota::subscriptionPreferences($db,1);
foreach(['user_order_update','worker_order_update','user_financial_update','worker_financial_update'] as $key)check($p[$key]['remaining_count']===1,'legacy pool '.$key);
Quota::markSubscriptionConsumed($db,1,'worker_financial_update');
check(Quota::subscriptionPreferences($db,1)['user_order_update']['remaining_count']===0,'shared physical grant consumed exactly once');
$queue=WorkerAdmin\Infrastructure\Messaging\OrderSubscriptionQueue::class;
check($queue::subscriptionSceneForEvent('appointment_recorded')==='appointment_update','optional appointment wired');
check($queue::subscriptionSceneForEvent('review_submitted')==='worker_review','optional review wired');
check($queue::subscriptionSceneForEvent('change_requested')==='user_financial_update','event routes finance');
$testData=new WorkerAdmin\Application\Messaging\SubscriptionTestData($provider);
check(isset($testData->subscriptionTestEventOptions('appointment_update')['appointment_recorded']),'optional template has actual event test');
echo "Role templates, optional events, MySQL shared quota, permission and non-destructive configuration passed\n";
}finally{$admin->execute('DROP DATABASE IF EXISTS '.$name);}

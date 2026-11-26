<?php
declare(strict_types=1);
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore as Quota;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionCatalog;
use WorkerAdmin\Infrastructure\Messaging\DatabaseChatReminderChannel;
use WorkerAdmin\Domain\Messaging\WechatTemplateValue;
if(getenv('QUOTA_CROSS_SCENE_TEST')!=='1')exit(2);
spl_autoload_register(static function(string $class):void{
    $prefix='WorkerAdmin\\';
    if(str_starts_with($class,$prefix)){
        $path=__DIR__.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($path))require_once $path;
    }
});
function ensure(bool $condition,string $name):void{if(!$condition)throw new RuntimeException($name);echo "[PASS] $name\n";}
function setting($key,$default=null){return $GLOBALS['fixture_settings'][$key]??$default;}
function bindFixture(string $id,array $fields,array $map):array{return ['template_id'=>$id,'synced_template_id'=>$id,'template_title'=>$id,'template_fields'=>$fields,'field_map'=>$map];}
$GLOBALS['fixture_settings']=['wechat.subscribe_enabled'=>true,'wechat.subscribe_template_ids'=>[
 'order_progress'=>bindFixture('order-fixture',['character_string1','thing2','phrase3','time4','thing5'],['order_no'=>'character_string1','order_type'=>'thing2','status'=>'phrase3','time'=>'time4','notice_text'=>'thing5']),
 'order_fee_change'=>bindFixture('fee-fixture',['thing1','character_string2','amount3','thing4'],['fee_note'=>'thing1','order_no'=>'character_string2','amount'=>'amount3','notice_text'=>'thing4']),
 'account_funds'=>bindFixture('funds-fixture',['thing1','amount2','time3','thing4'],['change_reason'=>'thing1','amount'=>'amount2','time'=>'time3','notice_text'=>'thing4']),
 'chat_unread'=>bindFixture('chat-fixture',['thing1','thing2','time3','thing4','thing5'],['sender_name'=>'thing1','notice_text'=>'thing2','time'=>'time3','message_type'=>'thing4','chat_info'=>'thing5'])
]];
$cfg=['host'=>'127.0.0.1','port'=>3306,'username'=>'root','password'=>'test-only','database'=>'mysql'];
$admin=new Database($cfg);$name='fixture_quota_'.bin2hex(random_bytes(5));
$admin->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');$cfg['database']=$name;$db=new Database($cfg);
try{
$db->execute('CREATE TABLE users(id BIGINT PRIMARY KEY) ENGINE=InnoDB');
$db->execute('INSERT INTO users VALUES(1)');
$db->execute('CREATE TABLE system_settings(setting_key VARCHAR(191) PRIMARY KEY,setting_value LONGTEXT,value_type VARCHAR(20),group_name VARCHAR(50),is_public TINYINT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB');
$keys=['order_progress','order_fee_change','account_funds','chat_unread','reviewResult'];
foreach($keys as $key){$pref=Quota::saveSubscriptionPreference($db,1,$key,true,'accept');ensure($pref['remaining_count']===1&&$pref['enabled']===true,'initial grant '.$key);}
$p=fn(string $key)=>Quota::subscriptionPreferences($db,1)[$key];
$channel=new DatabaseChatReminderChannel($db);
$db->transaction(function()use($channel){$channel->lockRecipient(1);$channel->consume(1,'chat_unread');});
ensure($p('chat_unread')['remaining_count']===0&&!$p('chat_unread')['enabled'],'last chat reservation disables exhausted grant');
$db->transaction(function()use($channel){$channel->lockRecipient(1);$channel->reject(1,'chat_unread','chat-fixture',47003);});
ensure($p('chat_unread')['remaining_count']===1&&$p('chat_unread')['enabled']&&$p('chat_unread')['authorization_status']==='accept','definite rejection restores last grant and enables it');
$db->transaction(function()use($channel){$channel->lockRecipient(1);$channel->consume(1,'chat_unread');});
Quota::saveSubscriptionPreference($db,1,'chat_unread',false,'disabled');
$db->transaction(function()use($channel){$channel->lockRecipient(1);$channel->reject(1,'chat_unread','chat-fixture',47003);});
ensure(!$p('chat_unread')['enabled']&&$p('chat_unread')['authorization_status']==='disabled','restoration does not override explicit disable');
Quota::saveSubscriptionPreference($db,1,'chat_unread',true,'accept');
$db->transaction(function()use($channel){$channel->lockRecipient(1);$channel->reject(1,'chat_unread','chat-fixture',43101);});
ensure(!$p('chat_unread')['enabled']&&$p('chat_unread')['remaining_count']===0,'chat definitive permission denial invalidates matching grant');
foreach(['order_progress','order_fee_change','account_funds','reviewResult'] as $key)ensure($p($key)['remaining_count']===1&&$p($key)['enabled'],'chat changes leave '.$key.' unaffected');
foreach(['order_progress'=>'order-fixture','order_fee_change'=>'fee-fixture','account_funds'=>'funds-fixture'] as $key=>$id){
 ensure(Quota::markSubscriptionConsumed($db,1,$key,$id),'matching business template consumes '.$key);
 ensure($p($key)['remaining_count']===0&&!$p($key)['enabled'],'business exhausted '.$key);
 Quota::restoreSubscriptionConsumed($db,1,$key,$id);
 ensure($p($key)['remaining_count']===1&&$p($key)['enabled'],'business restored '.$key);
}
ensure($p('reviewResult')['remaining_count']===1,'other business quota operations leave onboarding unaffected');
$scene=['field_map'=>['worker_name'=>'name1'],'template_fields'=>['name1']];
foreach(['测试师傅123😀'=> '测试师傅',str_repeat('中',11)=>str_repeat('中',10),str_repeat('A',21)=>str_repeat('A',20),'123😀'=>'入驻师傅'] as $raw=>$expected){
 $data=WechatTemplateValue::reviewResultData($scene,['worker_name'=>$raw]);
 ensure($data['name1']['value']===$expected,'review name normalization '.mb_strlen($expected,'UTF-8'));
}
echo "Cross-scene quota and review name regression passed.\n";
}finally{$admin->execute('DROP DATABASE '.$name);}

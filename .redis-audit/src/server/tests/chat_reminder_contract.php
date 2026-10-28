<?php
declare(strict_types=1);
if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1'){echo "提醒数据库验证未启用\n";exit;}
require_once dirname(__DIR__).'/app/Support/functions.php';
spl_autoload_register(static function(string $class):void{$prefix='WorkerAdmin\\';if(strpos($class,$prefix)===0){$file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;}});
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatReminderReceipts;
use WorkerAdmin\Application\Chat\ChatReminderDispatch;
use WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
function reminderEnsure(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
final class ReminderGatewayFixture implements SubscribeMessageGateway{
 public int $calls=0;public bool $fail=false;public int $reject=0;public bool $malformed=false;public array $payload=[];
 public function categoryList():array{return [];}public function publicTemplateTitles(array $ids,int $start=0,int $limit=30):array{return [];}
 public function publicTemplateKeywords(string $tid):array{return [];}public function addTemplate(string $tid,array $kids,string $desc=''):string{return '';}
 public function templateList():array{return [];}
 public function send(array $payload):array{$this->calls++;$this->payload=$payload;reminderEnsure(!isset($payload['sms_fallback'])&&$payload['scene_key']==='chat_unread','chat SMS fallback or wrong scene');if($this->fail)throw new RuntimeException('accepted but transport result lost');if($this->reject!==0)throw new RuntimeException('definitive provider failure',$this->reject);if($this->malformed)return [];return ['errcode'=>0];}
}
$address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');if(preg_match('/^127\.0\.0\.1:([0-9]+)$/D',$address,$m)!==1)throw new RuntimeException('loopback required');
$config=['host'=>'127.0.0.1','port'=>(int)$m[1],'database'=>'mysql','username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
$root=new Database($config);$name='bixuan_test_chat_reminder_'.bin2hex(random_bytes(6));$root->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$name;$GLOBALS['worker_admin_config']=['database'=>$config];$db=db();
try{
 (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');
 $db->execute("INSERT INTO users(id,public_id,nickname,status,created_at,updated_at) VALUES(10001,:public,'提醒隔离用户','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>str_repeat('U',26)]);
 $db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10001,'fixture-app','fixture-openid','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $settings=['wechat.miniapp_appid'=>['fixture-app','string'],'wechat.subscribe_enabled'=>['1','bool'],'wechat.subscribe_template_ids'=>[json_encode(['chat_unread'=>['template_id'=>'fixture-template','field_map'=>['status'=>'thing1','time'=>'time2']]]),'json'],
 'wechat.subscription.user.10001'=>[json_encode(['chat_unread'=>['enabled'=>true,'authorization_status'=>'accept','remaining_count'=>12,'template_id'=>'fixture-template','subscription_type'=>'one_time']]),'json']];
 foreach($settings as $key=>$value)$db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,created_at,updated_at) VALUES(:key,:value,:type,'notification',0,'隔离测试',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type)",['key'=>$key,'value'=>$value[0],'type'=>$value[1]]);
 $receipts=new ChatReminderReceipts($db);
 $body=static function(string $op):string{$q=['cluster_version'=>'1','conversation_id'=>'401','intent_id'=>'1','operation_id'=>$op,'recipient_id'=>'10001','target_seq'=>'2'];return json_encode($q,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);};
 $a=str_repeat('a',64);$raw=$body($a);
 $auth=new ChatInternalAuth($db,'test','internal-fixture',str_repeat('k',32));$headers=[];
 foreach($auth->sign('/chat-internal.php',$raw) as $line){[$key,$value]=explode(': ',$line,2);$headers[strtolower(substr($key,7))]=$value;}
 $db->execute("INSERT INTO chat_internal_nonces(nonce_hash,expires_at) VALUES(:old,UTC_TIMESTAMP()-INTERVAL 1 SECOND),(:live,UTC_TIMESTAMP()+INTERVAL 61 SECOND)",['old'=>str_repeat('0',64),'live'=>str_repeat('1',64)]);
 $auth->verify('/chat-internal.php',$raw,$headers);
 reminderEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM chat_internal_nonces WHERE nonce_hash=:old",['old'=>str_repeat('0',64)])===0,'expired nonce needs reminder worker');
 reminderEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM chat_internal_nonces WHERE nonce_hash=:live",['live'=>str_repeat('1',64)])===1,'live nonce pruned');
 $replayed=false;try{$auth->verify('/chat-internal.php',$raw,$headers);}catch(RuntimeException $error){$replayed=true;}reminderEnsure($replayed,'internal replay accepted');
 $db->execute("INSERT INTO chat_internal_nonces(nonce_hash,expires_at) VALUES(:old,UTC_TIMESTAMP()-INTERVAL 1 SECOND)",['old'=>str_repeat('2',64)]);
 $tamper=false;try{$auth->verify('/chat-internal.php',$raw.' ',$headers);}catch(RuntimeException $error){$tamper=true;}reminderEnsure($tamper,'tampered body accepted');
 reminderEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM chat_internal_nonces WHERE nonce_hash=:old",['old'=>str_repeat('2',64)])===1,'unauthenticated request mutated nonce table');
 for($i=0;$i<3;$i++)reminderEnsure($receipts->receive($raw)['state']==='accepted','receipt changed');
 reminderEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE topic='chat_reminder'")===1,'duplicate PHP receipt duplicated outbox');
 $conflict=false;try{$receipts->receive(str_replace('"target_seq":"2"','"target_seq":"3"',$raw));}catch(RuntimeException $error){$conflict=true;}reminderEnsure($conflict,'immutable operation payload changed');
 $gateway=new ReminderGatewayFixture();$chat=new \WorkerAdmin\Foundation\ChatApplicationContainer($db);
 $dispatcher=$chat->reminders($gateway,static fn(string $op):bool=>false);$stats=$dispatcher->dispatch();
 reminderEnsure($gateway->calls===0&&$stats['suppressed']===1,'read message sent');
 $b=str_repeat('b',64);$receipts->receive($body($b));$dispatcher=$chat->reminders($gateway,static fn(string $op):bool=>true);$stats=$dispatcher->dispatch();
 reminderEnsure($gateway->calls===1&&$stats['sent']===1,'unread not sent once');
 $c=str_repeat('c',64);$receipts->receive($body($c));$gateway->fail=true;$stats=$dispatcher->dispatch();
 reminderEnsure($gateway->calls===2&&$stats['unknown']===1,'provider unknown not recorded');
 $db->execute("UPDATE message_outbox SET status='failed',next_attempt_at=UTC_TIMESTAMP() WHERE business_key=:key",['key'=>'chat:'.$c]);$dispatcher->dispatch();
 reminderEnsure($gateway->calls===2&&$receipts->receive($body($c))['state']==='provider_unknown','unknown provider blindly retried');
 reminderEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE topic='aliyun_sms'")===0,'unknown fell back to paid SMS');
 $d=str_repeat('d',64);$receipts->receive($body($d));$checks=0;$final=$chat->reminders($gateway,static function(string $op)use(&$checks):bool{return ++$checks===1;});$stats=$final->dispatch();
 reminderEnsure($gateway->calls===2&&$stats['suppressed']===1,'final read check missed');
 $e=str_repeat('e',64);$receipts->receive($body($e));$db->execute("UPDATE chat_reminder_receipts SET state='sending',updated_at=UTC_TIMESTAMP()-INTERVAL 6 MINUTE WHERE operation_id=:op",['op'=>$e]);
 $dispatcher->dispatch();reminderEnsure($receipts->receive($body($e))['state']==='provider_unknown'&&$gateway->calls===2,'crashed sender retried');
 $large=str_repeat('f',64);$largeBody=str_replace('"recipient_id":"10001"','"recipient_id":"18446744073709551615"',$body($large));$receipts->receive($largeBody);$dispatcher->dispatch();
 reminderEnsure($gateway->calls===2&&$receipts->status($large)['diagnostic']==='recipient_id_unsupported','unsigned recipient ID saturated into another channel account');
 // Live-channel boundary regressions still use a local fake provider.
 $gateway->fail=false;
 $db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10001,'other-app','wrong-app-openid','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $new=static function(string $label)use($receipts,$body):string{$op=hash('sha256',$label);$receipts->receive($body($op));return $op;};
 $quota=static fn():int=>(int)(\WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore::subscriptionPreferences($db,10001)['chat_unread']['remaining_count']??0);
 $setPref=static function(array $changes)use($db):void{
  $prefs=\WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore::subscriptionPreferences($db,10001);
  $prefs['chat_unread']=array_merge($prefs['chat_unread'],$changes);
  $db->execute('UPDATE system_settings SET setting_value=:v WHERE setting_key=:k',['v'=>json_encode($prefs),'k'=>'wechat.subscription.user.10001']);
 };
 $new('correct-app');$dispatcher->dispatch();reminderEnsure($gateway->payload['openid']==='fixture-openid','another AppID identity selected');
 $db->execute("INSERT INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(10001,'fixture-app','ambiguous-openid','active',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $op=$new('ambiguous');$before=$gateway->calls;$dispatcher->dispatch();reminderEnsure($gateway->calls===$before&&$receipts->status($op)['diagnostic']==='openid_missing_or_ambiguous','ambiguous identity dispatched');
 $db->execute("DELETE FROM wechat_identities WHERE openid='ambiguous-openid'");
 $setPref(['template_id'=>'old-template']);$op=$new('old-grant');$dispatcher->dispatch();reminderEnsure($gateway->calls===$before&&$receipts->status($op)['diagnostic']==='subscription_template_changed','old template grant consumed');
 $setPref(['template_id'=>'fixture-template']);$checks=0;
 $race=$chat->reminders($gateway,static function(string $op)use(&$checks,$db):bool{if(++$checks===2)$db->execute("UPDATE wechat_identities SET openid='rotated-openid' WHERE app_id='fixture-app'");return true;});
 $new('fresh-payload');$race->dispatch();reminderEnsure($gateway->payload['openid']==='rotated-openid','stale precheck payload sent after final rebuild');
 $gateway->reject=47003;$beforeQuota=$quota();$op=$new('known-rejection');$dispatcher->dispatch();
 reminderEnsure($receipts->status($op)['diagnostic']==='wechat_rejected_47003'&&$quota()===$beforeQuota,'definite rejection lost quota or marked unknown');
 $before=$gateway->calls;$dispatcher->dispatch();reminderEnsure($gateway->calls===$before,'definite rejection retried');
 $gateway->reject=0;$gateway->malformed=true;$op=$new('malformed-response');$beforeQuota=$quota();$dispatcher->dispatch();
 reminderEnsure($receipts->status($op)['state']==='provider_unknown'&&$quota()===$beforeQuota-1,'malformed response counted as success or released unknown reservation');
 $gateway->malformed=false;$gateway->reject=43101;$op=$new('no-permission');$dispatcher->dispatch();
 reminderEnsure($receipts->status($op)['diagnostic']==='wechat_rejected_43101'&&$quota()===0,'official permission denial did not invalidate quota');
 echo "S20 PHP 内部签名/重放、幂等回执、最终未读复验、供应商未知、ID精度、AppID/模板绑定、最终载荷、明确拒绝/未知隔离与短信隔离验证通过\n";
}finally{$root->execute('DROP DATABASE IF EXISTS `'.$name.'`');}


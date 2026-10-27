<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'WorkerAdmin\\')){ $p=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatSmsReminders;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Application\Identity\MobileNumberProtection;
use WorkerAdmin\Infrastructure\Persistence\DatabaseUserWalletRepository;
use WorkerAdmin\Infrastructure\Messaging\DatabaseChatSmsDeliveryRepository;
use WorkerAdmin\Application\Messaging\PaidSmsDelivery;
function public_id(){return bin2hex(random_bytes(13));}
function app_config($key,$default=null){return $GLOBALS['smsConfig'][$key]??$default;}
function setting($key,$default=null){return $GLOBALS['smsSettings'][$key]??$default;}
final class SmsFixtureSettings implements \WorkerAdmin\Contracts\SettingsProvider {
 public function get(string $key,$default=null){return setting($key,$default);}
 public function bool(string $key,bool $default=false):bool{return (bool)$this->get($key,$default);}
 public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
}
final class SmsFixtureCipher implements \WorkerAdmin\Contracts\PrivateValueCipher {
 public function hmac(string $v,string $c):string{return hash_hmac('sha256',$c.':'.$v,'fixture-key-only');}
 public function encrypt(string $v,string $c):?string{return base64_encode($c.':'.$v);}
 public function decrypt(string $v,string $c):string{$d=base64_decode($v,true);return is_string($d)&&str_starts_with($d,$c.':')?substr($d,strlen($c)+1):'';}
}
final class SmsFixtureGateway implements \WorkerAdmin\Contracts\SmsGateway {
 public array $sent=[];public string $outcome='accepted';public int $status=3;
 public function enabled():bool{return true;}
 public function send(string $m,string $t,array $p,string $o='',bool $a=false):array{throw new RuntimeException('No unstructured SMS in paid flow');}
 public function sendWithOutcome(string $m,string $t,array $p,string $o='',bool $a=false):array{
  if($t!=='SYNTHETIC_CHAT_TEMPLATE'||$p!==['type'=>'订单沟通']||!str_starts_with($o,'chat-nudge:'))throw new RuntimeException('Wrong channel purpose or leaked body');
  $this->sent[$m]=$o;return ['outcome'=>$this->outcome,'biz_id'=>'synthetic','request_id'=>'fixture','provider_code'=>'OK','message'=>'fixture'];
 }
 public function querySendDetails(string $m,string $date,?string $biz=null):array{return ['outcome'=>'ok','details'=>[['send_status'=>$this->status,'out_id'=>$this->sent[$m]??'','phone_num'=>$m]]];}
}
final class SmsFixtureDiagnostics implements \WorkerAdmin\Contracts\MessageDiagnostics {public function write(string $c,string $x,Throwable $e):void{throw new RuntimeException('Unexpected SMS worker failure: '.$e->getMessage());}}
date_default_timezone_set('UTC');$addr=(string)getenv('CHAT_SMS_MYSQL_ADDR');
if(preg_match('/^127\.0\.0\.1:([0-9]+)$/D',$addr,$match)!==1)throw new RuntimeException('isolated loopback required');
$config=['host'=>'127.0.0.1','port'=>(int)$match[1],'database'=>'mysql','username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
$GLOBALS['smsConfig']=['chat.enabled'=>true,'chat.sms_reminders_enabled'=>true];
$GLOBALS['smsSettings']=['sms.enabled'=>true,'sms.provider'=>'aliyun','sms.template_codes'=>['chat_unread_nudge'=>'SYNTHETIC_CHAT_TEMPLATE']];
$GLOBALS['allowed']=true;$GLOBALS['target']='91001';$GLOBALS['wave']='1';
$build=static function(Database $db):array{
 $settings=new SmsFixtureSettings();$cipher=new SmsFixtureCipher();$mobile=new MobileNumberProtection($cipher);$wallet=new UserWalletManager(new DatabaseUserWalletRepository($db),$settings);
 $service=new ChatSmsReminders($db,$wallet,$mobile,$cipher,static function(array $q):array{
  if(!$GLOBALS['allowed'])return [];$recipient=$q['recipient_id']??$GLOBALS['target'];
  return [['recipient_id'=>$recipient,'message_id'=>'100','policy_version'=>'1','wave'=>hash('sha256','fixture:'.$recipient.':'.$GLOBALS['wave'])]];
 },str_repeat('k',32));
 $repo=new DatabaseChatSmsDeliveryRepository($db,$wallet,$service);$gateway=new SmsFixtureGateway();
 return [$service,$repo,$gateway,new PaidSmsDelivery($repo,$gateway,$mobile,$settings,new SmsFixtureDiagnostics()),$cipher];
};
if(($argv[1]??'')==='--child'){
 $name=(string)getenv('CHAT_SMS_DATABASE');if(preg_match('/^bixuan_test_sms_[a-f0-9]+$/D',$name)!==1)throw new RuntimeException('isolated child database required');$config['database']=$name;$db=new Database($config);[$service]=$build($db);
 try{$service->create('90001',json_decode($argv[2],true,32,JSON_THROW_ON_ERROR));echo 'created';}catch(RuntimeException $e){echo $e->getMessage();}exit;
}
$root=new Database($config);$name='bixuan_test_sms_'.bin2hex(random_bytes(5));$root->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$name;$db=new Database($config);$count=0;
$assert=static function(bool $ok,string $label)use(&$count):void{$count++;if(!$ok)throw new RuntimeException('SMS integration: '.$label);};
$reject=static function(callable $fn,string $code)use($assert):void{try{$fn();}catch(RuntimeException $e){$assert($e->getMessage()===$code,$code.' got '.$e->getMessage());return;}throw new RuntimeException('Missing rejection '.$code);};
try{
 (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($db->pdo(),dirname(__DIR__).'/database/database.sql');
 [$service,$repo,$gateway,$delivery,$cipher]=$build($db);
 foreach(array_merge([90001,90002,90003],range(91001,91030)) as $id)$db->execute("INSERT INTO users(id,public_id,nickname,status,mobile_ciphertext,created_at,updated_at) VALUES(:id,:public,'fixture','active',:cipher,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$id,'public'=>public_id(),'cipher'=>$cipher->encrypt('139'.str_pad((string)$id,8,'0',STR_PAD_LEFT),'user-mobile')]);
 $db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,created_at,updated_at) VALUES('sms.user_worker_invite_fee_cents','10','int','sms',0,'fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='10'");
 $quote=static function(string $recipient)use($service):array{$GLOBALS['target']=$recipient;return $service->quote('90001',['conversation_id'=>'9']);};
 $payload=static function(array $quote):array{$r=$quote['items'][0];return ['conversation_id'=>'9','recipient_id'=>$r['recipient_id'],'message_id'=>$r['message_id'],'wave'=>$r['wave'],'fee_cents'=>$r['fee_cents'],'quote_token'=>$r['quote_token'],'client_request_id'=>bin2hex(random_bytes(16))];};
 $q=$quote('91001');$assert($q['available_cents']===0,'zero balance visible');$assert((int)$db->fetchValue('SELECT COUNT(*) FROM chat_sms_nudges')===0,'quote never creates paid task');
 $input=$payload($q);$reject(fn()=>$service->create('90001',$input),'CHAT_SMS_BALANCE_INSUFFICIENT');
 $db->execute("INSERT INTO user_wallets(user_id,available_cents,reserved_cents,version,created_at,updated_at) VALUES(90001,100,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),(90002,100,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),(90003,100,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE available_cents=100");
 $created=$service->create('90001',$input);$assert($created['state']==='queued','only queued before worker');$assert(!$gateway->sent,'HTTP create never sends');
 $assert((int)$db->fetchValue('SELECT available_cents FROM user_wallets WHERE user_id=90001')===90,'exact integer hold');
 $assert((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=90001')===10,'hold remains separate from charge');
 $assert($service->create('90001',$input)===$created,'same operation replays once');
 $alternate=$input;$alternate['client_request_id']=bin2hex(random_bytes(16));$assert($service->create('90001',$alternate)===$created,'second device same unread wave reuses original task');
 $bad=$input;$bad['recipient_id']='91002';$reject(fn()=>$service->create('90001',$bad),'CHAT_SMS_CONFLICT');
 $id=(int)$db->fetchValue('SELECT id FROM chat_sms_nudges WHERE client_request_id=:r',['r'=>$input['client_request_id']]);$out=(int)$db->fetchValue('SELECT outbox_id FROM chat_sms_nudges WHERE id=:id',['id'=>$id]);
 $delivery->dispatchOutbox($out,$id);$assert(count($gateway->sent)===1,'one structured SMS');$assert((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=90001')===10,'provider acceptance does not charge');
 $delivery->checkReceipt($id);$delivery->checkReceipt($id);$assert((int)$db->fetchValue("SELECT COUNT(*) FROM user_wallet_ledger WHERE reference_type='chat_sms_nudge' AND entry_type='sms_charge'")===1,'repeated delivered receipt charges once');
 $assert((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=90001')===0,'delivered consumes hold');
 $assert($quote('91001')['items']===[],'same unread wave hidden');
 $input2=$payload($quote('91002'));$service->create('90001',$input2);$GLOBALS['allowed']=false;
 $id2=(int)$db->fetchValue('SELECT id FROM chat_sms_nudges WHERE client_request_id=:r',['r'=>$input2['client_request_id']]);$out2=(int)$db->fetchValue('SELECT outbox_id FROM chat_sms_nudges WHERE id=:id',['id'=>$id2]);
 $delivery->dispatchOutbox($out2,$id2);$assert(count($gateway->sent)===1,'read or revocation suppresses queued send');$assert($service->status('90001',['client_request_id'=>$input2['client_request_id']])['billing_status']==='released','suppression releases hold');$GLOBALS['allowed']=true;
 $input3=$payload($quote('91003'));$db->execute("UPDATE system_settings SET setting_value='20' WHERE setting_key='sms.user_worker_invite_fee_cents'");$reject(fn()=>$service->create('90001',$input3),'CHAT_SMS_PRICE_CHANGED');$db->execute("UPDATE system_settings SET setting_value='10' WHERE setting_key='sms.user_worker_invite_fee_cents'");
 $input4=$payload($quote('91004'));$service->create('90001',$input4);$db->execute("UPDATE users SET mobile_ciphertext=:phone WHERE id=91004",['phone'=>$cipher->encrypt('13999999999','user-mobile')]);
 $id4=(int)$db->fetchValue('SELECT id FROM chat_sms_nudges WHERE client_request_id=:r',['r'=>$input4['client_request_id']]);$delivery->dispatchOutbox((int)$db->fetchValue('SELECT outbox_id FROM chat_sms_nudges WHERE id=:id',['id'=>$id4]),$id4);$assert(count($gateway->sent)===1,'changed binding never sends to old or new phone');
 $input5=$payload($quote('91005'));$service->create('90001',$input5);$id5=(int)$db->fetchValue('SELECT id FROM chat_sms_nudges WHERE client_request_id=:r',['r'=>$input5['client_request_id']]);$gateway->outcome='uncertain';
 $delivery->dispatchOutbox((int)$db->fetchValue('SELECT outbox_id FROM chat_sms_nudges WHERE id=:id',['id'=>$id5]),$id5);
 $before=count($gateway->sent);$delivery->dispatchInvite($id5);$assert(count($gateway->sent)===$before,'uncertain channel never resends');
 $db->execute('UPDATE chat_sms_nudges SET receipt_deadline_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=:id',['id'=>$id5]);$delivery->checkReceipt($id5);
 $assert($service->status('90001',['client_request_id'=>$input5['client_request_id']])['state']==='unknown','receipt timeout uses existing release policy');$assert($quote('91005')['items']===[],'unknown send retains wave fence after refund');
 $assert($service->status('90002',['client_request_id'=>$input['client_request_id']])['state']==='not_found','another account cannot inspect task');
 // Shared-phone cooldown applies across payers and recipient account aliases.
 $GLOBALS['target']='91007';$other=$payload($service->quote('90002',['conversation_id'=>'9']));$service->create('90002',$other);
 $db->execute('UPDATE users SET mobile_ciphertext=:phone WHERE id=91008',['phone'=>$cipher->encrypt('139'.str_pad('91007',8,'0',STR_PAD_LEFT),'user-mobile')]);
 $GLOBALS['target']='91008';$assert($service->quote('90003',['conversation_id'=>'9'])['items']===[],'shared phone cooldown crosses payer and conversation identities');
 $reject(fn()=>$service->create('90003',$other),'CHAT_SMS_PRICE_CHANGED');
 $GLOBALS['target']='91009';$changed=$payload($quote('91009'));$GLOBALS['allowed']=false;
 $reject(fn()=>$service->create('90001',$changed),'CHAT_SMS_INELIGIBLE');$GLOBALS['allowed']=true;
 $failed=$payload($quote('91010'));$service->create('90001',$failed);$fid=(int)$db->fetchValue('SELECT id FROM chat_sms_nudges WHERE client_request_id=:r',['r'=>$failed['client_request_id']]);
 $gateway->outcome='accepted';$gateway->status=2;$delivery->dispatchOutbox((int)$db->fetchValue('SELECT outbox_id FROM chat_sms_nudges WHERE id=:id',['id'=>$fid]),$fid);$delivery->checkReceipt($fid);
 $assert($service->status('90001',['client_request_id'=>$failed['client_request_id']])['billing_status']==='released','explicit failed receipt refunds exactly once');
 // Eight independent connections compete for one recipient/wave and the final balance.
 $db->execute('UPDATE user_wallets SET available_cents=10,reserved_cents=0 WHERE user_id=90001');$race=$payload($quote('91006'));$children=[];
 foreach(range(1,8) as $n){$r=$race;$r['client_request_id']=bin2hex(random_bytes(16));$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--child',json_encode($r,JSON_THROW_ON_ERROR)],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,array_merge(getenv(),['CHAT_SMS_DATABASE'=>$name]));fclose($pipes[0]);$children[]=[$p,$pipes];}
 foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);$assert($exit===0&&$err==='','isolated child execution');$assert($out==='created','same wave resolves idempotently '.$out);}
 $assert((int)$db->fetchValue("SELECT COUNT(*) FROM chat_sms_nudges WHERE recipient_id=91006")===1,'concurrent devices create only one task');$assert((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=90001')===10,'one hold under eight processes');
 $GLOBALS['smsConfig']['chat.sms_reminders_enabled']=false;$delivery->dispatchPending(50);
 $assert((int)$db->fetchValue('SELECT reserved_cents FROM user_wallets WHERE user_id=90001')===0,'closing admission refunds unsent tasks');
 echo 'Chat paid SMS MySQL: '.$count." assertions passed\n";
}finally{$root->execute('DROP DATABASE IF EXISTS `'.$name.'`');}

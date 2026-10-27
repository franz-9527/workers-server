<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Application\Identity\MobileNumberProtection;
use WorkerAdmin\Contracts\PrivateValueCipher;

/** Explicit paid nudge. This never marks chat read or sends from an HTTP request. */
final class ChatSmsReminders
{
 public function __construct(private Database $db,private UserWalletManager $wallet,private MobileNumberProtection $mobile,private PrivateValueCipher $cipher,private \Closure $eligible,private string $key) {}
 public function enabled():bool {
  return \app_config('chat.enabled',false)===true&&\app_config('chat.sms_reminders_enabled',false)===true
   &&filter_var(\setting('sms.enabled',false),FILTER_VALIDATE_BOOLEAN)&&strtolower((string)\setting('sms.provider','aliyun'))==='aliyun'&&$this->template()!=='';
 }
 public function template():string {
  $codes=(array)\setting('sms.template_codes',[]);$v=$codes['chat_unread_nudge']??'';
  return trim(is_array($v)?(string)(($v['template_code']??'')?:($v['template_id']??'')?:($v['code']??'')):(string)$v);
 }
 public function fee():int {
  return max(1,min(10000,(int)($this->db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='sms.user_worker_invite_fee_cents'")??10)));
 }
 public static function id($v):string {
  if(!is_string($v)||preg_match('/^[1-9][0-9]{0,19}$/D',$v)!==1||(strlen($v)===20&&strcmp($v,'18446744073709551615')>0))throw new RuntimeException('CHAT_SMS_INVALID',400);
  return $v;
 }
 private function payer(string $v):int {
  self::id($v);if(strlen($v)>strlen((string)PHP_INT_MAX)||(strlen($v)===strlen((string)PHP_INT_MAX)&&strcmp($v,(string)PHP_INT_MAX)>0))throw new RuntimeException('CHAT_SMS_INVALID',400);
  return (int)$v;
 }
 private function sign(string $payer,array $q,int $expires):string {
  if(strlen($this->key)<32)throw new RuntimeException('CHAT_SMS_UNAVAILABLE',503);
  return hash_hmac('sha256',implode('|',['chat-sms-v1',$payer,$q['conversation_id'],$q['recipient_id'],$q['message_id'],$q['wave'],(string)$q['fee_cents'],(string)$expires]),$this->key);
 }
 public function currentMobile(string $recipient):string {
  $r=$this->db->fetch("SELECT mobile_ciphertext FROM users WHERE id=:id AND status='active'",['id'=>self::id($recipient)]);
  return $r?$this->mobile->normalize($this->cipher->decrypt((string)($r['mobile_ciphertext']??''),'user-mobile')):'';
 }
 public function candidates(string $payer,string $cid,string $recipient='',string $message=''):array {
  $rows=($this->eligible)(['payer_id'=>$payer,'conversation_id'=>$cid]+($recipient!==''?['recipient_id'=>$recipient]:[])+($message!==''?['message_id'=>$message]:[]));
  if(!is_array($rows)||count($rows)>64)throw new RuntimeException('CHAT_SMS_UNAVAILABLE',503);
  foreach($rows as $r){foreach(['recipient_id','message_id','policy_version'] as $k)self::id($r[$k]??null);if(preg_match('/^[a-f0-9]{64}$/D',(string)($r['wave']??''))!==1||$r['recipient_id']===$payer)throw new RuntimeException('CHAT_SMS_UNAVAILABLE',503);}
  return $rows;
 }
 public function quote(string $payer,array $q):array {
  $this->payer($payer);$cid=self::id($q['conversation_id']??null);
  if(array_keys($q)!==['conversation_id'])throw new RuntimeException('CHAT_SMS_INVALID',400);
  if(!$this->enabled())return ['enabled'=>false,'items'=>[]];
  $items=[];$fee=$this->fee();$balance=(int)($this->db->fetchValue('SELECT available_cents FROM user_wallets WHERE user_id=:id',['id'=>$payer])??0);
  foreach($this->candidates($payer,$cid) as $r){
   $phone=$this->currentMobile($r['recipient_id']);if($phone==='')continue;
   $hmac=$this->mobile->hmac($phone);$wave=$r['wave'];
   $old=$this->db->fetch('SELECT * FROM chat_sms_nudges WHERE active_dedupe_key=:wave AND user_id=:payer',['wave'=>$wave,'payer'=>$payer]);
   if($old)continue;
   if($this->limited($payer,$hmac))continue;
   $r+=['conversation_id'=>$cid,'fee_cents'=>$fee];$expires=time()+120;
   $r['quote_token']=$expires.'.'.$this->sign($payer,$r,$expires);$items[]=$r;
  }
  return ['payer_id'=>$payer,'enabled'=>true,'items'=>$items,'fee_cents'=>$fee,'available_cents'=>$balance,'wait_minutes'=>5,'cooldown_minutes'=>30];
 }
 private function limited(string $payer,string $hmac):bool {
  return (int)$this->db->fetchValue("SELECT COUNT(*) FROM chat_sms_nudges WHERE mobile_hmac_snapshot=:hmac AND created_at>=UTC_DATE()",['hmac'=>$hmac])>=3
   ||(bool)$this->db->fetchValue("SELECT id FROM chat_sms_nudges WHERE mobile_hmac_snapshot=:hmac AND created_at>UTC_TIMESTAMP()-INTERVAL 30 MINUTE LIMIT 1",['hmac'=>$hmac])
   ||(int)$this->db->fetchValue("SELECT COUNT(*) FROM chat_sms_nudges WHERE user_id=:id AND created_at>=UTC_DATE()",['id'=>$payer])>=10;
 }
 public function create(string $payer,array $q):array {
  $uid=$this->payer($payer);
  $keys=array_keys($q);sort($keys);$expected=['client_request_id','conversation_id','fee_cents','message_id','quote_token','recipient_id','wave'];sort($expected);
  if($keys!==$expected)throw new RuntimeException('CHAT_SMS_INVALID',400);
  foreach(['conversation_id','recipient_id','message_id'] as $k)self::id($q[$k]);
  if($q['recipient_id']===$payer||!is_int($q['fee_cents'])||$q['fee_cents']<1||$q['fee_cents']>10000||!is_string($q['client_request_id'])||preg_match('/^[a-f0-9]{32,64}$/D',$q['client_request_id'])!==1||preg_match('/^[a-f0-9]{64}$/D',(string)$q['wave'])!==1||!is_string($q['quote_token']))throw new RuntimeException('CHAT_SMS_INVALID',400);
  $fingerprint=hash('sha256',json_encode([$q['conversation_id'],$q['recipient_id'],$q['message_id'],$q['fee_cents'],$q['wave']],JSON_THROW_ON_ERROR));
  $existing=$this->existing($payer,$q['client_request_id']);
  if($existing){if(!hash_equals($existing['request_fingerprint'],$fingerprint))throw new RuntimeException('CHAT_SMS_CONFLICT',409);return $this->view($existing);}
  if(!$this->enabled())throw new RuntimeException('CHAT_SMS_UNAVAILABLE',503);
  $token=explode('.',$q['quote_token']);
  if(count($token)!==2||!ctype_digit($token[0])||(int)$token[0]<=time()||(int)$token[0]>time()+120||!hash_equals($this->sign($payer,$q,(int)$token[0]),$token[1])||$this->fee()!==$q['fee_cents'])throw new RuntimeException('CHAT_SMS_PRICE_CHANGED',409);
  $phone=$this->currentMobile($q['recipient_id']);if($phone==='')throw new RuntimeException('CHAT_SMS_INELIGIBLE',409);
  $hmac=$this->mobile->hmac($phone);
  return $this->db->transaction(function()use($payer,$uid,$q,$fingerprint,$phone,$hmac):array{
   // Permanent guard rows serialize shared-phone spam limits across conversations and payers.
   $guards=['mobile:'.$hmac,'payer:'.$payer];sort($guards);
   foreach($guards as $key){$this->db->execute('INSERT IGNORE INTO chat_sms_guards(guard_key) VALUES(:key)',['key'=>$key]);$this->db->fetchValue('SELECT guard_key FROM chat_sms_guards WHERE guard_key=:key FOR UPDATE',['key'=>$key]);}
   $wallet=$this->wallet->lockWallet($uid);
   $existing=$this->existing($payer,$q['client_request_id']);
   if($existing){if(!hash_equals($existing['request_fingerprint'],$fingerprint))throw new RuntimeException('CHAT_SMS_CONFLICT',409);return $this->view($existing);}
   $old=$this->db->fetch('SELECT * FROM chat_sms_nudges WHERE active_dedupe_key=:wave AND user_id=:id',['wave'=>$q['wave'],'id'=>$payer]);if($old)return $this->view($old);
   if($this->limited($payer,$hmac))throw new RuntimeException('CHAT_SMS_RATE_LIMIT',429);
   $current=$this->candidates($payer,$q['conversation_id'],$q['recipient_id'],$q['message_id']);
   if(count($current)!==1||!hash_equals($current[0]['wave'],$q['wave'])||$this->currentMobile($q['recipient_id'])!==$phone)throw new RuntimeException('CHAT_SMS_INELIGIBLE',409);
   if($this->fee()!==$q['fee_cents'])throw new RuntimeException('CHAT_SMS_PRICE_CHANGED',409);
   if((int)$wallet['available_cents']<$q['fee_cents'])throw new RuntimeException('CHAT_SMS_BALANCE_INSUFFICIENT',409);
   $public=\public_id();$id=$this->db->insert("INSERT INTO chat_sms_nudges(public_no,user_id,conversation_id,recipient_id,message_id,wave,active_dedupe_key,client_request_id,request_fingerprint,fee_cents_snapshot,mobile_hmac_snapshot,mobile_ciphertext_snapshot,delivery_status,billing_status,created_at,updated_at) VALUES(:public,:payer,:cid,:recipient,:message,:wave,:active,:request,:fingerprint,:fee,:hmac,:cipher,'queued','held',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
    ['public'=>$public,'payer'=>$payer,'cid'=>$q['conversation_id'],'recipient'=>$q['recipient_id'],'message'=>$q['message_id'],'wave'=>$q['wave'],'active'=>$q['wave'],'request'=>$q['client_request_id'],'fingerprint'=>$fingerprint,'fee'=>$q['fee_cents'],'hmac'=>$hmac,'cipher'=>$this->mobile->encrypt($phone)]);
   $rid=$this->db->insert("INSERT INTO user_wallet_reservations(public_no,user_id,amount_cents,business_type,business_id,business_key,status,expires_at,created_at,updated_at) VALUES(:public,:payer,:fee,'chat_sms_nudge',:id,:key,'held',UTC_TIMESTAMP()+INTERVAL 25 HOUR,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>\public_id(),'payer'=>$payer,'fee'=>$q['fee_cents'],'id'=>$id,'key'=>$public]);
   $this->wallet->applyLocked($wallet,'sms_hold','transfer',$q['fee_cents'],-$q['fee_cents'],$q['fee_cents'],'chat_sms_nudge',$id,'sms_hold:'.$public,'聊天短信催促费预占');
   $outbox=$this->db->insert("INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,created_at) VALUES('chat_paid_sms',:key,:payload,'pending',0,UTC_TIMESTAMP())",['key'=>$public,'payload'=>json_encode(['invite_id'=>$id],JSON_THROW_ON_ERROR)]);
   $this->db->execute('UPDATE chat_sms_nudges SET wallet_reservation_id=:reservation,outbox_id=:outbox WHERE id=:id',['reservation'=>$rid,'outbox'=>$outbox,'id'=>$id]);
   return $this->view($this->existing($payer,$q['client_request_id']));
  });
 }
 private function existing(string $payer,string $request):?array {return $this->db->fetch('SELECT * FROM chat_sms_nudges WHERE user_id=:id AND client_request_id=:request',['id'=>$payer,'request'=>$request])?:null;}
 public function status(string $payer,array $q):array {
  $this->payer($payer);if(array_keys($q)!==['client_request_id']||!is_string($q['client_request_id'])||preg_match('/^[a-f0-9]{32,64}$/D',$q['client_request_id'])!==1)throw new RuntimeException('CHAT_SMS_INVALID',400);
  $r=$this->existing($payer,$q['client_request_id']);return $r?$this->view($r):['state'=>'not_found'];
 }
 private function view(array $r):array {
  return ['state'=>$r['delivery_status'],'billing_status'=>$r['billing_status'],'fee_cents'=>(int)$r['fee_cents_snapshot'],'client_request_id'=>$r['client_request_id']];
 }
 public function preSend(array $r):bool {
  if(!$this->enabled()||strtotime((string)$r['created_at'].' UTC')<time()-900)return false;
  $phone=$this->currentMobile((string)$r['recipient_id']);if($phone===''||!hash_equals((string)$r['mobile_hmac_snapshot'],$this->mobile->hmac($phone)))return false;
  $current=$this->candidates((string)$r['user_id'],(string)$r['conversation_id'],(string)$r['recipient_id'],(string)$r['message_id']);
  return count($current)===1&&hash_equals((string)$r['wave'],$current[0]['wave']);
 }
}

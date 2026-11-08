<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts {
 interface PrivateValueCipher {public function hmac(string $plainText,string $context):string;public function encrypt(string $plainText,string $context):?string;public function decrypt(string $cipherText,string $context):string;}
 interface SettingsProvider {public function get(string $key,$default=null);public function bool(string $key,bool $default=false):bool;public function int(string $key,int $default=0):int;}
}
namespace WorkerAdmin\Core {
 class Database {
  public string|false|null $chatEnabled='1';public array $settings=[];
  public function fetchValue($sql,array $params=[]){if(str_contains((string)$sql,"setting_key='chat.enabled'"))return $this->chatEnabled;return null;}
  public function fetch($sql,array $params=[]){if(str_contains((string)$sql,'FROM system_settings')){$key=(string)($params['key']??'');return array_key_exists($key,$this->settings)?['setting_value'=>$this->settings[$key]]:null;}return null;}
  public function fetchAll($sql,array $params=[]):array{return [];}public function execute($sql,array $params=[]):int{return 1;}public function transaction(callable $cb){return $cb($this);}
 }
}
namespace WorkerAdmin\Application\Money {class UserWalletManager {}}
namespace WorkerAdmin\Application\Identity {class MobileNumberProtection {}}
namespace WorkerAdmin\Application\Chat {class ChatSmsProviderReadiness {public static function inspect($settings,bool $testing):array{return ['ready'=>true];}}}
namespace {
 $cfg=['app.env'=>'production','chat.sms_reminders_enabled'=>true,'chat.enabled'=>false];
 function app_config($key,$default=null){global $cfg;return $cfg[$key]??$default;}
 function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
 require __DIR__.'/ChatReleaseSettings.php';require __DIR__.'/ChatSmsReminders.php';require __DIR__.'/ChatCallCredentials.php';
 final class FixtureSettings implements \WorkerAdmin\Contracts\SettingsProvider {public function get(string $key,$default=null){return $default;}public function bool(string $key,bool $default=false):bool{return (bool)$this->get($key,$default);}public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}}
 final class FixtureCipher implements \WorkerAdmin\Contracts\PrivateValueCipher {
  public function hmac(string $p,string $c):string{return hash('sha256',$c.'|'.$p);}
  public function encrypt(string $p,string $c):?string{return 'enc:'.base64_encode($c.'|'.$p);}
  public function decrypt(string $v,string $c):string{$p=str_starts_with($v,'enc:')?base64_decode(substr($v,4),true):false;if(!is_string($p)||!str_starts_with($p,$c.'|'))throw new RuntimeException('bad ciphertext');return substr($p,strlen($c)+1);}
 }
 $db=new \WorkerAdmin\Core\Database();$settings=new FixtureSettings();$cipher=new FixtureCipher();
 $sms=new \WorkerAdmin\Application\Chat\ChatSmsReminders($db,new \WorkerAdmin\Application\Money\UserWalletManager(),new \WorkerAdmin\Application\Identity\MobileNumberProtection(),$cipher,static fn(array $q):array=>[],str_repeat('k',32),$settings);
 check($sms->enabled(),'database chat.enabled=1 admits paid SMS even when app_config chat.enabled is false');
 $db->chatEnabled='0';check(!$sms->enabled(),'database chat.enabled=0 closes paid SMS');
 $db->chatEnabled='1';$cfg['chat.sms_reminders_enabled']=false;check(!$sms->enabled(),'SMS child switch closes paid SMS');$cfg['chat.sms_reminders_enabled']=true;
 $stored=['schema'=>1,'managed'=>true,'app_id'=>str_repeat('a',32),'publish_privileges_verified'=>true];
 foreach(['certificate'=>'cert-fixture','customer_id'=>'customer-fixture','customer_secret'=>'secret-fixture'] as $k=>$p)$stored[$k]=$cipher->encrypt($p,'chat.calls.'.$k);
 $db->settings['chat.calls.provider_credentials']=json_encode($stored,JSON_THROW_ON_ERROR);
 $calls=new \WorkerAdmin\Application\Chat\ChatCallCredentials($db,$cipher);$normal=json_encode($calls->adminFormValues(),JSON_THROW_ON_ERROR);
 check(!str_contains($normal,'cert-fixture')&&!str_contains($normal,'customer-fixture')&&!str_contains($normal,'secret-fixture'),'normal call settings payload is secret-free');
 check($calls->revealSecret('certificate')==='cert-fixture'&&$calls->revealSecret('customer_secret')==='secret-fixture','explicit call secret reveal remains available');
 $go=file_get_contents(__DIR__.'/ChatGoRuntimeConfig.php');$a=strpos($go,'public function adminFormValues():array');$b=strpos($go,'public function revealSecret(string $name):string');
 check($a!==false&&$b!==false&&$b>$a,'Go runtime has secret-free form and explicit reveal');$form=substr($go,$a,$b-$a);
 foreach(['redis_password','tencent_asr_secret_id','tencent_asr_secret_key','wechat_callback_token','wechat_callback_aes_key'] as $n)check(!str_contains($form,$n),'normal Go form excludes '.$n);
 $module=file_get_contents(__DIR__.'/ChatSettingsAdminModule.php');
 foreach(["reveal_secret","password_verify","Cache-Control: no-store","chat.secret_reveal","仅超级管理员可查看聊天密钥"] as $n)check(str_contains($module,$n),'reveal endpoint control '.$n);
 check(!preg_match("/foreach\(\['redis_enabled'[^\n]*'redis_password'/",$module),'failed Go save never re-echoes Redis password');
 $cm=file_get_contents(__DIR__.'/ChatCallsAdminModule.php');check(str_contains($cm,"!in_array(\$key,['certificate','customer_id','customer_secret'],true)"),'failed call save excludes provider secrets');
 $sv=file_get_contents(__DIR__.'/chat-settings.php');
 check((bool)preg_match('/name="tencent_asr_secret_key"[^>]*value=""/',$sv),'Tencent SecretKey input starts blank');
 check((bool)preg_match('/name="redis_password"[^>]*value=""/',$sv),'Redis password input starts blank');
 check(!str_contains($sv,"\$secretValues['tencent_asr_secret_key']"),'settings HTML cannot render saved Tencent SecretKey');
 $cv=file_get_contents(__DIR__.'/chat-calls.php');check(!str_contains($cv,"\$credentials[\$key]??''"),'call settings HTML cannot render provider secret');
 check(str_contains($cv,"\$secretReveal('call_'.\$key"),'call settings offers controlled reveal');
 $modal=file_get_contents(__DIR__.'/chat-secret-reveal-modal.php');check(str_contains($modal,'autocomplete="current-password"')&&str_contains($modal,'每次查看都要重新验证'),'each reveal requests current admin password');
 echo "Chat audit P1 PHP contracts passed\n";
}

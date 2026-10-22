<?php

declare(strict_types=1);

// Pure isolated coordinator tests. No real AppSecret, WeChat or notifications.
$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{$prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;$path=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require_once $path;});
use WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider;
function app_config(string $key,$default=null){return $GLOBALS['s07_settings'][$key]??$default;}
function setting(string $key,$default=null){return $GLOBALS['s07_settings'][$key]??$default;}
function base_path(string $path=''):string{return $GLOBALS['s07_root'].($path!==''?'/'.$path:'');}
function s07_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function s07_remove(string $dir):void{foreach(scandir($dir)?:[] as $name){if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;if(is_dir($path)&&!is_link($path))s07_remove($path);else unlink($path);}rmdir($dir);}
function s07_provider(string $directory,string $secret='fixture-secret',?callable $request=null,string $environment='test'):WeChatAccessTokenProvider{
 return new WeChatAccessTokenProvider($environment,'chat-platform-app',$secret,$directory.'/tokens',$directory.'/export',$request??static function():array{return ['access_token'=>'s07_fixture_official_token','expires_in'=>7200];});
}
$mode=$argv[1]??'';
if(in_array($mode,['--child','--export'],true)){
 $directory=$argv[2]??'';
 if(!is_dir($directory)||is_link($directory)||preg_match('/^bixuan-s07-[a-zA-Z0-9]+$/D',basename($directory))!==1)throw new RuntimeException('Explicit S07 fixture directory required');
 if($mode==='--export'){
  if(getenv('CHAT_AUTH_TEST_INTEGRATION')!=='1')throw new RuntimeException('Explicit integration fixture required');
  s07_provider($directory)->get();echo "exported\n";exit;
 }
 $provider=s07_provider($directory,'fixture-secret',static function()use($directory):array{
  file_put_contents($directory.'/requests','refresh\n',FILE_APPEND|LOCK_EX);usleep(200000);
  return ['access_token'=>'s07_fixture_official_token','expires_in'=>7200];
 });
 $provider->get();echo "ok\n";exit;
}
$directory=sys_get_temp_dir().'/bixuan-s07-'.bin2hex(random_bytes(6));mkdir($directory,0700);
try{
 // Eight independent PHP processes contend for the same credential lock.
 $processes=[];
 for($i=0;$i<8;$i++){
  $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--child',$directory],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  s07_assert(is_resource($process),'child startup');fclose($pipes[0]);$processes[]=[$process,$pipes];
 }
 foreach($processes as [$process,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);s07_assert(proc_close($process)===0&&trim($out)==='ok','concurrent credential child failed: '.$error);}
 s07_assert(substr_count((string)file_get_contents($directory.'/requests'),'refresh')===1,'multiple concurrent refreshers');
 $provider=s07_provider($directory);$token=$provider->get();$export=$provider->exportPath();$data=json_decode((string)file_get_contents($export),true);
 s07_assert($data['env']==='test'&&$data['app_id']==='chat-platform-app'&&$data['generation']===$provider->generation($token),'export scope/generation');
 s07_assert(!array_key_exists('credential_hash',$data)&&strpos(json_encode($data),'fixture-secret')===false,'AppSecret exported');
 s07_assert((fileperms($export)&0777)===0640,'export mode');
 s07_assert($provider->metadata()['status']==='ready','read-only export preflight');
 chmod($export,0644);clearstatcache(true,$export);try{$provider->metadata();throw new LogicException('insecure export reported ready');}catch(RuntimeException $expected){}
 $provider->get();clearstatcache(true,$export);s07_assert((fileperms($export)&0777)===0640,'coordinator did not repair export permissions');
 $generation=$provider->generation($token);s07_assert($provider->rejectGeneration(str_repeat('a',64))===false,'unrelated invalidation');
 s07_assert($provider->rejectGeneration($generation),'rejected generation not invalidated');
 $revoked=json_decode((string)file_get_contents($export),true);s07_assert($revoked['token']===''&&$revoked['expires_at']===0,'revocation must stop cached Go generation');
 $fresh=s07_provider($directory,'fixture-secret',static function():array{return ['access_token'=>'s07_fixture_rotated_token','expires_in'=>7200];});$fresh->get();
 s07_assert($provider->rejectGeneration($generation)===false,'late invalidation erased a newer token');
 // TTL must never be inflated; valid old token survives a refresh outage.
 $shortDir=$directory.'/short';mkdir($shortDir,0700);
 $short=s07_provider($shortDir,'fixture-secret',static function():array{return ['access_token'=>'s07_fixture_short_token','expires_in'=>60];});$short->get();$shortData=json_decode((string)file_get_contents($short->exportPath()),true);
 s07_assert($shortData['expires_at']-$shortData['issued_at']===60,'provider TTL extended');
 $failing=s07_provider($shortDir,'fixture-secret',static function():array{throw new RuntimeException('secret fixture must not leak');});s07_assert($failing->get()==='s07_fixture_short_token','valid credential outage fallback');
 $expiry=json_decode((string)file_get_contents($short->exportPath()),true);s07_assert($expiry['expires_at']===$shortData['expires_at'],'fallback extended expiry');
 foreach(glob($shortDir.'/tokens/*.json')?:[] as $file){$row=json_decode((string)file_get_contents($file),true);$row['issued_at']=time()-100;$row['expires_at']=time()-1;file_put_contents($file,json_encode($row));}
 try{$failing->get();throw new LogicException('expired credential used');}catch(RuntimeException $e){s07_assert(strpos($e->getMessage(),'secret fixture')===false,'raw credential transport error leaked');}
 // A changed AppSecret cannot reuse the previous configuration cache.
 $rotated=s07_provider($directory,'new-fixture-secret',static function():array{return ['access_token'=>'s07_fixture_new_secret','expires_in'=>7200];});s07_assert($rotated->get()==='s07_fixture_new_secret','secret rotation reused old credential');
 $other=s07_provider($directory,'new-fixture-secret',static function():array{return ['access_token'=>'s07_fixture_production','expires_in'=>7200];},'production');s07_assert($other->get()==='s07_fixture_production','cross-environment credential collision');
 // All five legacy callers use the same coordinator cache, including the
 // subscription Infrastructure adapter. No constructor injection in runtime.
 $GLOBALS['s07_root']=$directory.'/legacy';mkdir($GLOBALS['s07_root'],0700);
 $GLOBALS['s07_settings']=['app.env'=>'test','wechat.miniapp_appid'=>'chat-platform-app','wechat.miniapp_secret'=>'fixture-secret','chat.safety_credential_export_enabled'=>true];
 $legacy=new WeChatAccessTokenProvider('test','chat-platform-app','fixture-secret',base_path('storage/private/wechat-tokens'),base_path('storage/private/chat-credentials'),static function():array{return ['access_token'=>'s07_fixture_shared_legacy','expires_in'=>7200];});$legacy->get();
 foreach(['Services\\WeChatContentSecurityService','Services\\WeChatCloudSyncService','Services\\MiniProgramCodeService','Services\\WorkerCloudGatewayService','Infrastructure\\External\\WeChatSubscribeGateway'] as $name){
  $class='WorkerAdmin\\'.$name;$object=new $class();$method=new ReflectionMethod($class,'accessToken');s07_assert($method->invoke($object)==='s07_fixture_shared_legacy','legacy caller has independent refresh path: '.$name);
 }
 echo "S07统一凭证并发、代次撤销、真实TTL、失效关闭、环境隔离及五个PHP调用方通过\n";
}finally{s07_remove($directory);}

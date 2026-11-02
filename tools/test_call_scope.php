<?php
declare(strict_types=1);
if(getenv('CALL_SCOPE_TEST')!=='1'||basename(dirname(__DIR__))!=='workers-server')throw new RuntimeException('Independent test repository only');
$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'WorkerAdmin\\')){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
set_error_handler(function($level,$message,$file,$line){if(error_reporting()&$level)throw new ErrorException($message,0,$level,$file,$line);});
use WorkerAdmin\Domain\Chat\CallQuotaScope;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatCallAdministration;
function app_config($key,$fallback=null){return $GLOBALS['fixtureAppConfig'][$key]??$fallback;}
function setting($key,$fallback=null){return $fallback;}
function ck($ok,$label){if(!$ok)throw new RuntimeException($label);$GLOBALS['passed'][]=$label;}
function reject($fn,$label){try{$fn();}catch(RuntimeException|InvalidArgumentException $e){ck(true,$label);return;}throw new RuntimeException('accepted: '.$label);}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]){return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function csrf(){return new class{function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
function asset_url($name){return '/server/assets/'.$name;}
$passed=[];
ck(CallQuotaScope::fromInput('shengwang',['scope_type'=>'app','app_id'=>'abc123','scope_key'=>'spoofed'])==='shengwang:app:abc123','app scope derived from the single AppID field');
ck(CallQuotaScope::fromInput('shengwang',['scope_type'=>'account','scope_account'=>'12345','app_id'=>'ignored'])==='shengwang:account:12345','account scope derives from account ID');
ck(CallQuotaScope::fromInput('wechat',['scope_type'=>'app','app_id'=>'wx0123456789abcdef'])==='wechat:app:wx0123456789abcdef','providers stay distinct');
ck(CallQuotaScope::fromInput('shengwang',['scope_key'=>'existing:legacy-account'])==='existing:legacy-account','old clients preserve old scope key');
foreach([
 ['scope_type'=>'','scope_key'=>'bypass'],['scope_type'=>['app']],['scope_type'=>'other'],['scope_type'=>'app','app_id'=>[]],
 ['scope_type'=>'account','scope_account'=>'../bad'],['scope_type'=>'account','scope_account'=>'bad:id'],
 ['scope_type'=>'account','scope_account'=>''],['scope_type'=>'account','scope_account'=>str_repeat('x',97)]
] as $input)reject(fn()=>CallQuotaScope::fromInput('shengwang',$input),'invalid explicit scope never falls back to a legacy value');
ck(CallQuotaScope::describe('shengwang','shengwang:account:12345')['account']==='12345','saved account can prefill plain ID');
ck(CallQuotaScope::describe('shengwang','arbitrary-legacy')['type']==='','unknown old scope is not reclassified');
$port=(int)getenv('MYSQL_PORT');if(!$port)throw new RuntimeException('ephemeral MySQL required');
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='call_scope_'.bin2hex(random_bytes(4));$pdo->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try{
$db=new Database(['host'=>'127.0.0.1','port'=>$port,'database'=>$name,'username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4']);
$db->execute('CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(32),status VARCHAR(24),must_change_password INT)');
$db->execute("INSERT INTO admin_users VALUES(1,'super_admin','active',0),(2,'operations','active',0)");
$sql=file_get_contents($root.'/server/database/upgrades/2.7.0.sql');
if(preg_match('/-- CHAT_BEGIN calls\R(.*?)-- CHAT_END calls/s',$sql,$m)!==1)throw new RuntimeException('shipping calls DDL required');
$db->execute('SET FOREIGN_KEY_CHECKS=0');
foreach(explode(';',$m[1]) as $stmt)if(trim($stmt)!=='')$db->execute($stmt);
$db->execute('SET FOREIGN_KEY_CHECKS=1');
$gateway=new class implements \WorkerAdmin\Contracts\ChatInternalGateway {
 public function eligible(string $operation):bool{throw new LogicException('unexpected');}
 public function smsCandidates(array $query):array{throw new LogicException('unexpected');}
 public array $stops=[];
 public function confirmCallStopped(string $call,string $epoch,string $proof):void{$this->stops[]=[$call,$epoch,$proof];}
};
$service=new ChatCallAdministration($db,$gateway);
$now=time();
$base=['_action'=>'pool','revision'=>'1','reason'=>'synthetic entitlement evidence','provider'=>'shengwang','scope_type'=>'app','app_id'=>str_repeat('1',32),'cycle_key'=>'fixture-cycle',
 'valid_from'=>date('Y-m-d\TH:i:s',$now-60),'expires_at'=>date('Y-m-d\TH:i:s',$now+3600),
 'free_total'=>'100','budget_limit'=>'100','official_used'=>'0','safety_margin'=>'0','external_uncertainty'=>'0','max_age_seconds'=>'300',
 'pool_enabled'=>'1','verified'=>'1','hard_stop_verified'=>'1','evidence_hash'=>hash('sha256','fixture actual evidence content'),
 'audio_numerator'=>'143','audio_denominator'=>'100','audio_quantum'=>'1','video_numerator'=>'429','video_denominator'=>'100','video_quantum'=>'1'];
$service->change($base+['scope_key'=>'tampered-client-key'],1,'super_admin');
$rows=$db->fetchAll('SELECT * FROM chat_call_resource_pools ORDER BY id');
ck(count($rows)===1&&$rows[0]['scope_key']==='shengwang:app:'.$base['app_id'],'real admin save ignores supplied key and persists derived scope');
$profile=json_decode($rows[0]['meter_json'],true);$facts=json_decode($rows[0]['facts_json'],true);
ck($facts['quota_scope_id']===$rows[0]['scope_key'],'resource decision retains exact normalized identity');
ck($profile['evidence_hash']===$base['evidence_hash'],'auto-generated evidence hash is retained unchanged');
$profileKeys=array_keys($profile);sort($profileKeys);
ck($profileKeys===['app_id','audio','entitlement_confirmed','evidence_hash','usage_known','video'],'Go meter profile format stays unchanged');
reject(fn()=>$service->change(array_replace($base,['scope_type'=>'account','scope_account'=>'owner']),1,'super_admin'),'same AppID cannot clone quota by switching account/application mode');
$account=array_replace($base,['scope_type'=>'account','scope_account'=>'account42','app_id'=>str_repeat('2',32)]);
$service->change($account,1,'super_admin');
reject(fn()=>$service->change(array_replace($account,['app_id'=>str_repeat('3',32)]),1,'super_admin'),'same shared account cannot clone quota under another AppID');
$legacy=$base;unset($legacy['scope_type']);$legacy['scope_key']='old-physical-scope';$legacy['app_id']=str_repeat('4',32);
$service->change($legacy,1,'super_admin');
reject(fn()=>$service->change(array_replace($base,['app_id'=>$legacy['app_id']]),1,'super_admin'),'same legacy AppID cannot be duplicated by canonical prefix');
reject(fn()=>$service->change(array_replace($base,['app_id'=>str_repeat('5',32),'scope_type'=>'','scope_key'=>'spoofed']),1,'super_admin'),'new empty scope is rejected in real transaction');
$service->change(array_replace($base,['app_id'=>str_repeat('5',32),'evidence_hash'=>'','verified'=>'0','hard_stop_verified'=>'0']),1,'super_admin');
ck(true,'registration accepts no evidence and no verification');
reject(fn()=>$service->change(array_replace($base,['app_id'=>str_repeat('5',32),'budget_limit'=>'101']),1,'super_admin'),'scope changes do not raise free allowance');
reject(fn()=>$service->change(array_replace($base,['app_id'=>str_repeat('5',32)]),2,'operations'),'read-only administrator cannot register resources');
ck((int)$db->fetchValue('SELECT COUNT(*) FROM chat_call_resource_pools')===4,'rejected changes leave no partial pool');
ck((int)$db->fetchValue('SELECT COUNT(*) FROM chat_call_admin_audit')===4,'only successful registrations create audit rows');

$pending=$db->fetch("SELECT * FROM chat_call_resource_pools WHERE JSON_UNQUOTE(JSON_EXTRACT(meter_json,'$.app_id'))=:app",['app'=>str_repeat('5',32)]);
$pendingFacts=json_decode($pending['facts_json'],true);
ck(!$pendingFacts['verified']&&!$pendingFacts['hard_stop_verified'],'no file does not manufacture verification');
ck(json_decode($pending['meter_json'],true)['evidence_hash']==='','no file remains an empty digest');
$entry=json_decode($db->fetchValue("SELECT details_json FROM chat_call_admin_audit ORDER BY id DESC LIMIT 1"),true);
ck($entry['evidence_source']==='not_provided','audit labels missing support documents honestly');
$service->change(['_action'=>'reverify','revision'=>'1','reason'=>'fixture recheck','pool_id'=>(string)$pending['id'],'pool_revision'=>'1','pool_enabled'=>'0','verified'=>'0','hard_stop_verified'=>'0'],1,'super_admin');
ck(true,'reverification can be saved without a file');
$at=date('Y-m-d\TH:i:s',time());
$service->change(['_action'=>'import','revision'=>'1','reason'=>'fixture usage entry','pool_id'=>(string)$pending['id'],'pool_revision'=>'2','official_used'=>'0','observed_at'=>$at,'covered_through'=>$at],1,'super_admin');
ck($db->fetchValue('SELECT evidence_hash FROM chat_call_usage_snapshots ORDER BY id DESC LIMIT 1')==='','usage import can omit a supporting file');
$stop=['_action'=>'confirmed_stop','revision'=>'1','reason'=>'fixture supplier side confirmed','call_id'=>'77','attempt_epoch'=>'1'];
reject(fn()=>$service->change($stop,1,'super_admin'),'manual stop without a file needs explicit confirmation');
$before=$db->fetchAll('SELECT local_uncovered,reserved_units FROM chat_call_resource_pools ORDER BY id');
$service->change($stop+['stop_confirmed'=>'1'],1,'super_admin');
ck(count($gateway->stops)===1&&preg_match('/^[a-f0-9]{64}$/D',$gateway->stops[0][2])===1,'manual confirmation records an operation digest and forwards it');
$entry=json_decode($db->fetchValue("SELECT details_json FROM chat_call_admin_audit WHERE action='confirmed_stop' ORDER BY id DESC LIMIT 1"),true);
ck($entry['evidence_source']==='admin_confirmation','manual operation digest is never called a supplier test report');
ck($before===$db->fetchAll('SELECT local_uncovered,reserved_units FROM chat_call_resource_pools ORDER BY id'),'PHP manual confirmation does not itself release reservations');
$service->change($stop+['evidence_hash'=>hash('sha256','legacy document')],1,'super_admin');
ck(count($gateway->stops)===2,'legacy document-based confirmations remain compatible');
$db->execute('CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(32),is_public INT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME)');
$cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {
 public function hmac(string $v,string $c):string{return hash_hmac('sha256',$v,$c);}
 public function encrypt(string $v,string $c):?string{return 'fixture:'.base64_encode($c.'|'.$v);}
 public function decrypt(string $v,string $c):string{$s=base64_decode(substr($v,8),true);if(!is_string($s)||!str_starts_with($s,$c.'|'))throw new RuntimeException('fixture secret in exception');return substr($s,strlen($c)+1);}
};
$credentialService=new \WorkerAdmin\Application\Chat\ChatCallCredentials($db,$cipher);
$network=0;
$missing=(new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,static function()use(&$network){$network++;throw new LogicException('network must not run');}))->run();
ck($network===0&&!$missing['connected'],'missing credentials report deficiencies without contacting a provider');
$credentialService->saveLocked(['shengwang_app_id'=>str_repeat('9',32),'certificate'=>str_repeat('a',32),'customer_id'=>'fixture-rest-id','customer_secret'=>'fixture-rest-secret','publish_privileges_verified'=>'0'],1);
$positive=static function($path,$id,$secret)use(&$network){
 $network++;ck(preg_match('#^/dev/v1/channel/user/[a-f0-9]{32}/bx_probe_[a-f0-9]{24}$#D',$path)===1,'diagnostic uses only a random query channel');
 ck($id==='fixture-rest-id'&&$secret==='fixture-rest-secret','diagnostic reads saved REST credentials');
 return ['status'=>200,'body'=>'{"success":true,"data":{"channel_exist":false}}'];
};
$report=(new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,$positive))->run();
ck($report['connected'],'official successful query is reported as a connection check');
ck(end($report['checks'])['state']==='pending','real stop is explicitly pending even after a successful query');
ck(!str_contains(json_encode($report),'fixture-rest-secret')&&!str_contains(json_encode($report),str_repeat('a',32)),'diagnostic report contains no secret');
ck($before===$db->fetchAll('SELECT local_uncovered,reserved_units FROM chat_call_resource_pools ORDER BY id'),'connection check never changes pool accounting');
foreach([
 ['status'=>401,'body'=>'fixture-rest-secret'],
 ['status'=>403,'body'=>'fixture-rest-secret'],
 ['status'=>429,'body'=>'fixture-rest-secret'],
 ['status'=>500,'body'=>'fixture-rest-secret'],
 ['status'=>200,'body'=>'invalid'],
 ['status'=>200,'body'=>'{"success":false,"data":{"channel_exist":false}}'],
 ['status'=>200,'body'=>'{"success":true,"data":{"channel_exist":"false"}}'],
 ['status'=>200,'body'=>str_repeat('x',16385)],
] as $failure){
 $bad=(new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,static fn()=>$failure))->run();
 ck(!$bad['connected']&&!str_contains(json_encode($bad),'fixture-rest-secret'),'provider errors cannot appear as success or reveal the raw response');
}
$bad=(new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,static function(){throw new RuntimeException('fixture-rest-secret');}))->run();
ck(!$bad['connected']&&!str_contains(json_encode($bad),'fixture-rest-secret'),'transport exceptions are summarized safely');

$controlCalls=[];
$controlCheck=new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,
 static function($path,$id,$secret,$method='GET',$body=null)use(&$controlCalls){
  $controlCalls[]=[$path,$method,$body];
  if($method==='POST'){
   ck($path==='/dev/v1/kicking-rule','control test uses only the official stop endpoint');
   ck(preg_match('/^bx_probe_[a-f0-9]{24}$/D',$body['cname'])===1,'control test never addresses an actual call channel');
   ck($body['time_in_seconds']===10&&$body['privileges']===['join_channel'],'control test rule is scoped and short-lived');
   return ['status'=>200,'body'=>'{"status":"success"}'];
  }
  return ['status'=>200,'body'=>'{"success":true,"data":{"channel_exist":false}}'];
 });
$controlReport=$controlCheck->run(true);
ck($controlReport['connected']&&$controlReport['control_checked']&&count($controlCalls)===3,'manual control test performs query, stop rule, then query');
ck(end($controlReport['checks'])['state']==='pending','empty-channel control success never claims true media stop');
ck($controlCalls[2][0]==='/dev/v1/channel/user/'.str_repeat('9',32).'/'.$controlCalls[1][2]['cname'],'control followup queries the same isolated channel');
$failedControl=(new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,
 static fn($path,$id,$secret,$method='GET')=>$method==='POST'?['status'=>403,'body'=>'fixture-rest-secret']:['status'=>200,'body'=>'{"success":true,"data":{"channel_exist":false}}']))->run(true);
ck($failedControl['connected']&&!$failedControl['control_checked']&&!str_contains(json_encode($failedControl),'fixture-rest-secret'),'rejected control permission remains a failed control test');
$busyControl=(new \WorkerAdmin\Application\Chat\ChatCallConnectionCheck($credentialService,
 static function($path,$id,$secret,$method='GET')use(&$seenQueries){if($method==='POST')return ['status'=>200,'body'=>'{"status":"success"}'];$seenQueries=($seenQueries??0)+1;return ['status'=>200,'body'=>$seenQueries===1?'{"success":true,"data":{"channel_exist":false}}':'{"success":true,"data":{"channel_exist":true}}'];}))->run(true);
ck(!$busyControl['control_checked'],'a non-empty channel result does not pass control verification');


// Actual database round trips for runtime ASR and combined call configuration.
$runtime=new \WorkerAdmin\Application\Chat\ChatGoRuntimeConfig($db,$cipher);
ck($runtime->configuration()['asr_api_name']==='SentenceASR','new ASR forms have a preset without manually entering an API name');
$runtime->save(['asr_profile'=>'sentence_asr','python_binary'=>'/www/wwwroot/workers-test/chat/.venv313/bin/python'],1,'super_admin');
ck($runtime->configuration()['asr_profile']==='sentence_asr'&&$runtime->configuration()['asr_api_name']==='SentenceASR','preset is persisted and read back from the database');
$runtime->save(['ffmpeg_binary'=>'/usr/bin/ffmpeg'],1,'super_admin');
ck($runtime->configuration()['asr_api_name']==='SentenceASR'&&$runtime->configuration()['python_binary']==='/www/wwwroot/workers-test/chat/.venv313/bin/python','saving unrelated fields preserves saved ASR and Python');
$runtime->save(['asr_profile'=>'custom','asr_api_name'=>'AuthorizedCustomASR','asr_data_encoding'=>'json_string','redis_enabled'=>'1','redis_address'=>'127.0.0.1:6380'],1,'super_admin');
$runtime->save(['python_binary'=>'/www/wwwroot/workers-test/chat/.venv313/bin/python'],1,'super_admin');
$readback=$runtime->configuration();
ck($readback['asr_api_name']==='AuthorizedCustomASR'&&$readback['asr_data_encoding']==='json_string','custom authorized API and data encoding survive partial saves');
ck($db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.go_redis_address'")==='127.0.0.1:6380','unrelated save preserves Redis connection');
$beforeAsr=$db->fetchAll('SELECT setting_key,setting_value FROM system_settings ORDER BY setting_key');
reject(fn()=>$runtime->save(['asr_profile'=>'sentence_asr','wechat_callback_token'=>'short','wechat_callback_aes_key'=>'invalid'],1,'super_admin'),'invalid callback rejects the whole runtime transaction');
ck($beforeAsr===$db->fetchAll('SELECT setting_key,setting_value FROM system_settings ORDER BY setting_key'),'failed callback never erases the previously saved ASR');
reject(fn()=>$runtime->save(['asr_profile'=>'custom','asr_api_name'=>''],1,'super_admin'),'custom empty API is rejected instead of silently clearing a saved value');
$runtime->save(['asr_profile'=>'disabled'],1,'super_admin');
$runtime->save(['ffprobe_binary'=>'/usr/bin/ffprobe'],1,'super_admin');
ck($runtime->configuration()['asr_profile']==='disabled'&&$runtime->configuration()['asr_api_name']==='','explicit ASR disable persists across later saves');
$runtime->save(['asr_profile'=>'sentence_asr'],1,'super_admin');
reject(fn()=>$runtime->save(['asr_profile'=>'disabled'],2,'operations'),'ASR changes remain super-admin only');
$GLOBALS['fixtureAppConfig']['database']=['host'=>'203.0.113.1'];
reject(fn()=>$runtime->status([]),'runtime diagnostic may fail independently of saved configuration');
ck($runtime->configuration()['asr_api_name']==='SentenceASR','saved form readback survives a capability diagnostic failure');
unset($GLOBALS['fixtureAppConfig']['database']);

$settingsBefore=$db->fetch('SELECT revision,config_json FROM chat_call_settings WHERE id=1');
$credentialsBefore=$db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.calls.provider_credentials'");
$manager=new ChatCallAdministration($db,$gateway,$credentialService);
$combined=['_action'=>'configuration','revision'=>'1','reason'=>'','enabled'=>'0','audio_enabled'=>'1','video_enabled'=>'1','allow_fallback'=>'1',
 'audio_first'=>'shengwang','video_first'=>'shengwang','window_seconds'=>'60','stop_margin_seconds'=>'30','heartbeat_seconds'=>'10','max_active_calls'=>'20',
 'shengwang_app_id'=>str_repeat('9',32),'certificate'=>str_repeat('a',32),'customer_id'=>'fixture-rest-id','customer_secret'=>'fixture-rest-secret','publish_privileges_verified'=>'0'];
$db->execute('UPDATE chat_call_resource_pools SET reserved_units=1 WHERE id=1');
$manager->change($combined,1,'super_admin');
$combinedSaved=$db->fetch('SELECT revision,config_json FROM chat_call_settings WHERE id=1');
ck((string)$combinedSaved['revision']==='2'&&!json_decode($combinedSaved['config_json'],true)['enabled'],'combined form saves switches with an unchanged credential identity during an active reservation');
ck($db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='chat.calls.provider_credentials'")===$credentialsBefore,'unchanged displayed plaintext does not rotate or rewrite encrypted secrets');
reject(fn()=>$manager->change(array_replace($combined,['revision'=>'2','enabled'=>'1','certificate'=>str_repeat('b',32)]),1,'super_admin'),'actual signing identity replacement remains blocked while exposure exists');
ck($db->fetch('SELECT revision,config_json FROM chat_call_settings WHERE id=1')===$combinedSaved,'failed credential change rolls back function settings atomically');
$db->execute('UPDATE chat_call_resource_pools SET reserved_units=0 WHERE id=1');
$manager->change(array_replace($combined,['revision'=>'2','enabled'=>'1','publish_privileges_verified'=>'1']),1,'super_admin');
ck(json_decode($db->fetchValue('SELECT config_json FROM chat_call_settings WHERE id=1'),true)['enabled']&&$credentialService->current()['publish_privileges_verified'],'combined save commits the function and provider confirmation together');
$GLOBALS['fixtureAppConfig']['app.env']='testing';
$disabled=json_decode($settingsBefore['config_json'],true);foreach(['enabled','audio_enabled','video_enabled'] as $key)$disabled[$key]=false;
$db->execute('UPDATE chat_call_settings SET config_json=:config WHERE id=1',['config'=>json_encode($disabled)]);
$effective=(new \WorkerAdmin\Application\Chat\ChatCallResources($db))->settings()['config'];
ck(!$effective['enabled']&&!$effective['audio_enabled']&&!$effective['video_enabled'],'testing does not force explicitly disabled audio and video switches back on');
unset($GLOBALS['fixtureAppConfig']['app.env']);
$db->execute('UPDATE chat_call_settings SET revision=:revision,config_json=:config WHERE id=1',['revision'=>$settingsBefore['revision'],'config'=>$settingsBefore['config_json']]);
$db->execute("UPDATE system_settings SET setting_value=:value WHERE setting_key='chat.calls.provider_credentials'",['value'=>$credentialsBefore]);


// Controller permission, CSRF, PRG and cooldown checks use empty saved credentials: no real network.
$db->execute('DELETE FROM system_settings');
class FixtureAuth extends \WorkerAdmin\Core\Auth {
 public function __construct(public $role){}
 public function user(){return ['id'=>$this->role==='super_admin'?1:2,'role'=>$this->role];}
 public function can($p){return $this->role==='super_admin'||$p==='chat.calls.view';}
 public function requirePermission($p){if(!$this->can($p))throw new RuntimeException('fixture denied',403);}
}
class FixtureCsrf extends \WorkerAdmin\Core\Csrf {
 public int $checks=0;public bool $deny=false;
 public function __construct(){}
 public function verifyRequest(){$this->checks++;if($this->deny)throw new RuntimeException('csrf rejected');}
}
class FixtureContainer {
 public function __construct(private $db){}
 public function calls(){return new class {
  public function current($page){return [];}
  public function change($input,$admin,$role){$GLOBALS['fixtureChanged']=true;if(($input['force_error']??'')==='1')throw new RuntimeException('资源已登记，请查看已有记录');return ($input['_action']??'')==='pool'?['pool_id'=>'42']:[];}
 };}
 public function callCredentials(){return $GLOBALS['credentialService'];}
}
class_alias(FixtureContainer::class,'WorkerAdmin\\Foundation\\ChatApplicationContainer');
function audit($action,$entity,$id,$text){$GLOBALS['fixtureAudits'][]=[$action,$text];}
function flash($type,$text){}
function redirect($url){$GLOBALS['fixtureRedirect']=$url;}
function view($name,$data){$GLOBALS['fixtureView']=$data;return '';}
$_SESSION=[];$_SERVER['REQUEST_METHOD']='POST';$_GET=['section'=>'register'];$_POST=['_action'=>'check_connection'];
$csrfService=new FixtureCsrf();
$module=new \WorkerAdmin\Http\Admin\Modules\ChatCallsAdminModule($db,new FixtureAuth('super_admin'),$csrfService);
$module->dispatch('chat.calls');
ck($csrfService->checks===1&&isset($_SESSION['chat_call_connection_report'])&&isset($fixtureRedirect),'authorized diagnostic is protected by CSRF and redirects after recording a report');
$stamp=$_SESSION['chat_call_connection_report']['timestamp'];$auditCount=count($fixtureAudits);
$module->dispatch('chat.calls');
ck($_SESSION['chat_call_connection_report']['timestamp']===$stamp&&count($fixtureAudits)===$auditCount,'rapid repeat is blocked without another request');
$csrfService->deny=true;reject(fn()=>$module->dispatch('chat.calls'),'bad CSRF is rejected before the diagnostic');$csrfService->deny=false;
reject(fn()=>(new \WorkerAdmin\Http\Admin\Modules\ChatCallsAdminModule($db,new FixtureAuth('operations'),$csrfService))->dispatch('chat.calls'),'read-only administrators cannot trigger provider requests');
$_SERVER['REQUEST_METHOD']='GET';
(new \WorkerAdmin\Http\Admin\Modules\ChatCallsAdminModule($db,new FixtureAuth('operations'),$csrfService))->dispatch('chat.calls');
ck($fixtureView['connectionReport']===null,'other roles cannot read another administrator report');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['_action'=>'settings'];
$module->dispatch('chat.calls');ck(!isset($_SESSION['chat_call_connection_report']),'a successful configuration change invalidates old diagnostics');


$_POST=['_action'=>'pool','free_total'=>'10000','budget_limit'=>'9000','official_used'=>'2'];
$module->dispatch('chat.calls');
ck(str_contains($fixtureRedirect,'section=resources')&&str_ends_with($fixtureRedirect,'#chat-pool-42'),'successful registration redirects to the actual new resource anchor');
$_POST=['_action'=>'pool','free_total'=>'10000','budget_limit'=>'9000','official_used'=>'2','force_error'=>'1'];
$module->dispatch('chat.calls');
ck($fixtureView['formValues']['free_total']==='10000'&&$fixtureView['formValues']['official_used']==='2','failed registration retains entered amount fields');
$unknownResult=$service->change(array_replace($base,['app_id'=>str_repeat('6',32),'official_used'=>'','verified'=>'1','hard_stop_verified'=>'1']),1,'super_admin');
ck(isset($unknownResult['pool_id']),'real successful service returns its new pool identifier');
$unknownRowDraft=$db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id',['id'=>$unknownResult['pool_id']]);
$unknownSummary=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::summary($unknownRowDraft);
ck($unknownSummary['official_used']===null&&$unknownSummary['remaining']===null&&!$unknownSummary['verified'],'blank official usage is unknown and cannot activate a pool');
$service->change(['_action'=>'reverify','revision'=>'1','pool_id'=>$unknownResult['pool_id'],'pool_revision'=>'1','reason'=>'fixture verify unknown','pool_enabled'=>'1','verified'=>'1','hard_stop_verified'=>'1'],1,'super_admin');
$unknownFresh=$db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id',['id'=>$unknownResult['pool_id']]);
ck(!json_decode($unknownFresh['facts_json'],true)['verified'],'reverification cannot silently turn unknown usage into confirmed zero');
ck(json_decode($unknownFresh['meter_json'],true)['entitlement_confirmed']===true,'explicit entitlement confirmation persists even when official usage is still unknown');
ck(str_contains(implode('；',\WorkerAdmin\Domain\Chat\CallQuotaDisplay::blockers($unknownFresh,['status'=>'unavailable'])),'官方已用量'),'unknown usage has an actionable explanation');
$at=date('Y-m-d\TH:i:s',time());
$service->change(['_action'=>'import','revision'=>'1','pool_id'=>$unknownResult['pool_id'],'pool_revision'=>'2','reason'=>'fixture confirmed zero','official_used'=>'0','observed_at'=>$at,'covered_through'=>$at],1,'super_admin');
$knownZero=$db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id',['id'=>$unknownResult['pool_id']]);
ck(\WorkerAdmin\Domain\Chat\CallQuotaDisplay::summary($knownZero)['official_used']===0,'explicit manual zero is displayed as known zero');
$firstRow=$db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=1');
$firstFacts=json_decode($firstRow['facts_json'],true);$firstFacts['safety_margin']='6000';$firstFacts['external_uncertainty']='6000';
$db->execute('UPDATE chat_call_resource_pools SET official_used=12000,local_uncovered=6000,reserved_units=18000,facts_json=:facts WHERE id=1',['facts'=>json_encode($firstFacts)]);
foreach([[12000,1],[6000,0]] as $i=>$segment)$db->execute('INSERT INTO chat_call_resource_ledger(pool_id,call_id,attempt_epoch,operation_id,consumed_units,through_at,covered,created_at) VALUES(1,77,1,:operation,:units,:at,:covered,UTC_TIMESTAMP(6))',['operation'=>hash('sha256','summary-'.$i),'units'=>$segment[0],'at'=>time(),'covered'=>$segment[1]]);
$realCurrent=$service->current(1,true);
$firstRow=array_values(array_filter($realCurrent['pools'],static fn($row)=>(string)$row['id']==='1'))[0];
$summary=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::summary($firstRow);
ck($summary['platform_used']===18000&&$summary['official_used']===12000&&$summary['remaining']===552000,'remaining does not double-count covered platform ledger usage');
ck(isset($realCurrent['registration_pools']),'registration defaults use a separate bounded latest-pool selection');
$presets=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::presets([],str_repeat('1',32));
ck($presets['shengwang']['free_total']==='10000'&&$presets['shengwang']['budget_limit']==='10000'&&$presets['shengwang']['official_used']==='','new Shengwang registration prefills free entitlement without inventing official usage');
ck($presets['wechat']['free_total']==='','WeChat never inherits Shengwang free minutes');
$existingPresets=\WorkerAdmin\Domain\Chat\CallQuotaDisplay::presets([$firstRow],str_repeat('1',32));
ck($existingPresets['shengwang']['pool_enabled']==='1'&&$existingPresets['shengwang']['verified']==='1'&&$existingPresets['shengwang']['hard_stop_verified']==='1','saved resource confirmation boxes are restored in registration presets');
ck($existingPresets['shengwang']['free_total']==='100.00'&&$existingPresets['shengwang']['official_used']==='2.00','saved numeric amounts prefill on return to registration');

$empty=['settings'=>['config'=>['enabled'=>false,'audio_enabled'=>true,'video_enabled'=>true,'allow_fallback'=>true,'audio_priority'=>['shengwang','wechat'],'video_priority'=>['shengwang','wechat'],'window_seconds'=>60,'stop_margin_seconds'=>30,'heartbeat_seconds'=>10,'max_active_calls'=>20],'revision'=>'1'],'pools'=>[],'alerts'=>[],'active'=>[],'decisions'=>[],'pagination'=>['pools'=>['page'=>1,'has_more'=>false],'alerts'=>['page'=>1,'has_more'=>false],'active'=>['page'=>1,'has_more'=>false]]];
$credentials=['app_id'=>$base['app_id']];$notice='';$canManage=true;$connectionReport=$controlReport;
$dir=$root.'/test-artifacts';if(!is_dir($dir))mkdir($dir,0775,true);
$css=file_get_contents($root.'/server/assets/admin.css');
foreach(['register','account','legacy','readonly','resources','active','registered','unknown','returning','settings','settings-failed','settings-readonly'] as $variant){
 $formValues=[];$current=$empty;$credentials=['app_id'=>$base['app_id']];$section=in_array($variant,['resources','active'],true)?$variant:(in_array($variant,['registered','unknown'],true)?'resources':'register');$canManage=!in_array($variant,['readonly','settings-readonly'],true);
 if(str_starts_with($variant,'settings')){$section='settings';$credentials+=['certificate'=>str_repeat('a',32),'customer_id'=>'fixture-rest-id','customer_secret'=>'fixture-rest-secret','publish_privileges_verified'=>true];}
 if($variant==='settings-failed')$formValues=['_action'=>'configuration','enabled'=>'1','publish_privileges_verified'=>'1','certificate'=>'failed-input-retained','reason'=>'fixture invalid'];
 if($variant==='account')$credentials=['app_id'=>str_repeat('2',32)];
 if($variant==='account')$current['pools']=[$db->fetch("SELECT * FROM chat_call_resource_pools WHERE scope_key='shengwang:account:account42'")];
 if($variant==='legacy')$current['pools']=[$db->fetch("SELECT * FROM chat_call_resource_pools WHERE scope_key='old-physical-scope'")];
 if($variant==='resources')$current['pools']=[$rows[0]];
 if($variant==='registered'||$variant==='returning')$current['pools']=[$firstRow];
 if($variant==='unknown')$current['pools']=[$unknownRowDraft];
 if($variant==='active')$current['active']=[['id'=>'77','conversation_id'=>'88','state'=>'stopping','attempt_epoch'=>'1','created_at'=>$now]];
 ob_start();require $root.'/server/views/admin/chat-calls.php';$html=ob_get_clean();
 $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
 ck($xp->query('//input[@name="scope_key"]')->length===0,'scope_key is not manually entered: '.$variant);
 ck($xp->query('//input[@name="evidence_hash" and @type!="hidden"]')->length===0,'hash is not manually entered: '.$variant);
 foreach($xp->query('//input[@type="file"]') as $node){ck(!$node->hasAttribute('name'),'selected file is never submitted');ck(!$node->hasAttribute('required'),'supporting file is optional');}
 ck($xp->query('//*[@id="chat-call-diagnostics"]')->length===($section==='settings'&&$canManage?1:0),'diagnostics only exists once on the combined settings page: '.$variant);
 ck($xp->query('//nav[@aria-label="通话管理分页"]/a')->length===5,'five clear management sections: '.$variant);
 if(str_starts_with($variant,'settings')&&$canManage){ck($xp->query('//form[contains(@class,"chat-call-configuration")]//*[@name="certificate"]')->length===1,'one form contains provider credentials and function settings');ck($xp->query('//form[contains(@class,"chat-call-configuration")]//*[@name="_action" and @value="configuration"]')->length===1,'combined page uses atomic save action');}
 if(in_array($variant,['registered','returning','unknown'],true))ck($xp->query('//input[@type="checkbox" and @name="verified" and @checked]')->length===1,'saved confirmation is visible on reload: '.$variant);
 if($variant==='settings-failed')ck($xp->query('//input[@name="certificate" and @value="failed-input-retained"]')->length===1,'failed combined save retains entered credential values');
 if($variant==='register')ck($xp->query('//select[@name="scope_type"]')->length===1,'scope selector is rendered');
 if($variant==='readonly')ck($xp->query('//*[@id="chat-call-registration"]')->length===0,'read-only view has no registration form');
 if($variant==='resources')ck($xp->query('//*[@data-chat-evidence]')->length===2,'import and reverify use separate evidence controls');
 if($variant==='active')ck($xp->query('//*[@data-chat-evidence]')->length===1,'stop confirmation uses evidence generator');
 file_put_contents($dir.'/call-scope-'.$variant.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>'.$css.'</style><body class="admin-body"><main class="admin-content" style="max-width:1200px;margin:auto">'.$html.'</main><script src="/server/assets/admin.js"></script></body></html>');
}
foreach($passed as $label)echo 'PASS '.$label."\n";
echo 'PASS TOTAL '.count($passed)."\n";
}finally{$pdo->exec('DROP DATABASE '.$name);}

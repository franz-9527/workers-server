<?php
declare(strict_types=1);
if(getenv('CALL_SCOPE_TEST')!=='1'||basename(dirname(__DIR__))!=='workers-server')throw new RuntimeException('Independent test repository only');
$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'WorkerAdmin\\')){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
set_error_handler(function($level,$message,$file,$line){if(error_reporting()&$level)throw new ErrorException($message,0,$level,$file,$line);});
use WorkerAdmin\Domain\Chat\CallQuotaScope;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatCallAdministration;
function app_config($key,$fallback=null){return $fallback;}
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
ck($profileKeys===['app_id','audio','evidence_hash','video'],'Go meter profile format stays unchanged');
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
  public function change($input,$admin,$role){$GLOBALS['fixtureChanged']=true;}
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

$empty=['settings'=>['config'=>[],'revision'=>'1'],'pools'=>[],'alerts'=>[],'active'=>[],'decisions'=>[],'pagination'=>['pools'=>['page'=>1,'has_more'=>false],'alerts'=>['page'=>1,'has_more'=>false],'active'=>['page'=>1,'has_more'=>false]]];
$credentials=['app_id'=>$base['app_id']];$notice='';$canManage=true;$connectionReport=$report;
$dir=$root.'/test-artifacts';if(!is_dir($dir))mkdir($dir,0775,true);
$css=file_get_contents($root.'/server/assets/admin.css');
foreach(['register','account','legacy','readonly','resources','active'] as $variant){
 $current=$empty;$section=in_array($variant,['resources','active'],true)?$variant:'register';$canManage=$variant!=='readonly';
 if($variant==='account')$current['pools']=[$db->fetch("SELECT * FROM chat_call_resource_pools WHERE scope_key='shengwang:account:account42'")];
 if($variant==='legacy')$current['pools']=[$db->fetch("SELECT * FROM chat_call_resource_pools WHERE scope_key='old-physical-scope'")];
 if($variant==='resources')$current['pools']=[$rows[0]];
 if($variant==='active')$current['active']=[['id'=>'77','conversation_id'=>'88','state'=>'stopping','attempt_epoch'=>'1','created_at'=>$now]];
 ob_start();require $root.'/server/views/admin/chat-calls.php';$html=ob_get_clean();
 $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($dom);
 ck($xp->query('//input[@name="scope_key"]')->length===0,'scope_key is not manually entered: '.$variant);
 ck($xp->query('//input[@name="evidence_hash" and @type!="hidden"]')->length===0,'hash is not manually entered: '.$variant);
 foreach($xp->query('//input[@type="file"]') as $node){ck(!$node->hasAttribute('name'),'selected file is never submitted');ck(!$node->hasAttribute('required'),'supporting file is optional');}
 if($variant==='register')ck($xp->query('//select[@name="scope_type"]')->length===1,'scope selector is rendered');
 if($variant==='readonly')ck($xp->query('//*[@id="chat-call-registration"]')->length===0,'read-only view has no registration form');
 if($variant==='resources')ck($xp->query('//*[@data-chat-evidence]')->length===2,'import and reverify use separate evidence controls');
 if($variant==='active')ck($xp->query('//*[@data-chat-evidence]')->length===1,'stop confirmation uses evidence generator');
 file_put_contents($dir.'/call-scope-'.$variant.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>'.$css.'</style><body class="admin-body"><main class="admin-content" style="max-width:1200px;margin:auto">'.$html.'</main><script src="/server/assets/admin.js"></script></body></html>');
}
foreach($passed as $label)echo 'PASS '.$label."\n";
echo 'PASS TOTAL '.count($passed)."\n";
}finally{$pdo->exec('DROP DATABASE '.$name);}

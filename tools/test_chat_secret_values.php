<?php
declare(strict_types=1);
namespace WorkerAdmin\Foundation {
    class ApplicationContainer {
        public function __construct($db){}
        public function privateCipher(){return $GLOBALS['fixtureCipher'];}
    }
    class ChatApplicationContainer {
        public function __construct(private $db){}
        public function credentials(){return new class {public function readiness(){return ['ready'=>true];}};}
        public function callCredentials(){return new \WorkerAdmin\Application\Chat\ChatCallCredentials($this->db,$GLOBALS['fixtureCipher']);}
        public function calls(){return new class {public function current($page){return [];}};}
    }
}
namespace {
if(getenv('CHAT_SECRET_TEST')!=='1'||basename(dirname(__DIR__))!=='workers-server')throw new \RuntimeException('Independent test repo only');
$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'WorkerAdmin\\')){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
set_error_handler(function($level,$message,$file,$line){if(error_reporting()&$level)throw new \ErrorException($message,0,$level,$file,$line);});
function app_config($key,$fallback=null){global $config;if(array_key_exists($key,$config))return $config[$key];$v=$config;foreach(explode('.',$key) as $p){if(!is_array($v)||!array_key_exists($p,$v))return $fallback;$v=$v[$p];}return $v;}
function setting($key,$fallback=null){return $GLOBALS['settings'][$key]??$fallback;}
function base_path($path=''){return $GLOBALS['root'].'/server'.($path!==''?'/'.$path:'');}
function view($name,$vars){$GLOBALS['lastView']=$vars;return '';}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]){return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function csrf(){return new class {function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
function asset_url($name){return '/server/assets/'.$name;}
$passed=[];
function ck($ok,$label){if(!$ok)throw new \RuntimeException($label);$GLOBALS['passed'][]=$label;}
$port=(int)getenv('MYSQL_PORT');if(!$port)throw new \RuntimeException('Ephemeral MySQL port required');
$pdo=new \PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$name='chat_secret_'.bin2hex(random_bytes(4));$pdo->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
$config=['app.env'=>'testing','app.url'=>'https://test.example.invalid','app.key'=>str_repeat('fixture-',8),
 'database'=>['host'=>'127.0.0.1','port'=>$port,'database'=>$name,'username'=>'root','password'=>'fixture-only'],
 'chat'=>['seed_env'=>'CHAT_FIXTURE_ACCESS_SEED','key_id'=>'fixture-v1']];
$settings=['wechat.miniapp_appid'=>'wx0000000000000001'];
$fixtureCipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {
 function hmac(string $p,string $c):string{return hash_hmac('sha256',$p,$c);}
 function encrypt(string $p,string $c):?string{return 'fixture:'.base64_encode($c.'|'.$p);}
 function decrypt(string $p,string $c):string{$v=base64_decode(substr($p,8),true);if(!is_string($v)||!str_starts_with($v,$c.'|'))throw new \RuntimeException('fixture decrypt failure with sensitive payload');return substr($v,strlen($c)+1);}
};
try{
$db=new \WorkerAdmin\Core\Database($config['database']);
$db->execute('CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(32),is_public INT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME)');
$db->execute('CREATE TABLE admin_users(id BIGINT PRIMARY KEY,role VARCHAR(32),status VARCHAR(12),must_change_password INT)');
$db->execute("INSERT INTO admin_users VALUES(1,'super_admin','active',0),(2,'operations','active',0)");
$db->execute('CREATE TABLE chat_call_resource_pools(id INT PRIMARY KEY,provider VARCHAR(20),reserved_units INT,local_uncovered INT)');
$db->execute('CREATE TABLE chat_call_sessions(id INT PRIMARY KEY,state VARCHAR(20))');
$runtime=new \WorkerAdmin\Application\Chat\ChatGoRuntimeConfig($db,$fixtureCipher);
$input=['redis_enabled'=>'1','redis_address'=>'127.0.0.1:6379','redis_password'=>'fixture<&"redis',
 'wechat_callback_token'=>'FixtureCallbackToken1234','wechat_callback_aes_key'=>rtrim(base64_encode(str_repeat('a',32)),'='),'wechat_callback_confirmed'=>'1'];
$runtime->save($input,1,'super_admin');
$values=$runtime->adminFormValues();
foreach(['redis_password','wechat_callback_token','wechat_callback_aes_key'] as $key)ck($values[$key]===$input[$key],'saved value returned for '.$key);
ck(!$values['errors'],'configured values have no decrypt errors');
foreach($db->fetchAll("SELECT setting_value FROM system_settings WHERE setting_key LIKE '%ciphertext' OR setting_key LIKE '%callback%'") as $row)
 foreach([$input['redis_password'],$input['wechat_callback_token'],$input['wechat_callback_aes_key']] as $secret)ck(!str_contains($row['setting_value'],$secret),'database row contains no plaintext secret');
$signing=new \WorkerAdmin\Application\Chat\ChatSigningKeyManager($db,$fixtureCipher);
$signing->generate($config['chat'],1,'super_admin');
$state=$signing->status($config['chat']);$status=$runtime->status($state);
foreach([$input['redis_password'],$input['wechat_callback_token'],$input['wechat_callback_aes_key']] as $secret)ck(!str_contains(json_encode($status),$secret),'runtime diagnostic stays secret-free');
$seed=base64_encode($signing->material($config['chat'])['seed']);
ck(!str_contains(json_encode($state),$seed),'signing status stays secret-free');
$runtime->save(array_replace($input,['redis_password'=>'','wechat_callback_token'=>'','wechat_callback_aes_key'=>'']),1,'super_admin');
ck($runtime->adminFormValues()['redis_password']===$input['redis_password'],'blank preserves existing Redis password');
$runtime->save(array_replace($input,['clear_redis_password'=>'1']),1,'super_admin');
ck($runtime->adminFormValues()['redis_password']==='','explicit clear wins over prefilled Redis password');
$runtime->save($input,1,'super_admin');
try{$runtime->save($input,2,'operations');ck(false,'operations save rejected');}catch(\RuntimeException $e){ck(true,'operations cannot save Go settings');}
$calls=new \WorkerAdmin\Application\Chat\ChatCallCredentials($db,$fixtureCipher);
$provider=['shengwang_app_id'=>str_repeat('1',32),'certificate'=>str_repeat('2',32),'customer_id'=>'fixture<&"rest','customer_secret'=>'fixture<&"secret','publish_privileges_verified'=>'1'];
$calls->saveLocked($provider,1);
$cv=$calls->adminFormValues();
foreach(['certificate','customer_id','customer_secret'] as $key)ck($cv[$key]===$provider[$key],'call form returns '.$key);
ck(!isset($calls->current()['customer_secret']),'call metadata stays secret-free');
$calls->saveLocked(array_replace($provider,['certificate'=>'','customer_id'=>'','customer_secret'=>'']),1);
ck($calls->adminFormValues()['customer_secret']===$provider['customer_secret'],'blank preserves call secrets');
class FixtureAuth extends \WorkerAdmin\Core\Auth {
 function __construct(public $role,public $permissions){}
 function user(){return ['id'=>$this->role==='super_admin'?1:2,'role'=>$this->role];}
 function can($permission){return $this->role==='super_admin'||in_array($permission,$this->permissions,true);}
 function requirePermission($permission){if(!$this->can($permission))throw new \RuntimeException('fixture denied',403);}
}
class FixtureCsrf extends \WorkerAdmin\Core\Csrf {function __construct(){}}
$_SERVER['REQUEST_METHOD']='GET';$_POST=[];$_GET=[];
$csrfService=new FixtureCsrf();
$module=new \WorkerAdmin\Http\Admin\Modules\ChatSettingsAdminModule($db,new FixtureAuth('super_admin',[]),$csrfService);
$module->dispatch('chat.settings');$sv=$lastView;
ck($sv['secretValues']['redis_password']===$input['redis_password'],'actual settings controller includes plaintext for owner');
ck($sv['secretValues']['signing_seed']===$seed,'actual settings controller uses active signing material');
putenv('CHAT_FIXTURE_ACCESS_SEED='.base64_encode(str_repeat('z',32)));$module->dispatch('chat.settings');
ck($lastView['secretValues']['signing_seed']===base64_encode(str_repeat('z',32)),'signing environment override is displayed');
putenv('CHAT_FIXTURE_ACCESS_SEED');
(new \WorkerAdmin\Http\Admin\Modules\ChatSettingsAdminModule($db,new FixtureAuth('operations',['settings.manage']),$csrfService))->dispatch('chat.settings');
ck($lastView['secretValues']===[],'settings read-only actor receives no secret values');
try{(new \WorkerAdmin\Http\Admin\Modules\ChatSettingsAdminModule($db,new FixtureAuth('viewer',[]),$csrfService))->dispatch('chat.settings');ck(false,'viewer denied');}catch(\RuntimeException $e){ck($e->getCode()===403,'settings viewer denied before values read');}
$_GET=['section'=>'credentials'];
$callModule=new \WorkerAdmin\Http\Admin\Modules\ChatCallsAdminModule($db,new FixtureAuth('super_admin',[]),$csrfService);
$callModule->dispatch('chat.calls');
ck($lastView['credentials']['customer_secret']===$provider['customer_secret'],'actual call controller includes plaintext on credential tab');
$_GET=['section'=>'history'];$callModule->dispatch('chat.calls');
ck(!isset($lastView['credentials']['customer_secret']),'other call tabs receive metadata only');
$_GET=['section'=>'credentials'];
(new \WorkerAdmin\Http\Admin\Modules\ChatCallsAdminModule($db,new FixtureAuth('operations',['chat.calls.view']),$csrfService))->dispatch('chat.calls');
ck($lastView['credentials']===[],'call viewer receives no credentials');
foreach(['normal','readonly'] as $variant){
 extract($sv);$canManage=$variant==='normal';$secretValues=$sv['secretValues'];
 ob_start();require $root.'/server/views/admin/chat-settings.php';$html=ob_get_clean();
 $dom=new \DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new \DOMXPath($dom);
 foreach(['redis_password','wechat_callback_token','wechat_callback_aes_key'] as $key){
  $node=$xp->query('//input[@name="'.$key.'"]')->item(0);
  ck($node->getAttribute('type')==='text','no password masking: '.$key);
  ck($node->getAttribute('value')===($canManage?$input[$key]:''),'escaped form value and permission: '.$key);
 }
 ck($xp->query('//*[@id="chat-signing-seed"]')->length===($canManage?1:0),'signing seed restricted to authorized form');
}
$section='credentials';$canManage=true;$credentials=$cv;
$current=['settings'=>['config'=>[],'revision'=>1],'pools'=>[]];
ob_start();require $root.'/server/views/admin/chat-calls.php';$html=ob_get_clean();
$dom=new \DOMDocument();$dom->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new \DOMXPath($dom);
foreach(['certificate','customer_id','customer_secret'] as $key){
 $node=$xp->query('//input[@name="'.$key.'"]')->item(0);
 ck($node->getAttribute('type')==='text'&&$node->getAttribute('value')===$provider[$key],'call view safely renders full '.$key);
}
$db->execute("UPDATE system_settings SET setting_value='corrupt' WHERE setting_key='chat.go_redis_password_ciphertext'");
$broken=$runtime->adminFormValues();
ck($broken['redis_password']===''&&count($broken['errors'])===1&&!str_contains(json_encode($broken),'sensitive payload'),'decrypt failure does not reveal exception or ciphertext');
$calls->saveLocked(['clear_credentials'=>'1'],1);ck($calls->adminFormValues()['customer_secret']==='','call clear removes displayed values');
foreach($passed as $label)echo 'PASS '.$label."\n";
echo 'PASS TOTAL '.count($passed)."\n";
}finally{$pdo->exec('DROP DATABASE '.$name);}
}

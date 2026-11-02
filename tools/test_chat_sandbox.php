<?php
declare(strict_types=1);
if(getenv('CHAT_SANDBOX_TEST')!=='1'||basename(dirname(__DIR__))!=='workers-server')throw new RuntimeException('Independent test repository required');
$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){if(str_starts_with($class,'WorkerAdmin\\')){$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;}});
set_error_handler(function($level,$message,$file,$line){if(error_reporting()&$level)throw new ErrorException($message,0,$level,$file,$line);});
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Chat\ChatSandbox;
use WorkerAdmin\Application\Chat\ChatSimulation;
use WorkerAdmin\Support\SqlScriptRunner;
$env='testing';$settings=['demo.data_enabled'=>true,'wechat.miniapp_appid'=>'wxfixture123','wechat.admin_openids'=>['fixture-openid'],'demo.actor_user_public_id'=>'DEMOUSER','demo.actor_worker_public_id'=>'DEMOWORKER'];
function app_config($key,$fallback=null){return ['app.env'=>$GLOBALS['env'],'app.key'=>str_repeat('fixture-key-',4)][$key]??$fallback;}
function setting($key,$fallback=null){return $GLOBALS['settings'][$key]??$fallback;}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($route,$params=[]){return '/admin/?'.http_build_query(['route'=>$route]+$params);}
function asset_url($name){return '/server/assets/'.$name;}
function local_datetime($value){return $value;}
function csrf(){return new class{function input(){return '<input type="hidden" name="csrf" value="fixture">';}};}
$checks=0;
function ck($ok,$name){if(!$ok)throw new RuntimeException($name);$GLOBALS['checks']++;echo "PASS $name\n";}
function deny($fn,$code){try{$fn();}catch(RuntimeException $e){ck($e->getCode()===$code,'denial '.$code.' '.$e->getMessage());return;}throw new RuntimeException('Unexpected acceptance');}
$port=(int)getenv('MYSQL_PORT');if(!$port)throw new RuntimeException('Ephemeral MySQL required');
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port,'root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='sandbox_'.bin2hex(random_bytes(5));$pdo->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try{
$db=new Database(['host'=>'127.0.0.1','port'=>$port,'database'=>$name,'username'=>'root','password'=>'fixture-only','charset'=>'utf8mb4']);
foreach([
"CREATE TABLE admin_users(id BIGINT PRIMARY KEY,username VARCHAR(50),real_name VARCHAR(50),role VARCHAR(30),status VARCHAR(16),must_change_password INT)",
"INSERT INTO admin_users VALUES(1,'owner','管理甲','super_admin','active',0),(2,'operator','管理乙','operations','active',0),(3,'disabled','禁用身份','operations','disabled',0)",
"CREATE TABLE users(id BIGINT PRIMARY KEY,public_id VARCHAR(40),nickname VARCHAR(60),status VARCHAR(16))",
"INSERT INTO users VALUES(11,'DEMOUSER','演示客户甲','active'),(22,'DEMOWORKER','演示师傅甲','active'),(33,'01KTESTV260WORKER000000001','演示师傅乙','active'),(99,'REALUSER','真实账号','active')",
"CREATE TABLE worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT,cloud_record_mode VARCHAR(24))",
"INSERT INTO worker_profiles VALUES(1,22,'demo_local'),(2,33,'demo_local')",
"CREATE TABLE merchant_profiles(id BIGINT PRIMARY KEY,user_id BIGINT)",
"CREATE TABLE wechat_identities(id BIGINT PRIMARY KEY,user_id BIGINT,app_id VARCHAR(32),openid VARCHAR(64),status VARCHAR(16))",
"INSERT INTO wechat_identities VALUES(1,99,'wxfixture123','fixture-openid','active')",
"CREATE TABLE login_sessions(id BIGINT PRIMARY KEY,user_id BIGINT,token_hash CHAR(64),status VARCHAR(16),expires_at DATETIME,demo_actor_user_id BIGINT NULL,test_actor_user_id BIGINT NULL,test_instance_id BIGINT NULL,test_actor_expires_at DATETIME NULL)",
"CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,value_type VARCHAR(20),group_name VARCHAR(32),is_public INT,description TEXT,updated_by BIGINT NULL,created_at DATETIME,updated_at DATETIME)",
"CREATE TABLE orders(id BIGINT PRIMARY KEY,marker VARCHAR(40))",
"INSERT INTO orders VALUES(10,'preserve test and real orders')",
"CREATE TABLE chat_messages(id BIGINT PRIMARY KEY,marker VARCHAR(40))",
"INSERT INTO chat_messages VALUES(1,'preserve production chat')",
"CREATE TABLE message_outbox(id BIGINT PRIMARY KEY,marker VARCHAR(40))",
"INSERT INTO message_outbox VALUES(1,'preserve notifications')"
] as $sql)$db->execute($sql);
$runner=new SqlScriptRunner();
foreach(['2.7.1','2.7.2'] as $v){$sql=file_get_contents($root.'/server/database/upgrades/'.$v.'.sql');$runner->run($db->pdo(),$sql,$v.'.sql');$runner->run($db->pdo(),$sql,$v.'.sql');}
$sandbox=new ChatSandbox($db);ck($sandbox->ready(),'idempotent actual migrations create the three sandbox tables');
$actors=$sandbox->actors();ck(count($actors)===5,'catalog includes two active administrators and three approved demo identities');
ck(!isset($actors['user:99'])&&!isset($actors['admin:3']),'real users and disabled administrators are not selectable');
$make=fn($keys,$title='回归会话')=>$sandbox->create(['title'=>$title,'members'=>$keys,'request_key'=>bin2hex(random_bytes(16))],1)['id'];
$input=['title'=>'自由组合群','members'=>['admin:1','admin:2','user:11','user:22'],'request_key'=>str_repeat('a',32)];
$room=$sandbox->create($input,1)['id'];ck($sandbox->create($input,1)['id']===$room,'creation is idempotent across a repeated request');
deny(fn()=>$sandbox->create(array_replace($input,['title'=>'changed']),1),409);
foreach([[],['admin:1'],['admin:1','admin:1'],['admin:1','user:99'],['admin:1','admin:3']] as $members)deny(fn()=>$make($members),400);
deny(fn()=>$sandbox->create(['title'=>'forbidden','members'=>['admin:1','user:11'],'request_key'=>str_repeat('b',32)],2),403);
$admins=$make(['admin:1','admin:2'],'管理员双人测试');$demos=$make(['user:11','user:22'],'演示双人测试');
ck($sandbox->dashboard(1,$room)['room']['title']==='自由组合群','selected room and title persist');
$sent=$sandbox->send($room,'admin:2','后台管理员测试','key-one',1);
ck($sandbox->send($room,'admin:2','后台管理员测试','key-one',1)===$sent,'message idempotency is scoped to room and selected sender');
deny(fn()=>$sandbox->send($room,'admin:2','changed','key-one',1),409);
deny(fn()=>$sandbox->send($room,'user:33','not a member','key-outside',1),403);
ck((int)$db->fetchValue('SELECT operated_by FROM chat_sandbox_messages WHERE id=:id',['id'=>$sent['message_id']])===1,'actual operating administrator is recorded separately from chosen sender');
$sim=new ChatSimulation($db);
foreach([['a',11],['b',22],['c',33]] as $index=>$case)$db->execute("INSERT INTO login_sessions VALUES(:id,99,:hash,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,:actor,NULL,NULL,NULL)",['id'=>$index+1,'hash'=>hash('sha256',str_repeat($case[0],64)),'actor'=>$case[1]]);
$customer=$sim->identity(str_repeat('a',64));$worker=$sim->identity(str_repeat('b',64));$outside=$sim->identity(str_repeat('c',64));
ck(!empty($customer['sandbox'])&&$customer['expires']>time(),'existing demo switch resolves a sandbox identity without a short-lived entry');
ck($sim->credential($customer)['transport']==='simulation','sandbox identity receives no Go token');
$recovery=new class implements \WorkerAdmin\Contracts\ChatRecoveryReadiness {public int $calls=0;public function ensureReady(array $config,string $environment):void{$this->calls++;throw new RuntimeException('Go must not be called');}};
$cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {public function hmac(string $p,string $c):string{return hash('sha256',$p);}public function encrypt(string $p,string $c):?string{return $p;}public function decrypt(string $p,string $c):string{return $p;}};
$issuer=new \WorkerAdmin\Application\Chat\ChatCredentialIssuer($db,[],'testing',$cipher,$recovery);
ck($issuer->availabilityMini(str_repeat('a',64))===['enabled'=>true,'transport'=>'simulation']&&$issuer->exchangeMini(str_repeat('a',64))['transport']==='simulation'&&$recovery->calls===0,'real credential API chooses isolated demo transport without Go readiness');
deny(fn()=>$issuer->exchangeMini(str_repeat('a',64),true),403);
$wire=(string)(8000000000000000000+(int)$room);
$digests=$sim->execute($customer,'conversations/digests',[]);
ck(count($digests['items'])===2,'demo sees only rooms where it is a member');
$page=$sim->execute($customer,'conversations/bootstrap',['conversation_id'=>$wire]);
ck($page['conversation_mode']==='collaboration'&&$page['order_context']['simulation']===true&&$page['order_context']['public_no']==='','group is labeled as test with no real order link');
ck($page['messages'][0]['sender_id']==='7000000000000000002','administrator identity uses a separate numeric display namespace');
$profiles=$sim->participants($customer,[['conversation_id'=>$wire,'policy_version'=>'1']]);
ck(count($profiles['items'][0]['members'])===4&&$profiles['items'][0]['members'][0]['simulation']===true,'mini participants include all explicitly selected roles');
deny(fn()=>$sim->execute($outside,'conversations/bootstrap',['conversation_id'=>$wire]),403);
deny(fn()=>$sim->execute($customer,'messages/send',['conversation_id'=>$wire,'message_type'=>'image','text'=>'x','client_message_id'=>'image']),400);
$reply=$sim->execute($customer,'messages/send',['conversation_id'=>$wire,'message_type'=>'text','text'=>'演示客户回复','client_message_id'=>'reply']);
$unread=$sim->execute($worker,'conversations/unread',['conversation_id'=>$wire]);ck($unread['unread_count']===2,'unread counts messages rather than differences between sequence IDs');
$latest=$sim->execute($worker,'conversations/bootstrap',['conversation_id'=>$wire]);
$read=$sim->execute($worker,'conversations/read',['conversation_id'=>$wire,'cursor'=>$latest['sync_cursor']]);ck($read['unread_count']===0,'read cursor updates only the current member watermark');
deny(fn()=>$sim->execute($customer,'conversations/read',['conversation_id'=>$wire,'cursor'=>$latest['sync_cursor']]),400);
deny(fn()=>$sim->execute($worker,'messages/recall',['conversation_id'=>$wire,'message_id'=>$reply['message_id']]),403);
$recalled=$sim->execute($customer,'messages/recall',['conversation_id'=>$wire,'message_id'=>$reply['message_id']]);ck($recalled['state']==='recalled'&&!isset($recalled['text']),'only the sender can recall its message');
$pref=$sim->execute($customer,'conversations/preference',['conversation_id'=>$wire,'member_version'=>'1','field'=>'pinned','value'=>true,'expected_version'=>'0']);
ck($pref['pinned']&&$pref['pinned_version']==='1','test preferences use member-scoped compare-and-swap');
deny(fn()=>$sim->execute($customer,'conversations/preference',['conversation_id'=>$wire,'member_version'=>'1','field'=>'pinned','value'=>false,'expected_version'=>'0']),409);
for($n=0;$n<55;$n++)$sandbox->send($room,'user:22','分页消息 '.$n,'page-'.$n,1);
$latest=$sim->execute($customer,'conversations/bootstrap',['conversation_id'=>$wire]);
ck(count($latest['messages'])===50&&$latest['history_cursor']!=='','mini history is bounded and supplies an older cursor');
$older=$sim->execute($customer,'messages/history',['conversation_id'=>$wire,'cursor'=>$latest['history_cursor']]);ck(count($older['messages'])===7,'older message page has the remaining messages');
$view=$sandbox->dashboard(1,$room);ck(count($view['messages'])===50&&$view['older'],'admin messages are also paged at 50');
for($n=0;$n<20;$n++)$make(['admin:1','user:11'],'列表分页 '.$n);
ck(count($sandbox->dashboard()['rooms'])===20&&$sandbox->dashboard()['more'],'room list is paged at 20');
$settings['wechat.admin_openids']=[];ck($sim->identity(str_repeat('a',64))===null,'revoking real mini administrator authority blocks demo chat');
deny(fn()=>$sim->execute($customer,'conversations/digests',[]),403);$settings['wechat.admin_openids']=['fixture-openid'];
$settings['demo.data_enabled']=false;ck($sim->identity(str_repeat('a',64))===null,'demo-data switch revokes sandbox access');$settings['demo.data_enabled']=true;
$db->execute("UPDATE login_sessions SET demo_actor_user_id=22 WHERE id=1");deny(fn()=>$sim->execute($customer,'conversations/digests',[]),403);
$db->execute("UPDATE login_sessions SET demo_actor_user_id=11 WHERE id=1");
$db->execute("UPDATE login_sessions SET status='revoked' WHERE id=1");deny(fn()=>$sim->execute($customer,'conversations/digests',[]),403);$db->execute("UPDATE login_sessions SET status='active' WHERE id=1");
$env='production';deny(fn()=>$make(['admin:1','user:11']),403);deny(fn()=>$sandbox->clear(1),403);ck($sim->identity(str_repeat('a',64))===null,'production never exposes demo sandbox transport');$env='testing';

// Generate real views before cleanup, including special characters and an input draft.
$sandbox->send($room,'admin:1','<script>unsafe()</script>'."\n".'下一行','xss-test',1);
$actors=$sandbox->actors();$data=$sandbox->dashboard(1,$room);$ready=true;$notice='';$values=[];$requestKey=str_repeat('c',32);
$dir=$root.'/test-artifacts';if(!is_dir($dir))mkdir($dir,0775,true);$css=file_get_contents($root.'/server/assets/admin.css');
foreach(['normal','failed','missing'] as $variant){
 $ready=$variant!=='missing';$values=$variant==='failed'?['title'=>'输入保留','members'=>['admin:1','user:11'],'_action'=>'create']:[];
 ob_start();require $root.'/server/views/admin/chat-testing.php';$html=ob_get_clean();
 ck(!str_contains($html,'<script>unsafe()'),'message text is escaped in the actual PHP view');
 ck(!str_contains($html,'test-center.entry-token'),'chat testing contains no temporary-entry action');
 file_put_contents($dir.'/sandbox-'.$variant.'.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><style>'.$css.'</style><body class="admin-body"><main class="admin-content" style="max-width:1200px;margin:auto">'.$html.'</main></body></html>');
}
$ready=true;$values=[];
file_put_contents($dir.'/sandbox-native.json',json_encode(['page'=>$page,'profiles'=>$profiles,'digests'=>$digests],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$beforeUsers=$db->fetchAll('SELECT * FROM users ORDER BY id');$beforeAdmins=$db->fetchAll('SELECT * FROM admin_users ORDER BY id');
$beforeOrders=$db->fetchAll('SELECT * FROM orders');$beforeReal=$db->fetchAll('SELECT * FROM chat_messages');$beforeOutbox=$db->fetchAll('SELECT * FROM message_outbox');
$db->execute("INSERT INTO chat_test_conversations(instance_id,worker_slot,customer_id,worker_id,created_at) VALUES(1,'worker_a',11,22,UTC_TIMESTAMP())");
$db->execute("INSERT INTO chat_test_messages(conversation_id,sender_id,sender_role,client_key,payload_hash,text_body,change_seq,created_at) VALUES(1,11,'customer','legacy',:hash,'旧测试消息',1,UTC_TIMESTAMP())",['hash'=>hash('sha256','legacy')]);
$deleted=$sandbox->clear(1,$admins);ck($deleted['chat_sandbox_rooms']===1,'individual room deletion is scoped');
deny(fn()=>$sandbox->clear(2),403);
$all=$sandbox->clear(1);
foreach(['chat_sandbox_messages','chat_sandbox_members','chat_sandbox_rooms','chat_test_messages','chat_test_conversations'] as $table)ck((int)$db->fetchValue('SELECT COUNT(*) FROM '.$table)===0,'bulk clear removes '.$table);
ck($beforeUsers===$db->fetchAll('SELECT * FROM users ORDER BY id')&&$beforeAdmins===$db->fetchAll('SELECT * FROM admin_users ORDER BY id'),'bulk clear preserves all identities');
ck($beforeOrders===$db->fetchAll('SELECT * FROM orders')&&$beforeReal===$db->fetchAll('SELECT * FROM chat_messages')&&$beforeOutbox===$db->fetchAll('SELECT * FROM message_outbox'),'bulk clear preserves orders, production chat and external notification rows');
deny(fn()=>$sandbox->send($room,'admin:1','late','late-key',1),404);deny(fn()=>$sim->execute($customer,'conversations/bootstrap',['conversation_id'=>$wire]),404);
$new=$make(['admin:1','user:11']);ck((int)$new>(int)$room,'cleanup does not reset room numbering');

class TestAuth extends \WorkerAdmin\Core\Auth {
 public function __construct(public string $role='super_admin'){}
 public function user(){return ['id'=>1,'role'=>$this->role];}
 public function requirePermission($p){}
}
class TestCsrf extends \WorkerAdmin\Core\Csrf {
 public bool $deny=false;public int $checks=0;
 public function __construct(){}
 public function verifyRequest(){$this->checks++;if($this->deny)throw new RuntimeException('CSRF rejected',403);}
}
function view($name,$data){$GLOBALS['rendered']=$data;return '';}
function audit($a,$t,$id,$detail){$GLOBALS['auditRows'][]=[$a,$id,$detail];}
function flash($type,$message){}
function redirect($url){$GLOBALS['redirected']=$url;}
function json_response($body,$status=200){$GLOBALS['jsonResult']=[$body,$status];}
$_SERVER['REQUEST_METHOD']='GET';$_GET=['room'=>$new];$_POST=[];
$csrfTest=new TestCsrf();$module=new \WorkerAdmin\Http\Admin\Modules\ChatTestingAdminModule($db,new TestAuth(),$csrfTest);
$module->dispatch('chat.testing');ck($rendered['ready']&&$rendered['activeMenu']==='chat.testing','actual controller exposes the migrated ready workspace');
deny(fn()=>(new \WorkerAdmin\Http\Admin\Modules\ChatTestingAdminModule($db,new TestAuth('operations'),$csrfTest))->dispatch('chat.testing'),403);
$_SERVER['REQUEST_METHOD']='POST';$_POST=['_action'=>'clear_all'];$csrfTest->deny=true;deny(fn()=>$module->dispatch('chat.testing'),403);
ck((int)$db->fetchValue('SELECT COUNT(*) FROM chat_sandbox_rooms')===1,'bad CSRF cannot clear test rooms');
$csrfTest->deny=false;$module->dispatch('chat.testing');
ck($csrfTest->checks===2&&isset($redirected)&&$auditRows[0][0]==='chat.test_clear_all','one-click clear requires CSRF and records the actual operation');
$_SERVER['REQUEST_METHOD']='GET';$_GET=['room'=>$new,'poll'=>'1'];$module->dispatch('chat.testing');
ck($jsonResult[1]===404,'live view reports a cleared room rather than rendering stale messages');

echo 'PASS TOTAL '.$checks."\n";
}finally{$pdo->exec('DROP DATABASE '.$name);}

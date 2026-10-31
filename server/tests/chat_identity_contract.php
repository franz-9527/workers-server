<?php

declare(strict_types=1);
spl_autoload_register(static function(string $class):void{if(str_starts_with($class,'WorkerAdmin\\')){$file=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require_once $file;}});

// Standalone PHP 7.4+ contract; optional real MySQL fixture is explicitly opted in.
require_once dirname(__DIR__).'/app/Core/Database.php';
require_once dirname(__DIR__).'/app/Core/Auth.php';
require_once dirname(__DIR__).'/app/Core/Csrf.php';
require_once dirname(__DIR__).'/app/Http/Admin/AdminRequestGuard.php';
require_once dirname(__DIR__).'/app/Application/Chat/ChatCredentialIssuer.php';
require_once dirname(__DIR__).'/app/Infrastructure/Chat/ChatRecoveryGate.php';

use WorkerAdmin\Application\Chat\ChatCredentialIssuer;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Http\Admin\AdminRequestGuard;

$config = ['enabled'=>true,'key_id'=>'chat-v1','seed_env'=>'CHAT_ACCESS_SEED'];

if (in_array(($argv[1] ?? ''), ['--issue-mini','--issue-admin','--logout-admin'],true)) {
    if (getenv('CHAT_AUTH_TEST_INTEGRATION') !== '1') exit(2);
    $host = (string)getenv('CHAT_AUTH_TEST_MYSQL_HOST');
    $database = (string)getenv('CHAT_AUTH_TEST_MYSQL_DATABASE');
    if ($host !== '127.0.0.1' || strpos($database, 'bixuan_test_auth_') !== 0) exit(2);
    $db = new Database(['host'=>$host,'port'=>(int)getenv('CHAT_AUTH_TEST_MYSQL_PORT'),
        'database'=>$database,'username'=>(string)getenv('CHAT_AUTH_TEST_MYSQL_USER'),
        'password'=>(string)getenv('CHAT_AUTH_TEST_MYSQL_PASSWORD'),'charset'=>'utf8mb4']);
    try {
        $issuer = (new \WorkerAdmin\Foundation\ChatApplicationContainer($db))->credentials($config,'testing');
        if ($argv[1] === '--issue-mini') {
            $result = $issuer->exchangeMini((string)getenv('CHAT_AUTH_TEST_LOGIN_TOKEN'));
        } else {
            $folder = (string)getenv('CHAT_AUTH_TEST_SESSION_DIR');
            if (!is_dir($folder)) exit(2);
            session_save_path($folder);
            session_id((string)getenv('CHAT_AUTH_TEST_SESSION_ID'));
            session_start();
            if ($argv[1] === '--issue-admin') {
                $_SESSION['admin_id']='2'; $_SESSION['_last_activity']=time();
                $result = $issuer->exchangeAdmin(new Auth($db));
            } else {
                (new Auth($db))->logout(); $result=['revoked'=>true];
            }
        }
        echo json_encode($result, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) { fwrite(STDERR, "fixture exchange failed\n"); exit(1); }
    exit;
}

function app_config($key, $default=null) { return $default; }

final class ChatIdentityFixtureDatabase extends Database
{
    public ?array $row;
    public int $reads = 0;
    public ?array $auditGrant = null;
    public function __construct() { $this->row = ['source_id'=>'7','user_id'=>'18446744073709551615',
        'token_hash'=>hash('sha256', str_repeat('a',64)),'expires_unix'=>time()+60]; }
    public function fetch($sql, array $params = []) {
        $this->reads++;
        if(strpos($sql,'SELECT s.*,UNIX_TIMESTAMP')===0)return null;
        if(strpos($sql,'SELECT authority_version FROM chat_mini_auditors')===0){if(array_keys($params)!==['id'])throw new RuntimeException('unexpected grant access');return $this->auditGrant;}
        if (strpos($sql, 'SELECT ') !== 0 || array_keys($params) !== ['token_hash']) throw new RuntimeException('unexpected source access');
        return $this->row;
    }
    public function fetchValue($sql,array $params=[]){if(strpos($sql,"SELECT setting_value FROM system_settings WHERE setting_key='chat.enabled'")===0)return '1';throw new RuntimeException('unexpected setting read');}
    public function execute($sql, array $params = []) { throw new RuntimeException('identity must never write'); }
}

function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejected(callable $fn, int $status): void { try { $fn(); } catch (RuntimeException $e) { ensure($e->getCode() === $status, 'unexpected status'); return; } throw new RuntimeException('unexpected acceptance'); }

$seed = random_bytes(32);
putenv('CHAT_ACCESS_SEED='.base64_encode($seed));
$db = new ChatIdentityFixtureDatabase();
$issuer = (new \WorkerAdmin\Foundation\ChatApplicationContainer($db))->credentials($config,'testing');
$result = $issuer->exchangeMini(str_repeat('a',64));
$parts = explode('.', $result['access_token']);
$decode = static function(string $s): string { return (string)base64_decode(strtr($s,'-_','+/')); };
$claims = json_decode($decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
ensure($claims['sub'] === '18446744073709551615' && $claims['source_id'] === '7', 'IDs lost precision');
ensure($claims['env'] === 'test' && $claims['scopes'] === ['chat:use'], 'authority mismatch');
ensure($claims['exp'] <= time()+60 && $claims['exp'] > time(), 'source expiry not respected');
$public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
ensure(sodium_crypto_sign_verify_detached($decode($parts[2]),$parts[0].'.'.$parts[1],$public), 'signature mismatch');
rejected(static function() use($issuer): void { $issuer->exchangeMini('invalid'); },401);
rejected(static function() use($issuer): void { $issuer->exchangeMini(str_repeat('a',64),true); },403);
$db->auditGrant=['authority_version'=>str_repeat('b',64)];
$auditResult=$issuer->exchangeMini(str_repeat('a',64),true);
$auditParts=explode('.',$auditResult['access_token']);
$auditClaims=json_decode($decode($auditParts[1]),true,32,JSON_THROW_ON_ERROR);
ensure($auditClaims['kind']==='mini_audit'&&$auditClaims['scopes']===['chat:audit'],'mini audit mixed with ordinary authority');
ensure($auditClaims['session_version']===hash('sha256',"mini-audit-v1\n7\n".$db->row['token_hash']."\n".$db->row['user_id']."\n".str_repeat('b',64)),'independent audit generation mismatch');
$db->auditGrant=['authority_version'=>str_repeat('c',64)];
$regrant=$issuer->exchangeMini(str_repeat('a',64),true);$regrantParts=explode('.',$regrant['access_token']);$regrantClaims=json_decode($decode($regrantParts[1]),true,32,JSON_THROW_ON_ERROR);
ensure($regrantClaims['session_version']!==$auditClaims['session_version'],'regrant revived previous credential generation');
$db->row = null;
rejected(static function() use($issuer): void { $issuer->exchangeMini(str_repeat('a',64)); },401);
$db->row = ['source_id'=>'1','user_id'=>'1','token_hash'=>str_repeat('a',64),'expires_unix'=>time()+60];
putenv('CHAT_ACCESS_SEED=bad-secret');
rejected(static function() use($issuer): void { $issuer->exchangeMini(str_repeat('a',64)); },503);
rejected(static function() use($db,$config): void { ((new \WorkerAdmin\Foundation\ChatApplicationContainer($db))->credentials($config,'test'))->exchangeMini(str_repeat('a',64)); },503);
rejected(static function() use($db): void { ((new \WorkerAdmin\Foundation\ChatApplicationContainer($db))->credentials(['enabled'=>false],'production'))->exchangeMini(str_repeat('a',64)); },503);
$blockedGate=new class implements \WorkerAdmin\Contracts\ChatRecoveryReadiness {
    public function ensureReady(array $config,string $environment):void {throw new RuntimeException('recovery blocked',503);}
};
$reads=$db->reads;
$blockedIssuer=new ChatCredentialIssuer($db,$config,'testing',new \WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher(),$blockedGate);
rejected(static function()use($blockedIssuer):void{$blockedIssuer->exchangeMini(str_repeat('a',64));},503);
ensure($db->reads===$reads,'closed injected recovery gate must stop before identity reads');
putenv('CHAT_ACCESS_SEED');

final class ChatResponse extends RuntimeException
{
    public array $payload;
    public function __construct(array $payload,int $status) { parent::__construct('fixture response',$status);$this->payload=$payload; }
}
function json_response(array $payload,$status=200) { throw new ChatResponse($payload,(int)$status); }
function request_id() { return 'fixture-request'; }
final class ChatGuardAuth extends Auth
{
    public bool $loggedIn=true;
    public bool $changePassword=false;
    public function __construct() {}
    public function check() { return $this->loggedIn; }
    public function user() { return ['must_change_password'=>$this->changePassword]; }
}
$guardAuth=new ChatGuardAuth();$guard=new AdminRequestGuard($guardAuth,new Csrf());
$_SESSION['_csrf_token']=str_repeat('a',64);$_SERVER['REQUEST_METHOD']='POST';$_POST=[];
rejected(static function()use($guard):void{$guard->inspect('chat.credentials');},403);
$_SERVER['HTTP_X_CSRF_TOKEN']=str_repeat('a',64);$guard->inspect('chat.credentials');
$guardAuth->loggedIn=false;rejected(static function()use($guard):void{$guard->inspect('chat.credentials');},401);
$guardAuth->loggedIn=true;$guardAuth->changePassword=true;rejected(static function()use($guard):void{$guard->inspect('chat.credentials');},403);
$_SERVER['REQUEST_METHOD']='GET';rejected(static function()use($guard):void{$guard->inspect('chat.credentials');},405);
$_SESSION=['admin_id'=>'2','chat_admin_session_id'=>'1'];
try { (new Auth(new ChatIdentityFixtureDatabase()))->logout(); throw new RuntimeException('logout bypassed revocation failure'); }
catch(RuntimeException $error) { ensure(isset($_SESSION['admin_id'],$_SESSION['chat_admin_session_id']), 'failed logout discarded revocation evidence'); }
echo "聊天身份PHP契约通过\n";

if(getenv('CHAT_AUTH_TEST_INTEGRATION')==='1'){
    $address=(string)getenv('CHAT_INTEGRATION_MYSQL_ADDR');
    if(preg_match('/^127\\.0\\.0\\.1:([0-9]+)$/D',$address,$endpoint)!==1)throw new RuntimeException('isolated grants endpoint required');
    $dbConfig=['host'=>'127.0.0.1','port'=>(int)$endpoint[1],'database'=>'mysql','username'=>'root','password'=>(string)getenv('CHAT_INTEGRATION_ADMIN_PASSWORD'),'charset'=>'utf8mb4'];
    $rootDb=new Database($dbConfig);$name='bixuan_test_chat_grants_'.bin2hex(random_bytes(6));
    $rootDb->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$dbConfig['database']=$name;$grantDb=new Database($dbConfig);
    try{
        (new \WorkerAdmin\Support\SqlScriptRunner())->runFile($grantDb->pdo(),dirname(__DIR__).'/database/database.sql');
        $grantDb->execute("INSERT INTO users(id,public_id,nickname,status,created_at,updated_at) VALUES(301,:public,'grant fixture','active',UTC_TIMESTAMP(),UTC_TIMESTAMP()),(302,:inactive,'inactive grant fixture','disabled',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>str_repeat('A',26),'inactive'=>str_repeat('B',26)]);
        $login=str_repeat('c',64);
        $grantDb->execute("INSERT INTO login_sessions(user_id,token_hash,status,expires_at,created_at) VALUES(301,:hash,'active',UTC_TIMESTAMP()+INTERVAL 1 HOUR,UTC_TIMESTAMP())",['hash'=>hash('sha256',$login)]);
        putenv('CHAT_ACCESS_SEED='.base64_encode(random_bytes(32)));
        $chat=new \WorkerAdmin\Foundation\ChatApplicationContainer($grantDb);$grants=$chat->auditors();$liveIssuer=$chat->credentials($config,'testing');
        $input=['user_id'=>'301','reason'=>'隔离授权验证','enabled'=>'1'];
        rejected(static function()use($liveIssuer,$login):void{$liveIssuer->exchangeMini($login,true);},403);
        $grants->change($input,'99');$first=$grantDb->fetch('SELECT * FROM chat_mini_auditors WHERE user_id=301');
        $firstToken=$liveIssuer->exchangeMini($login,true);$firstClaims=json_decode($decode(explode('.',$firstToken['access_token'])[1]),true,32,JSON_THROW_ON_ERROR);
        ensure($firstClaims['sub']==='301'&&$firstClaims['scopes']===['chat:audit'],'grant must export audit-only identity');
        ensure(count($grants->recent())===1&&(int)$grantDb->fetchValue('SELECT COUNT(*) FROM chat_auditor_grant_audit')===1,'grant and append-only audit saved together');
        $grants->change(array_replace($input,['enabled'=>'0']),'99');
        rejected(static function()use($liveIssuer,$login):void{$liveIssuer->exchangeMini($login,true);},403);
        $grants->change($input,'99');$third=$grantDb->fetch('SELECT * FROM chat_mini_auditors WHERE user_id=301');
        $thirdToken=$liveIssuer->exchangeMini($login,true);$thirdClaims=json_decode($decode(explode('.',$thirdToken['access_token'])[1]),true,32,JSON_THROW_ON_ERROR);
        ensure($first['authority_version']!==$third['authority_version']&&$firstClaims['session_version']!==$thirdClaims['session_version'],'disable/regrant cannot revive previous audit generation');
        foreach(['302','999999'] as $invalidUser){
            $denied=false;try{$grants->change(array_replace($input,['user_id'=>$invalidUser]),'99');}catch(RuntimeException $error){$denied=true;}
            ensure($denied,'inactive/missing account received audit authority');
        }
        foreach([['user_id'=>['301']],['reason'=>['invalid']],['user_id'=>'18446744073709551616'],['reason'=>'短']] as $invalid){
            $denied=false;try{$grants->change(array_replace($input,$invalid),'99');}catch(RuntimeException $error){$denied=true;}
            ensure($denied,'malformed grant input bypassed validation');
        }
        $auditCount=(int)$grantDb->fetchValue('SELECT COUNT(*) FROM chat_auditor_grant_audit');
        $grantDb->execute("CREATE TRIGGER fail_grant_audit BEFORE INSERT ON chat_auditor_grant_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'");
        $rolledBack=false;try{$grants->change(array_replace($input,['enabled'=>'0']),'99');}catch(Throwable $error){$rolledBack=true;}
        ensure($rolledBack&&$third===$grantDb->fetch('SELECT * FROM chat_mini_auditors WHERE user_id=301'),'audit failure must roll back the grant and its generation');
        ensure((int)$grantDb->fetchValue('SELECT COUNT(*) FROM chat_auditor_grant_audit')===$auditCount,'failed grant appended partial audit');
        $grantDb->execute('DROP TRIGGER fail_grant_audit');
        $grantDb->execute("UPDATE users SET status='disabled' WHERE id=301");
        $denied=false;try{$grants->change($input,'99');}catch(RuntimeException $error){$denied=true;}
        ensure($denied,'active-account check was bypassed after status change');
        echo "Chat auditor grants: real MySQL grant/revoke/regrant, independent credential generation and audit rollback passed\n";
    }finally{putenv('CHAT_ACCESS_SEED');$rootDb->execute('DROP DATABASE IF EXISTS `'.$name.'`');}
}

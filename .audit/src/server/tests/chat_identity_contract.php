<?php

declare(strict_types=1);

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
        $issuer = new ChatCredentialIssuer($db, $config, 'testing');
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
        if(strpos($sql,'SELECT authority_version FROM chat_mini_auditors')===0){if(array_keys($params)!==['id'])throw new RuntimeException('unexpected grant access');return $this->auditGrant;}
        if (strpos($sql, 'SELECT ') !== 0 || array_keys($params) !== ['token_hash']) throw new RuntimeException('unexpected source access');
        return $this->row;
    }
    public function execute($sql, array $params = []) { throw new RuntimeException('identity must never write'); }
}

function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejected(callable $fn, int $status): void { try { $fn(); } catch (RuntimeException $e) { ensure($e->getCode() === $status, 'unexpected status'); return; } throw new RuntimeException('unexpected acceptance'); }

$seed = random_bytes(32);
putenv('CHAT_ACCESS_SEED='.base64_encode($seed));
$db = new ChatIdentityFixtureDatabase();
$issuer = new ChatCredentialIssuer($db, $config, 'testing');
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
rejected(static function() use($db,$config): void { (new ChatCredentialIssuer($db,$config,'test'))->exchangeMini(str_repeat('a',64)); },503);
rejected(static function() use($db): void { (new ChatCredentialIssuer($db,['enabled'=>false],'production'))->exchangeMini(str_repeat('a',64)); },503);
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

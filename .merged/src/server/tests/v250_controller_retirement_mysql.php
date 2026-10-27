<?php

declare(strict_types=1);

// Explicit opt-in only; this suite creates and removes its own schema on the isolated test server.
if (getenv('CONTROLLER_RETIREMENT_MYSQL_TEST') !== '1') {
    fwrite(STDERR,"Set CONTROLLER_RETIREMENT_MYSQL_TEST=1 for an isolated MySQL 5.7 test server.\n");
    exit(2);
}
function setting($key,$default=null) { return $GLOBALS['retirementMysqlSettings'][$key] ?? $default; }
function app_config($key,$default=null) { return $default; }
$root=dirname(__DIR__,2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (strpos($class,'WorkerAdmin\\')!==0) return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if (is_file($file)) require_once $file;
});
$checks=0;
function retirementMysqlAssert(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    ++$GLOBALS['checks'];
}
function retirementMysqlReject(callable $operation,string $code): void {
    try { $operation(); throw new RuntimeException('Expected '.$code); }
    catch (\WorkerAdmin\Domain\Order\OrderOperationException $error) {
        retirementMysqlAssert($error->apiCode()===$code,'stable rejection '.$code);
    }
}
$config=[
    'host'=>(string)(getenv('V250_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('V250_DB_PORT') ?: 3306),
    'username'=>(string)(getenv('V250_DB_USER') ?: 'root'),
    'password'=>(string)(getenv('V250_DB_PASSWORD') ?: ''),
    'database'=>'worker_controller_retirement_test',
];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',
    $config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
retirementMysqlAssert(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'exact MySQL 5.7');
$created=false;
try {
    // No IF EXISTS/DROP before creation: an existing schema is never overwritten.
    $server->exec('CREATE DATABASE worker_controller_retirement_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created=true;
    $db=new \WorkerAdmin\Core\Database($config);
    $other=new \WorkerAdmin\Core\Database($config);
    $db->execute("CREATE TABLE system_settings(setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT) ENGINE=InnoDB");
    $db->execute("CREATE TABLE orders(id INT PRIMARY KEY,public_no VARCHAR(80) UNIQUE,is_demo TINYINT NOT NULL,counter INT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $db->execute("INSERT INTO system_settings VALUES('demo.data_enabled','true')");
    $db->execute("INSERT INTO orders(id,public_no,is_demo) VALUES(1,'RETIREMENT-DEMO',1),(2,'RETIREMENT-REAL',0)");
    $guard=new \WorkerAdmin\Infrastructure\Persistence\DatabaseDemoOrderWriteGuard($db);
    $lock=['lock_name'=>'wall_worker_admin_demo_guard'];
    $result=$guard->run('RETIREMENT-DEMO',static function () use ($db,$other,$lock): array {
        return $db->transaction(static function (\WorkerAdmin\Core\Database $db) use ($other,$lock): array {
            retirementMysqlAssert((int)$other->fetchValue('SELECT GET_LOCK(:lock_name,0)',$lock)===0,'switch connection excluded during write');
            $db->execute('UPDATE orders SET counter=counter+1 WHERE id=1');
            return ['committed'=>true];
        });
    });
    retirementMysqlAssert($result===['committed'=>true] && (int)$db->fetchValue('SELECT counter FROM orders WHERE id=1')===1,'demo write commits once');
    retirementMysqlAssert((int)$other->fetchValue('SELECT GET_LOCK(:lock_name,0)',$lock)===1,'lock released after commit');
    $other->execute("UPDATE system_settings SET setting_value='false' WHERE setting_key='demo.data_enabled'");
    $other->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$lock);
    retirementMysqlReject(static function () use ($guard): void {
        $guard->run('RETIREMENT-DEMO',static function (): array { throw new RuntimeException('disabled callback must not run'); });
    },'DEMO_DATA_DISABLED');
    retirementMysqlAssert((int)$db->fetchValue('SELECT counter FROM orders WHERE id=1')===1,'disabled demo unchanged');
    $guard->run('RETIREMENT-REAL',static function () use ($db): array {
        $db->execute('UPDATE orders SET counter=counter+1 WHERE id=2'); return [];
    });
    retirementMysqlAssert((int)$db->fetchValue('SELECT counter FROM orders WHERE id=2')===1,'real write allowed with demo disabled');
    // A request can have an older REPEATABLE READ snapshot before the setting changes.
    $other->execute("UPDATE system_settings SET setting_value='true' WHERE setting_key='demo.data_enabled'");
    $db->pdo()->beginTransaction();
    $db->fetchValue("SELECT setting_value FROM system_settings WHERE setting_key='demo.data_enabled'");
    retirementMysqlAssert((int)$other->fetchValue('SELECT GET_LOCK(:lock_name,0)',$lock)===1,'switch lock acquired after request snapshot');
    $other->execute("UPDATE system_settings SET setting_value='false' WHERE setting_key='demo.data_enabled'");
    $other->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$lock);
    retirementMysqlReject(static function () use ($guard): void {
        $guard->run('RETIREMENT-DEMO',static function (): array { throw new RuntimeException('stale snapshot must not allow write'); });
    },'DEMO_DATA_DISABLED');
    $db->pdo()->rollBack();
    retirementMysqlAssert((int)$other->fetchValue('SELECT GET_LOCK(:lock_name,0)',$lock)===1,'snapshot rejection releases advisory lock');
    retirementMysqlReject(static function () use ($guard): void { $guard->run('RETIREMENT-REAL',static function (): array { return []; }); },'DEMO_SETTING_BUSY');
    $other->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$lock);
    $other->execute("UPDATE system_settings SET setting_value='true' WHERE setting_key='demo.data_enabled'");
    try {
        $guard->run('RETIREMENT-DEMO',static function () use ($db): array {
            return $db->transaction(static function (\WorkerAdmin\Core\Database $db): array {
                $db->execute('UPDATE orders SET counter=counter+1 WHERE id=1');
                throw new RuntimeException('injected rollback');
            });
        });
    } catch (RuntimeException $error) { retirementMysqlAssert($error->getMessage()==='injected rollback','original rollback error preserved'); }
    retirementMysqlAssert((int)$db->fetchValue('SELECT counter FROM orders WHERE id=1')===1,'failed write rolled back');
    retirementMysqlAssert((int)$other->fetchValue('SELECT GET_LOCK(:lock_name,0)',$lock)===1,'lock released after rollback');
    $other->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$lock);
    foreach (['Cancellation','Dispute'] as $kind) {
        $class='WorkerAdmin\\Infrastructure\\Persistence\\DatabaseOrder'.$kind.'Repository';
        $repository=new $class($db);
        $operator=['channel'=>'web_admin','admin_user_id'=>1];
        retirementMysqlReject(static function () use ($kind,$repository,$operator): void {
            if ($kind==='Cancellation') $repository->review($operator,'NOT-FOUND','reject','no','unclear','测试拒绝',0);
            else $repository->resolveByAdmin($operator,'NOT-FOUND','测试裁定','unclear');
        },'ORDER_NOT_FOUND');
        retirementMysqlAssert((int)$other->fetchValue('SELECT GET_LOCK(:lock_name,0)',$lock)===1,$kind.' failure releases real MySQL lock');
        $other->execute("UPDATE system_settings SET setting_value='false' WHERE setting_key='demo.data_enabled'");
        $other->fetchValue('SELECT RELEASE_LOCK(:lock_name)',$lock);
        retirementMysqlReject(static function () use ($kind,$repository,$operator): void {
            if ($kind==='Cancellation') $repository->review($operator,'RETIREMENT-DEMO','reject','no','unclear','测试拒绝',0);
            else $repository->resolveByAdmin($operator,'RETIREMENT-DEMO','测试裁定','unclear');
        },'DEMO_DATA_DISABLED');
        $other->execute("UPDATE system_settings SET setting_value='true' WHERE setting_key='demo.data_enabled'");
    }

    // Real token/role SQL and all five Application guards run against this MySQL schema.
    $db->execute("CREATE TABLE users(id INT PRIMARY KEY,public_id VARCHAR(80),status VARCHAR(20),nickname VARCHAR(80),avatar_url VARCHAR(200),mobile_masked VARCHAR(30)) ENGINE=InnoDB");
    $db->execute("CREATE TABLE worker_profiles(id INT PRIMARY KEY,user_id INT,public_id VARCHAR(80),display_name VARCHAR(80),verification_status VARCHAR(20),rejection_reason TEXT,invited_only TINYINT,lat DOUBLE,lng DOUBLE,city VARCHAR(80),district VARCHAR(80)) ENGINE=InnoDB");
    $db->execute("CREATE TABLE merchant_profiles(id INT PRIMARY KEY,user_id INT) ENGINE=InnoDB");
    $db->execute("CREATE TABLE login_sessions(id INT PRIMARY KEY,user_id INT,demo_actor_user_id INT NULL,test_actor_user_id INT NULL,test_instance_id INT NULL,test_actor_expires_at DATETIME NULL,token_hash CHAR(64),status VARCHAR(20),expires_at DATETIME,last_seen_at DATETIME NULL) ENGINE=InnoDB");
    $db->execute("CREATE TABLE wechat_identities(id INT PRIMARY KEY,user_id INT,openid VARCHAR(80),app_id VARCHAR(80),status VARCHAR(20)) ENGINE=InnoDB");
    $db->execute("INSERT INTO users(id,public_id,status) VALUES(10,'real-admin','active'),(501,'01K2DEMOVTESTUSER','active'),(502,'01K2DEMOVTESTMERCHANT','active'),(503,'01K2DEMOVTESTWORKER','active')");
    $db->execute("INSERT INTO merchant_profiles(id,user_id) VALUES(1,502)");
    $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,verification_status,invited_only) VALUES(88,503,'demo-worker-profile','approved',0)");
    $db->execute("INSERT INTO wechat_identities(id,user_id,openid,app_id,status) VALUES(1,10,'admin-openid','test-app','active')");
    $token=str_repeat('a',64);
    $db->execute("INSERT INTO login_sessions(id,user_id,demo_actor_user_id,token_hash,status,expires_at) VALUES(1,10,501,:hash,'active',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR))",['hash'=>hash('sha256',$token)]);
    $GLOBALS['retirementMysqlSettings']=['wechat.miniapp_appid'=>'test-app','wechat.admin_openids'=>['admin-openid'],'demo.data_enabled'=>true];
    $identity=(new \WorkerAdmin\Foundation\ApplicationContainer($db))->apiActorResolver();
    foreach (['user'=>501,'merchant'=>502,'worker'=>503] as $role=>$id) {
        $db->execute('UPDATE login_sessions SET demo_actor_user_id=:id WHERE id=1',['id'=>$id]);
        $actor=$identity->resolve($token);
        retirementMysqlAssert($actor['demo_role']===$role && $actor['is_demo_actor'] && !$actor['is_admin'],'SQL fallback role '.$role.' is trusted without admin capability');
        retirementMysqlAssert($actor['real_user_id']===10 && $actor['user_id']===$id,'real identity preserved for '.$role);
        $orders=new \WorkerAdmin\Foundation\OrderApplicationContainer($db);
        retirementMysqlReject(static function () use ($orders,$actor): void { $orders->submission()->create($actor,[]); },'DEMO_CREATE_BLOCKED');
        retirementMysqlReject(static function () use ($orders,$actor): void { $orders->submission()->finalize($actor,[]); },'DEMO_CREATE_BLOCKED');
        retirementMysqlReject(static function () use ($orders,$actor): void { $orders->nearbyWorkerOutreach()->notify($actor,[]); },'DEMO_EXTERNAL_ACTION_BLOCKED');
        $applications=new \WorkerAdmin\Foundation\ApplicationContainer($db);
        retirementMysqlReject(static function () use ($applications,$token): void { $applications->merchantAuthSubmission()->submit($token,[]); },'DEMO_EXTERNAL_ACTION_BLOCKED');
        retirementMysqlReject(static function () use ($applications,$token): void { $applications->realNameSubmission()->submit($token,[]); },'DEMO_EXTERNAL_ACTION_BLOCKED');
    }
    $GLOBALS['retirementMysqlSettings']['demo.actor_user_public_id']='real-admin';
    $db->execute('UPDATE login_sessions SET demo_actor_user_id=10 WHERE id=1');
    $actor=$identity->resolve($token);
    retirementMysqlAssert($actor['demo_role']==='user' && $actor['is_demo_actor'],'configured active demo account takes precedence over fallback');
    unset($GLOBALS['retirementMysqlSettings']['demo.actor_user_public_id']);
    $db->execute('UPDATE login_sessions SET demo_actor_user_id=10 WHERE id=1');
    retirementMysqlAssert(!$identity->resolve($token)['is_demo_actor'],'unconfigured production account cannot become a demo actor');
    retirementMysqlAssert($db->fetchValue('SELECT demo_actor_user_id FROM login_sessions WHERE id=1')===null,'invalid proxy cleared in real session row');
    retirementMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM orders')===2,'rejected actor operations create no orders');

    echo 'Controller retirement MySQL 5.7: '.$checks." checks passed; two connections, setting switch, stale snapshot, commit/rollback, timeout and both review repositories.\n";
} finally {
    if ($created) $server->exec('DROP DATABASE worker_controller_retirement_test');
}

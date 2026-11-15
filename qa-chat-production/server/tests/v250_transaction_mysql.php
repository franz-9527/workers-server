<?php

declare(strict_types=1);

if ((string)getenv('V250_PLATFORM_MYSQL_TEST') !== '1') {
    fwrite(STDOUT, "[SKIP] V250 MySQL transaction regression disabled.\n");
    exit(0);
}

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Core/Database.php';

use WorkerAdmin\Core\Database;

function txAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $label . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

$config = [
    'host'=>(string)(getenv('V250_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('V250_DB_PORT') ?: 3306),
    'database'=>(string)(getenv('V250_DB_NAME') ?: 'worker_test'),
    'username'=>(string)(getenv('V250_DB_USER') ?: 'root'),
    'password'=>(string)(getenv('V250_DB_PASSWORD') ?: 'root'),
    'charset'=>'utf8mb4',
];

$db = new Database($config);
$db->execute('DROP TABLE IF EXISTS v250_transaction_probe');
$db->execute('CREATE TABLE v250_transaction_probe(id INT UNSIGNED NOT NULL AUTO_INCREMENT, marker VARCHAR(32) NOT NULL, PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

try {
    $db->transaction(function (Database $db): void {
        $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('outer_ok')");
        $db->transaction(function (Database $db): void {
            $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('inner_ok')");
        });
    });
    txAssert((int)$db->fetchValue('SELECT COUNT(*) FROM v250_transaction_probe') === 2, '正常嵌套事务只由最外层提交');

    $rollbackOnlyThrown = false;
    try {
        $db->transaction(function (Database $db): void {
            $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('outer_before_fail')");
            try {
                $db->transaction(function (Database $db): void {
                    $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('inner_fail')");
                    throw new RuntimeException('inner failure');
                });
            } catch (RuntimeException $ignored) {
                // 模拟业务层错误捕获：数据库仍必须阻止外层把部分事实提交。
            }
            $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('outer_after_catch')");
        });
    } catch (RuntimeException $exception) {
        $rollbackOnlyThrown = strpos($exception->getMessage(), '禁止提交部分业务结果') !== false;
    }
    txAssert($rollbackOnlyThrown, '内层失败被业务捕获后最外层仍强制 rollback-only');
    txAssert((int)$db->fetchValue('SELECT COUNT(*) FROM v250_transaction_probe') === 2, 'rollback-only 未留下任何部分写入');

    $db->transaction(function(Database $db):void{
        $db->beforeCommit('same',static function(Database $db):void{$db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('superseded_hook')");});
        $db->transaction(static function(Database $db):void{
            $db->beforeCommit('same',static function(Database $db):void{$db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('deduplicated_hook')");});
        });
        txAssert((int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='deduplicated_hook'")===0,'预提交工作不会在内层事务提前运行');
    });
    txAssert((int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='deduplicated_hook'")===1,'同键预提交工作合并一次');
    txAssert((int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='superseded_hook'")===0,'重复键使用最后登记的动作');
    $failed=false;
    try{$db->transaction(static function(Database $db):void{
        $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('must_rollback')");
        $db->beforeCommit('fail',static function():void{throw new RuntimeException('fixture hook failure');});
    });}catch(RuntimeException $error){$failed=$error->getMessage()==='fixture hook failure';}
    txAssert($failed && (int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='must_rollback'")===0,'预提交失败会回滚原业务写入');
    $called=0;$failed=false;
    try{$db->transaction(static function(Database $db)use(&$called):void{
        try{$db->transaction(static function(Database $db)use(&$called):void{
            $db->beforeCommit('cancelled',static function()use(&$called):void{$called++;});
            throw new RuntimeException('fixture nested failure');
        });}catch(RuntimeException $ignored){}
    });}catch(RuntimeException $error){$failed=true;}
    txAssert($failed && $called===0,'rollback-only不执行已登记回调');
    $failed=false;try{$db->beforeCommit('outside',static function():void{});}catch(RuntimeException $error){$failed=true;}
    txAssert($failed,'事务外登记被拒绝');
    $failed=false;try{$db->transaction(static function(Database $db):void{
        $db->beforeCommit('reentrant',static function(Database $db):void{$db->beforeCommit('nested',static function():void{});});
    });}catch(RuntimeException $error){$failed=true;}
    txAssert($failed,'预提交执行中不得递归登记');
    $failed=false;try{$db->transaction(static function(Database $db):void{
        for($i=0;$i<129;$i++)$db->beforeCommit('bounded:'.$i,static function():void{});
    });}catch(RuntimeException $error){$failed=true;}
    txAssert($failed,'预提交队列最多128个不同键');
    $db->transaction(static function(Database $db):void{$db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('after_hook_failure')");});
    txAssert((int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='after_hook_failure'")===1,'失败回调不会泄漏到下一事务');
    $db->transaction(static function(Database $db):void{
        $db->beforeCommit('nested-write',static function(Database $db):void{
            $db->transaction(static function(Database $db):void{$db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('hook_nested_sql')");});
        });
    });
    txAssert((int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='hook_nested_sql'")===1,'预提交动作内的嵌套SQL仍加入同一事务');
    $failed=false;try{$db->transaction(static function(Database $db):void{
        $db->beforeCommit('caught-hook-error',static function(Database $db):void{
            try{$db->transaction(static function(Database $db):void{
                $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('caught_hook_failure')");throw new RuntimeException('fixture');
            });}catch(RuntimeException $ignored){}
        });
    });}catch(RuntimeException $error){$failed=true;}
    txAssert($failed && (int)$db->fetchValue("SELECT COUNT(*) FROM v250_transaction_probe WHERE marker='caught_hook_failure'")===0,'预提交内捕获的嵌套失败仍禁止外层提交');

    $pdo1 = $db->pdo();
    $db2 = new Database($config);
    $pdo2 = $db2->pdo();
    $db->execute("INSERT INTO v250_transaction_probe(marker) VALUES('lock_probe')");
    $lockId = (int)$pdo1->lastInsertId();
    $pdo1->beginTransaction();
    $stmt1 = $pdo1->prepare('SELECT id FROM v250_transaction_probe WHERE id=:id FOR UPDATE');
    $stmt1->execute(['id'=>$lockId]);
    $pdo2->exec('SET SESSION innodb_lock_wait_timeout=1');
    $blocked = false;
    try {
        $stmt2 = $pdo2->prepare('SELECT id FROM v250_transaction_probe WHERE id=:id FOR UPDATE');
        $stmt2->execute(['id'=>$lockId]);
    } catch (Throwable $exception) {
        $blocked = true;
    }
    if ($pdo1->inTransaction()) $pdo1->rollBack();
    txAssert($blocked, 'MySQL 5.7 双连接 FOR UPDATE 会串行化同一业务行');
} finally {
    try { if ($db->pdo()->inTransaction()) $db->pdo()->rollBack(); } catch (Throwable $ignored) {}
    try { $db->execute('DROP TABLE IF EXISTS v250_transaction_probe'); } catch (Throwable $ignored) {}
}

echo "V2.5.0 MySQL transaction/concurrency regression: OK\n";


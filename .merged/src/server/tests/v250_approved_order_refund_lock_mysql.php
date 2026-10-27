<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Scheduling\ApprovedOrderRefundRetryBridge;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php';
require_once $root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryBridge.php';

function refundLockSame($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('V250_DB_NAME') !== 'worker_test') {
    fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");
    exit(2);
}

$config = [
    'host'=>(string)(getenv('V250_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('V250_DB_PORT') ?: 3306),
    'database'=>'worker_test',
    'username'=>(string)(getenv('V250_DB_USER') ?: 'root'),
    'password'=>(string)(getenv('V250_DB_PASSWORD') ?: 'root'),
];
$holder = new Database($config);
$worker = new Database($config);
refundLockSame(strpos((string)$holder->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

$lockName='wall_worker_approved_order_refund_retry';
refundLockSame((int)$holder->fetchValue('SELECT GET_LOCK(:name,0)',['name'=>$lockName]),1,'外部连接成功占用退款执行锁');
$submitted=0;
$bridge = new ApprovedOrderRefundRetryBridge($worker, 100, static function(array $row) use (&$submitted): array {
    $submitted++;
    return ['refund_id'=>'SHOULD-NOT-RUN'];
});
$result=$bridge->run();
refundLockSame((string)$result['status'],'success','锁冲突属于安全跳过而不是任务失败');
refundLockSame(!empty($result['skipped']),true,'锁冲突返回 skipped 语义');
refundLockSame((int)$result['scanned'],0,'锁冲突时不扫描退款业务表');
refundLockSame($submitted,0,'锁冲突时不会向微信提交退款');
refundLockSame((int)$holder->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lockName]),1,'测试释放退款执行锁');

echo "V2.5.0 approved order refund retry lock MySQL 5.7 integration: OK\n";

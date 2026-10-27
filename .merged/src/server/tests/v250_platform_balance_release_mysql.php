<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderFrozenBalanceReleaser;

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Core/Database.php';
require_once $root . '/server/app/Infrastructure/Persistence/PlatformOrderFrozenBalanceReleaser.php';

function ensureRelease($actual, $expected, string $case): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] ' . $case . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . "\n");
        exit(1);
    }
    echo '[PASS] ' . $case . "\n";
}

// 测试使用独立的临时数据库（真实 InnoDB 表），与正常业务数据库完全隔离。
// MySQL 5.7 不允许同一 SQL 多次引用同一 TEMPORARY TABLE，真实表才可覆盖生产查询。
// 仅 CI 且指定 worker_test 库可启用本测试；不触碰/修改 worker_test 的业务表。
if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "Refusing to run outside the platform MySQL GitHub Actions test.\n");
    exit(2);
}
$dbName = (string) (getenv('V250_DB_NAME') ?: 'worker_test');
if ($dbName !== 'worker_test') {
    fwrite(STDERR, "Refusing to run against non-test database.\n");
    exit(2);
}
$config = [
    'host'=>(string)(getenv('V250_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('V250_DB_PORT') ?: '3306'),
    'database'=>'worker_test','username'=>(string)(getenv('V250_DB_USER') ?: 'root'),
    'password'=>(string)(getenv('V250_DB_PASSWORD') ?: 'root'),
];
$control = new Database($config);
ensureRelease(strpos((string)$control->fetchValue('SELECT VERSION()'), '5.7.') === 0, true, 'MySQL 5.7 真实连接');
$fixtureDatabase = 'worker_v250_release_ci';
$control->pdo()->exec('DROP DATABASE IF EXISTS `' . $fixtureDatabase . '`');
$control->pdo()->exec('CREATE DATABASE `' . $fixtureDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database'] = $fixtureDatabase;
$db = new Database($config);
$pdo = $db->pdo();
$ddl = [
    "CREATE TABLE orders(
      id BIGINT UNSIGNED NOT NULL PRIMARY KEY, version INT NOT NULL DEFAULT 1,
      order_source_type VARCHAR(24) NOT NULL, is_demo TINYINT NOT NULL DEFAULT 0,
      lifecycle_status VARCHAR(40) NOT NULL, fulfillment_status VARCHAR(40) NOT NULL,
      accepted_at DATETIME NULL, assigned_worker_id BIGINT UNSIGNED NOT NULL,
      dispute_status VARCHAR(24) NOT NULL DEFAULT 'none', refund_status VARCHAR(24) NOT NULL DEFAULT 'none'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE order_platform_terms(
      order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,worker_settlement_mode VARCHAR(24) NOT NULL,
      settlement_status VARCHAR(32) NOT NULL,worker_balance_credit_cents BIGINT UNSIGNED NOT NULL,
      worker_balance_freeze_days_snapshot INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE worker_balance_ledger(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,worker_id BIGINT UNSIGNED NOT NULL,
      entry_type VARCHAR(48) NOT NULL,direction VARCHAR(16) NOT NULL,
      amount_cents BIGINT UNSIGNED NOT NULL,available_delta_cents BIGINT NOT NULL,
      frozen_delta_cents BIGINT NOT NULL,business_type VARCHAR(32) NOT NULL,
      business_id BIGINT UNSIGNED NOT NULL,idempotency_key VARCHAR(180) NOT NULL,
      description VARCHAR(255) NULL,status VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL,
      UNIQUE KEY uniq_idempotency_key(idempotency_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE order_events(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
      event_type VARCHAR(70) NOT NULL,event_key VARCHAR(128) NULL,actor_type VARCHAR(24) NOT NULL,
      actor_id BIGINT UNSIGNED NULL,payload_json TEXT NOT NULL,created_at DATETIME NOT NULL,
      UNIQUE KEY uniq_event_key(event_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE scheduled_job_failures(
      id INT AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(80) NOT NULL,business_key VARCHAR(120) NOT NULL,
      business_version INT NOT NULL,resolved_at DATETIME NULL,next_retry_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE disputes(id INT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
       business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE refunds(id INT AUTO_INCREMENT PRIMARY KEY,payment_order_id BIGINT UNSIGNED NULL,
       order_id BIGINT UNSIGNED NULL,status VARCHAR(24) NOT NULL,failure_code VARCHAR(64) NULL) ENGINE=InnoDB",
    "CREATE TABLE order_cancel_requests(id INT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE change_requests(id INT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE after_sales(id INT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
];
foreach ($ddl as $query) $pdo->exec($query);

function seedRelease(Database $db, int $orderId, int $daysAgo, int $freezeDays, int $credit = 70000,
                     string $source = 'platform', string $mode = 'platform_balance', string $status = 'awaiting_platform_income',
                     int $ledgerAmount = 70000): void
{
    $db->execute(
        "INSERT INTO orders(id,version,order_source_type,lifecycle_status,fulfillment_status,accepted_at,assigned_worker_id)
         VALUES(:id,1,:source,'completed','accepted',DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$daysAgo} DAY),:worker)",
        ['id'=>$orderId,'source'=>$source,'worker'=>$orderId + 100]
    );
    $db->execute(
        "INSERT INTO order_platform_terms(order_id,worker_settlement_mode,settlement_status,worker_balance_credit_cents,worker_balance_freeze_days_snapshot)
         VALUES(:id,:mode,:status,:credit,:freeze)",
        ['id'=>$orderId,'mode'=>$mode,'status'=>$status,'credit'=>$credit,'freeze'=>$freezeDays]
    );
    $db->execute(
        "INSERT INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,
            frozen_delta_cents,business_type,business_id,idempotency_key,status,created_at)
         VALUES(:worker_id,'platform_order_income_frozen','credit',:amount,0,:frozen,'platform_order',:id,:key,'effective',UTC_TIMESTAMP())",
        ['worker_id'=>$orderId + 100,'amount'=>$ledgerAmount,'frozen'=>$ledgerAmount,
         'id'=>$orderId,'key'=>'platform-order:' . $orderId . ':worker-income']
    );
}

seedRelease($db,101,5,3);
seedRelease($db,102,0,3);
seedRelease($db,103,5,3);
seedRelease($db,104,5,3,70000,'platform','platform_balance','awaiting_platform_income',69999);
seedRelease($db,105,5,3,70000,'platform','worker_collect');
seedRelease($db,106,5,3,70000,'consumer');
seedRelease($db,107,5,0);
seedRelease($db,108,5,3,70000,'platform','platform_balance','pending');
seedRelease($db,109,5,3);
seedRelease($db,110,5,3); // 预约费退款成功，不得放行冻结余额
seedRelease($db,111,5,3); // refund.order_id 为空，仍须按支付单关联识别
$db->execute("INSERT INTO disputes(order_id,status) VALUES(103,'processing')");
$db->execute("INSERT INTO refunds(order_id,status) VALUES(110,'succeeded')");
$pay111=$db->insert("INSERT INTO payment_orders(order_id,business_type,status) VALUES(111,'order_booking','paid')");
$db->execute("INSERT INTO refunds(payment_order_id,order_id,status) VALUES(:payment_id,NULL,'succeeded')", ['payment_id'=>$pay111]);

$releaser = new PlatformOrderFrozenBalanceReleaser();
$failures = [];
$callback = static function (int $id, int $version, Throwable $exception) use (&$failures): void {
    $failures[$id] = $exception->getMessage();
};
$stats = $releaser->run($db, 100, $callback);
ensureRelease($stats['success'], 2, '到期工费解冻成功两单（待确认收入款不阻挡工费解冻）');
ensureRelease($stats['failure'], 1, '冻结源台账金额不一致不得解冻');
ensureRelease(isset($failures[104]), true, '异常冻结资金记录失败');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE entry_type='platform_order_income_release'"), 2, '仅有两笔有效释放');
$ledger = $db->fetch("SELECT SUM(available_delta_cents) available,SUM(frozen_delta_cents) frozen FROM worker_balance_ledger WHERE business_id=101 AND business_type='platform_order'");
ensureRelease((int)$ledger['available'],70000,'余额解冻后可提现增加700元');
ensureRelease((int)$ledger['frozen'],0,'余额解冻后冻结余额为0');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_type='platform_worker_income_released'"),2,'资金释放留下审计事件');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=103 AND entry_type='platform_order_income_release'"),0,'争议处理中不解冻');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=102 AND entry_type='platform_order_income_release'"),0,'冻结期未到不解冻');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=110 AND entry_type='platform_order_income_release'"),0,'已成功退款阻断冻结余额转可提现');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=111 AND entry_type='platform_order_income_release'"),0,'关联支付单退款成功即使退款单缺失订单ID也阻断');
ensureRelease($releaser->releaseOne($db,110),false,'直接调用也不能为成功退款单放行工费');
ensureRelease($releaser->releaseOne($db,111),false,'直接调用使用支付订单关联校验退款');

$repeat = $releaser->run($db, 100, $callback);
ensureRelease($repeat['success'],0,'重复扫描不可重复入账');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE entry_type='platform_order_income_release'"),2,'重复扫描释放条数不变');
ensureRelease($releaser->releaseOne($db,101),false,'同一订单手工重试不可重复解冻');
ensureRelease($releaser->releaseOne($db,102),false,'直接调用同样校验冻结截止时间');

$db->execute("UPDATE disputes SET status='resolved' WHERE order_id=103");
$after = $releaser->run($db, 100, $callback);
ensureRelease($after['success'],1,'争议关闭后继续解冻');
ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=103 AND entry_type='platform_order_income_release'"),1,'争议订单仅释放一次');
foreach ([105=>'师傅代收不可重复记账',106=>'非平台单不可解冻',107=>'冻结0天无需转移',108=>'未完成验收结算不可解冻'] as $orderId=>$label) {
    ensureRelease((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=:id AND entry_type='platform_order_income_release'",['id'=>$orderId]),0,$label);
}
ensureRelease((string)$db->fetchValue('SELECT settlement_status FROM order_platform_terms WHERE order_id=109'),'awaiting_platform_income','解冻不会伪造平台收入款到账');

$control->pdo()->exec('DROP DATABASE `' . $fixtureDatabase . '`');
echo "Platform frozen worker balance MySQL 5.7 integration: OK\n";

<?php

declare(strict_types=1);

use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\RepairGuardedPlatformOrderAdminRepository;

$root = dirname(__DIR__, 2);
spl_autoload_register(static function(string $class)use($root):void{if(str_starts_with($class,'WorkerAdmin\\')){$file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require_once $file;}});
require_once $root.'/server/app/Core/Database.php';
require_once $root.'/server/app/Contracts/PlatformOrderAdminRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Infrastructure/Persistence/RepairGuardedPlatformOrderAdminRepository.php';

function repairGuardEnsure($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
        exit(1);
    }
    echo '[PASS] '.$label."\n";
}

if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('V250_DB_NAME') !== 'worker_test') {
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
$rootDb = new Database($config);
repairGuardEnsure(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

$dbName = 'worker_v250_publish_repair_guard_ci';
$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database'] = $dbName;
$db = new Database($config);

$ddl = [
    "CREATE TABLE orders(
        id BIGINT UNSIGNED PRIMARY KEY,
        public_no VARCHAR(32) NOT NULL,
        order_source_type VARCHAR(24) NOT NULL,
        payment_status VARCHAR(24) NOT NULL,
        audit_status VARCHAR(24) NOT NULL,
        publish_status VARCHAR(24) NOT NULL,
        lifecycle_status VARCHAR(24) NOT NULL,
        refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
        selected_quote_id BIGINT UNSIGNED NULL,
        assigned_worker_id BIGINT UNSIGNED NULL,
        current_assignment_id BIGINT UNSIGNED NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        audit_note VARCHAR(255) NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB",
    "CREATE TABLE order_platform_terms(order_id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
    "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE scheduled_job_failures(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_name VARCHAR(64) NOT NULL,
        business_key VARCHAR(128) NOT NULL,
        error_message TEXT NULL,
        resolved_at DATETIME NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB",
    "CREATE TABLE order_events(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        actor_type VARCHAR(24) NOT NULL,
        actor_id BIGINT UNSIGNED NULL,
        payload_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB",
];
foreach ($ddl as $sql) $db->pdo()->exec($sql);

$inner = new class($db) implements PlatformOrderAdminRepository {
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function dashboard(): array { return []; }
    public function create(array $command, int $adminId): int { return 0; }
    public function publish(int $orderId, int $adminId, int $quoteDays): void
    {
        $changed = $this->db->execute(
            "UPDATE orders
             SET audit_status='approved',publish_status='published',audit_note='inner publish',updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND publish_status<>'published'",
            ['id'=>$orderId]
        );
        if ($changed !== 1) throw new OrderOperationException('publish changed', 'INNER_PUBLISH_CHANGED', 409);
    }
    public function selectQuote(int $orderId, int $quoteId, int $adminId): void {}
    public function createInvite(int $orderId, int $adminId, int $validDays): array { return []; }
    public function revokeInvite(int $orderId, int $inviteId, int $adminId): void {}
};
$guard = new RepairGuardedPlatformOrderAdminRepository($inner, $db);

$seed = static function (Database $db, int $id, string $updatedSql, bool $withTerms=true, bool $withLocation=true): void {
    $db->execute(
        "INSERT INTO orders(id,public_no,order_source_type,payment_status,audit_status,publish_status,lifecycle_status,
             refund_status,selected_quote_id,assigned_worker_id,current_assignment_id,lat,lng,updated_at)
         VALUES(:id,:no,'platform','paid','draft','hidden','active','none',NULL,NULL,NULL,:lat,:lng,".$updatedSql.")",
        [
            'id'=>$id,
            'no'=>'PR'.$id,
            'lat'=>$withLocation ? 31.2304 : null,
            'lng'=>$withLocation ? 121.4737 : null,
        ]
    );
    if ($withTerms) $db->execute('INSERT INTO order_platform_terms(order_id) VALUES(:id)', ['id'=>$id]);
};

// 1) 正常自动发布窗口内，直接 POST 手动 publish 必须被服务端拒绝。
$seed($db, 301, 'UTC_TIMESTAMP()');
$blockedCode = '';
try {
    $guard->publish(301, 9, 7);
} catch (OrderOperationException $exception) {
    $blockedCode = $exception->apiCode();
}
repairGuardEnsure($blockedCode, 'PLATFORM_PUBLISH_REPAIR_NOT_READY', '正常自动发布窗口禁止人工 publish');
repairGuardEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=301'), 'hidden', '被拒绝订单保持隐藏');

// 2) 没有显式 failure，但超过 5 分钟仍未发布，可作为 stuck 异常修复。
$seed($db, 302, 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 MINUTE)');
$guard->publish(302, 9, 7);
repairGuardEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=302'), 'published', '超时订单允许人工修复发布');
repairGuardEnsure((string)$db->fetchValue('SELECT audit_note FROM orders WHERE id=302'), '自动发布异常人工修复', '异常修复写入明确审核说明');
$payload302 = json_decode((string)$db->fetchValue("SELECT payload_json FROM order_events WHERE order_id=302 AND event_type='platform_auto_publish_repaired' ORDER BY id DESC LIMIT 1"), true);
repairGuardEnsure((string)($payload302['reason'] ?? ''), 'publish_stuck', '超时修复事件记录 stuck 原因');

// 3) 自动任务已有明确失败时，即使不足 5 分钟也可人工修复，并关闭未解决 failure。
$seed($db, 303, 'UTC_TIMESTAMP()');
$db->execute(
    "INSERT INTO scheduled_job_failures(job_name,business_key,error_message,resolved_at,created_at)
     VALUES('platform_auto_publish','order:303','scheduler execution failed',NULL,UTC_TIMESTAMP())"
);
$failureId = (int)$db->fetchValue("SELECT id FROM scheduled_job_failures WHERE business_key='order:303' ORDER BY id DESC LIMIT 1");
$guard->publish(303, 9, 7);
repairGuardEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=303'), 'published', '明确失败订单允许立即人工修复');
repairGuardEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_failures WHERE business_key='order:303' AND resolved_at IS NULL"), 0, '修复后关闭未解决自动发布 failure');
$payload303 = json_decode((string)$db->fetchValue("SELECT payload_json FROM order_events WHERE order_id=303 AND event_type='platform_auto_publish_repaired' ORDER BY id DESC LIMIT 1"), true);
repairGuardEnsure((string)($payload303['reason'] ?? ''), 'scheduler_failure', '明确失败修复事件记录 failure 原因');
repairGuardEnsure((int)($payload303['scheduler_failure_id'] ?? 0), $failureId, '修复事件关联原 failure id');

// 4) 即使已有 failure，数据完整性没修好也不能强制发布。
$seed($db, 304, 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)', false, true);
$db->execute(
    "INSERT INTO scheduled_job_failures(job_name,business_key,error_message,resolved_at,created_at)
     VALUES('platform_auto_publish','order:304','missing platform terms',NULL,UTC_TIMESTAMP())"
);
$incompleteCode = '';
try {
    $guard->publish(304, 9, 7);
} catch (OrderOperationException $exception) {
    $incompleteCode = $exception->apiCode();
}
repairGuardEnsure($incompleteCode, 'PLATFORM_TERMS_REQUIRED', '缺平台条款时异常修复仍被阻止');
repairGuardEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=304'), 'hidden', '数据未修复订单保持隐藏');
repairGuardEnsure((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_failures WHERE business_key='order:304' AND resolved_at IS NULL"), 1, '数据未修复时保留 failure');

// 5) 业务上已有争议等阻断时，不能借异常修复绕过业务状态。
$seed($db, 305, 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)');
$db->execute("INSERT INTO disputes(order_id,status) VALUES(305,'open')");
$businessBlockCode = '';
try {
    $guard->publish(305, 9, 7);
} catch (OrderOperationException $exception) {
    $businessBlockCode = $exception->apiCode();
}
repairGuardEnsure($businessBlockCode, 'PLATFORM_PUBLISH_REPAIR_BLOCKED', '存在争议时禁止人工修复发布');
repairGuardEnsure((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=305'), 'hidden', '业务阻断订单保持隐藏');

$rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
echo "Platform publish repair guard MySQL 5.7 integration: OK\n";

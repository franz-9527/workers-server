<?php

declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderRefundReviewWriter;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Core/Database.php';
require_once $root . '/server/app/Domain/Order/OrderOperationException.php';
require_once $root . '/server/app/Infrastructure/Persistence/PlatformOrderRefundReviewWriter.php';
require_once $root . '/server/app/Infrastructure/Persistence/PlatformOrderManualReceiptRecorder.php';

function ensureRefundReview($actual, $expected, string $label): void
{
    if ($actual !== $expected) {
        fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
        exit(1);
    }
    echo '[PASS] '.$label."\n";
}
function expectReviewRejection(callable $fn, string $code, string $label): void
{
    try { $fn(); }
    catch (OrderOperationException $e) {
        ensureRefundReview($e->apiCode(), $code, $label);
        return;
    }
    fwrite(STDERR, '[FAIL] '.$label.' expected rejection '.$code."\n");
    exit(1);
}

if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true'
    || (string)(getenv('V250_DB_NAME') ?: 'worker_test') !== 'worker_test') {
    fwrite(STDERR, "Refusing to run finance integration outside GitHub Actions worker_test.\n");
    exit(2);
}
$config = [
    'host'=>(string)(getenv('V250_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('V250_DB_PORT') ?: '3306'),
    'database'=>'worker_test',
    'username'=>(string)(getenv('V250_DB_USER') ?: 'root'),
    'password'=>(string)(getenv('V250_DB_PASSWORD') ?: 'root'),
];
$control = new Database($config);
ensureRefundReview(strpos((string)$control->fetchValue('SELECT VERSION()'), '5.7.') === 0,true,'连接真实 MySQL 5.7');
ensureRefundReview($control->tableExists('platform_order_refund_reviews'), true, 'V2.5.0 升级已建立退款复核表');
$name = 'worker_v250_refund_review_ci';
$control->pdo()->exec('DROP DATABASE IF EXISTS `'.$name.'`');
$control->pdo()->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database'] = $name;
$db = new Database($config);
$pdo = $db->pdo();
$ddl = [
    "CREATE TABLE orders(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,order_source_type VARCHAR(24) NOT NULL,
      accepted_at DATETIME NULL,title VARCHAR(120),public_no VARCHAR(50)) ENGINE=InnoDB",
    "CREATE TABLE order_platform_terms(order_id BIGINT UNSIGNED PRIMARY KEY,
      settlement_status VARCHAR(24),worker_labor_cents BIGINT UNSIGNED,
      worker_balance_credit_cents BIGINT UNSIGNED,worker_settlement_mode VARCHAR(24)) ENGINE=InnoDB",
    "CREATE TABLE payment_orders(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32)) ENGINE=InnoDB",
    "CREATE TABLE refunds(id BIGINT UNSIGNED PRIMARY KEY,payment_order_id BIGINT UNSIGNED NOT NULL,
      order_id BIGINT UNSIGNED NULL,public_no VARCHAR(40),amount_cents BIGINT UNSIGNED NOT NULL,
      status VARCHAR(24) NOT NULL,succeeded_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE worker_balance_ledger(id INT AUTO_INCREMENT PRIMARY KEY,business_id BIGINT UNSIGNED,
      idempotency_key VARCHAR(180),status VARCHAR(24),amount_cents BIGINT) ENGINE=InnoDB",
    "CREATE TABLE order_events(id INT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,event_type VARCHAR(80),
      actor_type VARCHAR(24),actor_id BIGINT UNSIGNED NULL,payload_json TEXT,created_at DATETIME) ENGINE=InnoDB",
    // 从真实升级产物复制列、唯一索引，确保表字段与生产结构完全一致，避免假模型掩盖 SQL 错误。
    "CREATE TABLE platform_order_refund_reviews LIKE worker_test.platform_order_refund_reviews",
    "CREATE TABLE platform_order_manual_receipts LIKE worker_test.platform_order_manual_receipts",
];
foreach ($ddl as $statement) $pdo->exec($statement);

function seedReview(Database $db, int $order, int $refund, int $amount, string $source='platform',
                    string $refundStatus='succeeded', string $mode='platform_balance'): void
{
    $db->execute("INSERT INTO orders(id,order_source_type,accepted_at,title,public_no)
                  VALUES(:id,:source,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 DAY),'测试订单',:public_no)",
                 ['id'=>$order,'source'=>$source,'public_no'=>'P-'.$order]);
    $db->execute("INSERT INTO order_platform_terms(order_id,settlement_status,worker_labor_cents,
                     worker_balance_credit_cents,worker_settlement_mode)
                  VALUES(:id,'settled',70000,:credit,:mode)",
                 ['id'=>$order,'credit'=>$mode==='platform_balance'?70000:0,'mode'=>$mode]);
    $db->execute("INSERT INTO payment_orders(id,order_id,business_type) VALUES(:id,:order_id,'order_booking')",
                 ['id'=>$refund+10000,'order_id'=>$order]);
    $db->execute("INSERT INTO refunds(id,payment_order_id,order_id,public_no,amount_cents,status,succeeded_at)
                  VALUES(:id,:pay,:order_id,:public_no,:amount,:status,UTC_TIMESTAMP())",
                 ['id'=>$refund,'pay'=>$refund+10000,'order_id'=>$order,'public_no'=>'R-'.$refund,
                  'amount'=>$amount,'status'=>$refundStatus]);
}

seedReview($db,101,9001,5000);
seedReview($db,102,9002,20000,'platform','succeeded','worker_collect');
seedReview($db,103,9003,4000,'consumer');
seedReview($db,104,9004,5000,'platform','processing');
seedReview($db,105,9005,4000);
$db->execute("INSERT INTO worker_balance_ledger(business_id,idempotency_key,status,amount_cents)
              VALUES(101,'platform-order:101:worker-income','effective',70000)");
$writer = new PlatformOrderRefundReviewWriter();
ensureRefundReview(count($writer->cases($db)),3,'列表仅展示已验收平台单成功退款');
expectReviewRejection(static function () use ($writer,$db): void { $writer->open($db,9003,1); },
                      'PLATFORM_REFUND_REVIEW_NOT_ELIGIBLE','普通客户订单不能进入平台财务复核');
expectReviewRejection(static function () use ($writer,$db): void { $writer->open($db,9004,1); },
                      'PLATFORM_REFUND_NOT_SUCCEEDED','退款处理中禁止按成功退款判责');

$writer->open($db,9001,10);
$writer->open($db,9001,10);
ensureRefundReview((int)$db->fetchValue('SELECT COUNT(*) FROM platform_order_refund_reviews WHERE refund_id=9001'),1,'同一退款重复建档幂等');
ensureRefundReview((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_type='platform_refund_review_opened'"),1,'重复建档不重复发审计事件');
$reviewId=(int)$db->fetchValue('SELECT id FROM platform_order_refund_reviews WHERE refund_id=9001');
expectReviewRejection(static function() use($writer,$db,$reviewId): void { $writer->decide($db,$reviewId,'platform',1000,'分担依据已经记录',10); },
                      'PLATFORM_REFUND_RESPONSIBILITY_INVALID','平台全责不能强制师傅补款');
$writer->decide($db,$reviewId,'shared',2000,'核对工单后双方按约承担',10);
ensureRefundReview((string)$db->fetchValue('SELECT status FROM platform_order_refund_reviews WHERE id=:id',['id'=>$reviewId]),'awaiting_recovery','共享责任须等待真实线下追偿');
expectReviewRejection(static function() use($writer,$db,$reviewId): void { $writer->decide($db,$reviewId,'worker',5000,'重复判责',10); },
                      'PLATFORM_REFUND_REVIEW_ALREADY_DECIDED','重复判责不允许覆盖原审计结论');
ensureRefundReview((int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=101'),1,'判责不自动扣除师傅原工费');
$db->execute("INSERT INTO order_events(order_id,event_type,actor_type,payload_json,created_at)
              VALUES(101,'platform_income_received','admin','{\"payment_reference\":\"BANK-USED-88\"}',UTC_TIMESTAMP())");
expectReviewRejection(static function() use($writer,$db,$reviewId): void { $writer->confirmRecovery($db,$reviewId,'BANK-USED-88','',10); },
                      'PLATFORM_REFUND_RECOVERY_REFERENCE_USED','不同收入款不可重复使用同一流水号');
$writer->confirmRecovery($db,$reviewId,'BANK-RECOVERY-9001','核对真实银行到账',10);
ensureRefundReview((int)$db->fetchValue('SELECT recovered_cents FROM platform_order_refund_reviews WHERE id=:id',['id'=>$reviewId]),2000,'实际核对到账2000分');
ensureRefundReview((string)$db->fetchValue('SELECT status FROM platform_order_refund_reviews WHERE id=:id',['id'=>$reviewId]),'resolved','足额追回后结案');
expectReviewRejection(static function() use($writer,$db,$reviewId): void { $writer->confirmRecovery($db,$reviewId,'BANK-RECOVERY-9001','',10); },
                      'PLATFORM_REFUND_RECOVERY_NOT_DUE','重复确认不会再次确认线下到账');
ensureRefundReview((int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=101'),1,'线下追偿不自动生成钱包扣账');

$writer->open($db,9002,11);
$workerCollectId=(int)$db->fetchValue('SELECT id FROM platform_order_refund_reviews WHERE refund_id=9002');
$writer->decide($db,$workerCollectId,'platform',0,'核实由平台承担本次退款',11);
ensureRefundReview((string)$db->fetchValue('SELECT status FROM platform_order_refund_reviews WHERE id=:id',['id'=>$workerCollectId]),'resolved','平台全责无需师傅追偿直接核账');
$writer->open($db,9005,11);
$fullWorkerId=(int)$db->fetchValue('SELECT id FROM platform_order_refund_reviews WHERE refund_id=9005');
$writer->decide($db,$fullWorkerId,'worker',4000,'确认师傅承担全额责任',11);
expectReviewRejection(static function() use($writer,$db,$fullWorkerId): void { $writer->confirmRecovery($db,$fullWorkerId,'BANK-RECOVERY-9001','',11); },
                      'PLATFORM_REFUND_RECOVERY_REFERENCE_USED','另一订单不能重复登记真实追偿流水号');
$writer->confirmRecovery($db,$fullWorkerId,'BANK-RECOVERY-9005-A','核实第一笔',11,1000);
ensureRefundReview((int)$db->fetchValue('SELECT recovered_cents FROM platform_order_refund_reviews WHERE id=:id',['id'=>$fullWorkerId]),1000,'分笔核账首笔到账10元');
ensureRefundReview((string)$db->fetchValue('SELECT status FROM platform_order_refund_reviews WHERE id=:id',['id'=>$fullWorkerId]),'awaiting_recovery','实际未收齐不可提前结案');
expectReviewRejection(static function() use($writer,$db,$fullWorkerId): void {
    $writer->confirmRecovery($db,$fullWorkerId,'BANK-RECOVERY-OVERPAID','超额登记',11,4000);
},'PLATFORM_REFUND_RECOVERY_AMOUNT_INVALID','多笔累计不能超过核准责任金额');
expectReviewRejection(static function() use($writer,$db,$fullWorkerId): void {
    $writer->confirmRecovery($db,$fullWorkerId,'BANK-RECOVERY-9005-A','重复上报第一笔',11,1000);
},'PLATFORM_REFUND_RECOVERY_REFERENCE_USED','已登记分笔流水不能重复使用');
$writer->confirmRecovery($db,$fullWorkerId,'BANK-RECOVERY-9005-B','核实最后一笔',11,3000);
ensureRefundReview((int)$db->fetchValue('SELECT recovered_cents FROM platform_order_refund_reviews WHERE id=:id',['id'=>$fullWorkerId]),4000,'累计核实到账40元');
ensureRefundReview((string)$db->fetchValue('SELECT status FROM platform_order_refund_reviews WHERE id=:id',['id'=>$fullWorkerId]),'resolved','最后一笔核实足额后才结案');
ensureRefundReview((int)$db->fetchValue("SELECT COUNT(*) FROM platform_order_manual_receipts WHERE refund_review_id=:id",['id'=>$fullWorkerId]),2,'同一复核单保留两笔独立真实收款凭证');
ensureRefundReview((int)$db->fetchValue("SELECT SUM(amount_cents) FROM platform_order_manual_receipts WHERE refund_review_id=:id",['id'=>$fullWorkerId]),4000,'分笔收款凭证合计等于累计追偿');
ensureRefundReview((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_type='platform_refund_recovery_confirmed'"),3,'三笔外部追偿各留不可重放审计事件');
ensureRefundReview(count($writer->cases($db)),3,'结案仍保留退款核账历史');
foreach ($writer->cases($db) as $reviewRow) {
    if ((int)($reviewRow['review_id'] ?? 0) === $fullWorkerId) {
        ensureRefundReview((int)$reviewRow['receipt_total_cents'],4000,'核账后台按真实分笔凭证核算已回收');
        ensureRefundReview((int)$reviewRow['receipt_count'],2,'核账后台展示逐笔凭证数量');
    }
}

$control->pdo()->exec('DROP DATABASE `'.$name.'`');
echo "Platform refund responsibility and offline recovery MySQL 5.7 integration: OK\n";

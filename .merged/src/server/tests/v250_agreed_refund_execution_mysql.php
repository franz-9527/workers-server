<?php

declare(strict_types=1);

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseAgreedRefundExecutionRepository;

    if (!function_exists('business_no')) {
        function business_no(string $prefix): string
        {
            static $seq = 0;
            $seq++;
            return $prefix.'-AGREED-CI-'.str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        }
    }

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once __DIR__.'/cancellation_risk_fixture.php';
    require_once $root.'/server/app/Contracts/AgreedRefundExecutionRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseAgreedRefundExecutionRepository.php';

    function agreedRefundMysqlSame($actual, $expected, string $label): void
    {
        if ($actual !== $expected) {
            fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
            exit(1);
        }
        fwrite(STDOUT, '[PASS] '.$label."\n");
    }

    if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('V250_DB_NAME') !== 'worker_test') {
        fwrite(STDERR, "This test is restricted to GitHub Actions worker_test.\n");
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
    agreedRefundMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0, true, 'MySQL 5.7 connected');

    $dbName = 'worker_v250_agreed_refund_execution_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);

    $db->pdo()->exec("CREATE TABLE orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        title VARCHAR(160) NOT NULL DEFAULT '',workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',
        order_source_type VARCHAR(24) NOT NULL DEFAULT 'platform',lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'active',
        fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'scheduled',payment_status VARCHAR(24) NOT NULL DEFAULT 'paid',
        dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',booking_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
        refund_status VARCHAR(24) NOT NULL DEFAULT 'pending_review',publish_status VARCHAR(24) NOT NULL DEFAULT 'hidden',
        quote_status VARCHAR(24) NOT NULL DEFAULT 'selected',assignment_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
        version INT NOT NULL DEFAULT 1,cancelled_at DATETIME NULL,updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_cancel_requests(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
        requested_refund_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,reason VARCHAR(1000) NOT NULL DEFAULT '',
        reason_code VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL,requested_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE payment_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
        business_type VARCHAR(40) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE refunds(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,payment_order_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(1000) NOT NULL,status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,
        failure_code VARCHAR(64) NULL,requested_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE worker_balance_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_type VARCHAR(32) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,entry_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE run_empty_fee_claims(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_events(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(80) NOT NULL,event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    $seed = static function (Database $db, int $id, int $requested, int $paid, string $lifecycle = 'active'): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,title,workflow_profile,order_source_type,lifecycle_status,fulfillment_status,payment_status,dispute_status,booking_fee_cents,refund_status,publish_status,quote_status,assignment_status,version,updated_at)
             VALUES(:id,:no,'平台退款执行测试','construction','platform',:lifecycle,'scheduled','paid','none',:paid,'pending_review','hidden','selected','accepted',4,UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-AGREED-'.$id,'paid'=>$paid,'lifecycle'=>$lifecycle]
        );
        $db->execute(
            "INSERT INTO order_cancel_requests(public_id,order_id,requested_refund_cents,reason,reason_code,status,requested_at,updated_at)
             VALUES(:request_id,:order_id,:requested,'双方已接受全额线上退款','bilateral_refund_execution_required','pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['request_id'=>'CR-AGREED-'.$id,'order_id'=>$id,'requested'=>$requested]
        );
        $db->execute(
            "INSERT INTO payment_orders(public_no,order_id,business_type,status,amount_cents)
             VALUES(:payment_no,:order_id,'booking_fee','paid',:paid)",
            ['payment_no'=>'PAY-AGREED-'.$id,'order_id'=>$id,'paid'=>$paid]
        );
        $db->execute("INSERT INTO quotes(order_id,status,updated_at) VALUES(:id,'selected',UTC_TIMESTAMP())", ['id'=>$id]);
        $db->execute("INSERT INTO assignments(order_id,status,updated_at) VALUES(:id,'accepted',UTC_TIMESTAMP())", ['id'=>$id]);
        $db->execute("INSERT INTO invite_tokens(order_id,status) VALUES(:id,'active')", ['id'=>$id]);
        $db->execute("INSERT INTO order_role_grants(order_id,status) VALUES(:id,'active')", ['id'=>$id]);
        $db->execute("INSERT INTO order_deadlines(order_id,status,updated_at) VALUES(:id,'pending',UTC_TIMESTAMP())", ['id'=>$id]);
    };

    $repo = new DatabaseAgreedRefundExecutionRepository($db);

    // 真实链路：双方接受后订单已先冻结为 cancel_approved_pending_refund，再由 Web 财务生成退款单。
    $seed($db, 1, 1000, 1000, 'cancel_approved_pending_refund');
    $cases = $repo->cases();
    $case1 = null;
    foreach ($cases as $row) if ((string)$row['request_id'] === 'CR-AGREED-1') $case1 = $row;
    agreedRefundMysqlSame((bool)($case1['can_prepare'] ?? false), true, '履约已冻结且金额与事实一致时允许生成退款单');
    agreedRefundMysqlSame((int)($case1['current_refundable_cents'] ?? 0), 1000, '财务页显示真实当前可退金额');

    $prepared = $repo->prepare('CR-AGREED-1', '已核对微信原支付和历史资金分配，金额一致', 77);
    agreedRefundMysqlSame((int)$prepared['refund_count'], 1, '冻结待退款订单按真实原支付生成一笔退款单');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM refunds WHERE order_id=1"), 'created', '新退款单仅为 created，不自动提交微信');
    agreedRefundMysqlSame((int)$db->fetchValue("SELECT amount_cents FROM refunds WHERE order_id=1"), 1000, '退款单金额严格等于双方协议金额');
    agreedRefundMysqlSame((int)$db->fetchValue("SELECT requested_by_admin_id FROM refunds WHERE order_id=1"), 77, '退款单记录真实 Web admin_users.id');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM order_cancel_requests WHERE order_id=1"), 'processing', '协议进入财务执行处理中');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT reason_code FROM order_cancel_requests WHERE order_id=1"), 'bilateral_refund_execution_prepared', '协议状态明确区分已生成退款单');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT lifecycle_status FROM orders WHERE id=1"), 'cancel_approved_pending_refund', '生成退款单后订单继续保持停止履约');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT refund_status FROM orders WHERE id=1"), 'pending_review', '订单等待 Web 人工确认退款');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM assignments WHERE order_id=1"), 'cancelled', '成交指派同步关闭');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM order_deadlines WHERE order_id=1"), 'cancelled', '订单自动化期限同步关闭');
    agreedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_type='agreed_refund_execution_prepared'"), 1, '财务生成退款单写入订单审计事件');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT event_key FROM order_events WHERE order_id=1 AND event_type='agreed_refund_execution_prepared'"), 'agreed_refund_execution_prepared:1', '财务执行事件使用稳定幂等键');

    $replayed = false;
    try {
        $repo->prepare('CR-AGREED-1', '重复点击不应再次生成退款单', 77);
    } catch (OrderOperationException $exception) {
        $replayed = $exception->apiCode() === 'AGREED_REFUND_EXECUTION_CHANGED';
    }
    agreedRefundMysqlSame($replayed, true, '重复生成退款单被状态机阻断');
    agreedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE order_id=1"), 1, '重复点击不会制造第二笔退款');

    // 兼容新状态冻结前已经产生的旧 execution_required 记录。
    $seed($db, 2, 1000, 1000, 'active');
    agreedRefundMysqlSame((bool)array_values(array_filter($repo->cases(), static function (array $row): bool {
        return (string)$row['request_id'] === 'CR-AGREED-2' && !empty($row['can_prepare']);
    }))[0]['can_prepare'], true, '旧 active execution_required 仍可继续财务执行');

    $seed($db, 3, 1000, 900);
    $drift = false;
    try {
        $repo->prepare('CR-AGREED-3', '当前真实可退金额已经变化，应停止执行', 77);
    } catch (OrderOperationException $exception) {
        $drift = $exception->apiCode() === 'AGREED_REFUND_AMOUNT_CHANGED';
    }
    agreedRefundMysqlSame($drift, true, '真实可退金额变化时不能单方面修改双方协议');
    agreedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE order_id=3"), 0, '金额漂移时不创建退款单');
    agreedRefundMysqlSame((string)$db->fetchValue("SELECT status FROM order_cancel_requests WHERE order_id=3"), 'pending', '金额漂移时协议记录保持待财务核对');

    $seed($db, 4, 1000, 1000);
    $db->execute("UPDATE orders SET dispute_status='open' WHERE id=4");
    $db->execute("INSERT INTO disputes(order_id,status) VALUES(4,'open')");
    $disputeBlocked = false;
    try {
        $repo->prepare('CR-AGREED-4', '争议仍未结案，不应执行退款', 77);
    } catch (OrderOperationException $exception) {
        $disputeBlocked = $exception->apiCode() === 'AGREED_REFUND_DISPUTE_ACTIVE';
    }
    agreedRefundMysqlSame($disputeBlocked, true, '未结争议先处理争议，不能直接退款');
    agreedRefundMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM refunds WHERE order_id=4"), 0, '争议阻断时不创建退款单');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 agreed refund Web execution MySQL 5.7: OK\n";
}

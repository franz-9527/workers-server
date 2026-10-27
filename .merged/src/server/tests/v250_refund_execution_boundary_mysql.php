<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;
    final class OrderNotificationService
    {
        public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void {}
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRepository;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/OrderCancellationRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderCancellationRepository.php';

    function refundExecutionSame($actual, $expected, string $label): void
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
    refundExecutionSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0, true, 'MySQL 5.7 connected');

    $dbName = 'worker_v250_refund_execution_boundary_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);

    $db->pdo()->exec("CREATE TABLE orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        user_id BIGINT UNSIGNED NOT NULL,
        assigned_worker_id BIGINT UNSIGNED NULL,
        current_assignment_id BIGINT UNSIGNED NULL,
        assignment_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
        fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'scheduled',
        lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'active',
        refund_status VARCHAR(24) NOT NULL DEFAULT 'pending_review',
        publish_status VARCHAR(24) NOT NULL DEFAULT 'hidden',
        quote_status VARCHAR(24) NOT NULL DEFAULT 'selected',
        payment_status VARCHAR(24) NOT NULL DEFAULT 'paid',
        workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',
        order_source_type VARCHAR(24) NOT NULL DEFAULT 'platform',
        dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',
        booking_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
        version INT NOT NULL DEFAULT 1,
        cancelled_at DATETIME NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_cancel_requests(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        order_id BIGINT UNSIGNED NOT NULL,
        applicant_type VARCHAR(24) NOT NULL,
        applicant_id BIGINT UNSIGNED NULL,
        reason_code VARCHAR(64) NOT NULL,
        reason VARCHAR(1000) NOT NULL,
        status VARCHAR(24) NOT NULL,
        requested_refund_cents INT NOT NULL DEFAULT 0,
        requested_at DATETIME NOT NULL,
        closed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE assignments(
        id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE payment_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
        business_type VARCHAR(40) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE refunds(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_order_id BIGINT UNSIGNED NOT NULL,
        amount_cents BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE worker_balance_ledger(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_type VARCHAR(32) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,
        entry_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE run_empty_fee_claims(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE commission_receivables(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_events(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(80) NOT NULL,
        event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,actor_type VARCHAR(24) NOT NULL,
        actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    $db->execute(
        "INSERT INTO orders(id,public_no,user_id,assigned_worker_id,current_assignment_id,assignment_status,fulfillment_status,lifecycle_status,refund_status,publish_status,quote_status,payment_status,workflow_profile,order_source_type,dispute_status,booking_fee_cents,version,updated_at)
         VALUES(1,'OD-EXEC-CI',101,202,301,'accepted','scheduled','active','pending_review','hidden','selected','paid','construction','platform','none',1000,7,UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO order_cancel_requests(public_id,order_id,applicant_type,applicant_id,reason_code,reason,status,requested_refund_cents,requested_at,created_at,updated_at)
         VALUES('CR-EXEC-CI',1,'user',101,'bilateral_refund_pending','双方协商全额退款','pending',1000,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,updated_at) VALUES(301,1,202,'accepted',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO payment_orders(public_no,order_id,business_type,status,amount_cents) VALUES('PAY-EXEC-CI',1,'booking_fee','paid',1000)");
    $db->execute("INSERT INTO quotes(order_id,status,updated_at) VALUES(1,'selected',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO invite_tokens(order_id,status) VALUES(1,'active')");
    $db->execute("INSERT INTO order_role_grants(order_id,status) VALUES(1,'active')");
    $db->execute("INSERT INTO order_deadlines(order_id,status,updated_at) VALUES(1,'pending',UTC_TIMESTAMP())");

    $repo = new DatabaseOrderCancellationRepository($db);
    $agreement = $repo->approveByAgreement(['worker_id'=>202,'user_id'=>2020], 'CR-EXEC-CI');
    refundExecutionSame((bool)($agreement['agreement_accepted'] ?? false), true, '对方接受退款方案');
    refundExecutionSame((bool)($agreement['execution_requires_platform'] ?? false), true, '平台单转入财务核账而非重新裁定');
    refundExecutionSame((string)$db->fetchValue("SELECT reason_code FROM order_cancel_requests WHERE public_id='CR-EXEC-CI'"), 'bilateral_refund_execution_required', '双方同意后进入财务执行语义');
    refundExecutionSame((string)$db->fetchValue("SELECT status FROM order_cancel_requests WHERE public_id='CR-EXEC-CI'"), 'pending', '财务桥接前取消申请保持可识别 pending');
    refundExecutionSame((string)$db->fetchValue("SELECT lifecycle_status FROM orders WHERE id=1"), 'cancel_approved_pending_refund', '双方同意后订单立即停止履约');
    refundExecutionSame((string)$db->fetchValue("SELECT refund_status FROM orders WHERE id=1"), 'pending_review', '资金尚未提交微信时保持待退款状态');
    refundExecutionSame((string)$db->fetchValue("SELECT publish_status FROM orders WHERE id=1"), 'hidden', '双方同意后订单保持隐藏');
    refundExecutionSame((string)$db->fetchValue("SELECT assignment_status FROM orders WHERE id=1"), 'cancelled', '双方同意后订单指派状态关闭');
    refundExecutionSame((string)$db->fetchValue("SELECT status FROM assignments WHERE id=301"), 'cancelled', '双方同意后真实指派记录关闭');
    refundExecutionSame((string)$db->fetchValue("SELECT status FROM quotes WHERE order_id=1"), 'cancelled', '双方同意后报价关系关闭');
    refundExecutionSame((string)$db->fetchValue("SELECT status FROM invite_tokens WHERE order_id=1"), 'revoked', '双方同意后邀请令牌失效');
    refundExecutionSame((string)$db->fetchValue("SELECT status FROM order_role_grants WHERE order_id=1"), 'revoked', '双方同意后临时订单角色授权撤销');
    refundExecutionSame((string)$db->fetchValue("SELECT status FROM order_deadlines WHERE order_id=1"), 'cancelled', '双方同意后自动履约期限关闭');
    refundExecutionSame((int)$db->fetchValue("SELECT COUNT(*) FROM refunds"), 0, '财务核账前不伪造退款单');

    $versionAfterAgreement = (int)$db->fetchValue("SELECT version FROM orders WHERE id=1");
    foreach (['approve','reject'] as $decision) {
        $blocked = false;
        try {
            $repo->review(
                ['channel'=>'mini_admin','user_id'=>901],
                'OD-EXEC-CI',
                $decision,
                'yes',
                'shared',
                '双方已经同意，仅应核对真实资金执行',
                $decision === 'approve' ? 1000 : 0
            );
        } catch (OrderOperationException $exception) {
            $blocked = $exception->apiCode() === 'REFUND_EXECUTION_WEB_REQUIRED';
        }
        refundExecutionSame($blocked, true, 'execution_required 禁止小程序重新'.$decision.'退款');
        refundExecutionSame((string)$db->fetchValue("SELECT status FROM order_cancel_requests WHERE public_id='CR-EXEC-CI'"), 'pending', '被阻断后取消申请保持 pending');
        refundExecutionSame((string)$db->fetchValue("SELECT reason_code FROM order_cancel_requests WHERE public_id='CR-EXEC-CI'"), 'bilateral_refund_execution_required', '被阻断后保留财务执行语义');
        refundExecutionSame((int)$db->fetchValue("SELECT version FROM orders WHERE id=1"), $versionAfterAgreement, '被阻断后订单版本不变化');
    }

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 agreed refund execution MySQL 5.7 boundary: OK\n";
}

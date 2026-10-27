<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;

    final class OrderNotificationService
    {
        public static array $queued = [];
        public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void
        {
            self::$queued[] = [$orderId,$eventKey,$eventType,$payload];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once __DIR__.'/cancellation_risk_fixture.php'; // 仅注册 WorkerAdmin autoload；平台夹具不会进入风控计分。
    require_once $root.'/server/app/Contracts/OrderCancellationRepository.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderCancellationRepository.php';

    if (!function_exists('business_no')) {
        function business_no(string $prefix='NO'): string
        {
            static $i = 0;
            return $prefix.'WEB'.str_pad((string)++$i, 18, '0', STR_PAD_LEFT);
        }
    }

    function webCancelMysqlSame($actual, $expected, string $label): void
    {
        if ($actual !== $expected) {
            fwrite(STDERR, '[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
            exit(1);
        }
        fwrite(STDOUT, '[PASS] '.$label."\n");
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
    webCancelMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName = 'worker_v250_cancel_web_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);

    $ddl = [
        "CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,
            workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',order_source_type VARCHAR(24) NOT NULL DEFAULT 'platform',
            payment_status VARCHAR(24) NOT NULL DEFAULT 'paid',lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'active',
            fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'not_started',refund_status VARCHAR(24) NOT NULL DEFAULT 'pending_review',
            audit_status VARCHAR(24) NOT NULL DEFAULT 'approved',selected_quote_id BIGINT UNSIGNED NULL,assigned_worker_id BIGINT UNSIGNED NULL,
            current_assignment_id BIGINT UNSIGNED NULL,contact_revealed_at DATETIME NULL,completion_submitted_at DATETIME NULL,accepted_at DATETIME NULL,
            booking_fee_cents INT NOT NULL DEFAULT 1000,publish_status VARCHAR(24) NOT NULL DEFAULT 'hidden',quote_status VARCHAR(24) NOT NULL DEFAULT 'quoting',
            assignment_status VARCHAR(24) NOT NULL DEFAULT 'none',cancelled_at DATETIME NULL,closed_at DATETIME NULL,version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(48) NOT NULL,
            status VARCHAR(24) NOT NULL,amount_cents INT NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
            applicant_type VARCHAR(24) NOT NULL,applicant_id BIGINT UNSIGNED NULL,request_type VARCHAR(48) NOT NULL,
            order_state_snapshot_json LONGTEXT NULL,reason_code VARCHAR(64) NOT NULL,reason VARCHAR(1000) NOT NULL,status VARCHAR(24) NOT NULL,
            requested_refund_cents INT NOT NULL DEFAULT 0,requested_at DATETIME NOT NULL,closed_at DATETIME NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64) NOT NULL UNIQUE,payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents INT NOT NULL,reason VARCHAR(1000) NOT NULL,
            status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,requested_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
            CONSTRAINT fk_cancel_web_refund_admin FOREIGN KEY(requested_by_admin_id) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_decisions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,cancel_request_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(24) NOT NULL,responsibility VARCHAR(24) NOT NULL,reason VARCHAR(1000) NOT NULL,refund_amount_cents INT NOT NULL DEFAULT 0,
            run_empty_fee_cents INT NOT NULL DEFAULT 0,refund_id BIGINT UNSIGNED NULL,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NOT NULL,created_at DATETIME NOT NULL,
            CONSTRAINT fk_cancel_web_decision_admin FOREIGN KEY(decided_by) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_relations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parent_order_id BIGINT UNSIGNED NOT NULL,child_order_id BIGINT UNSIGNED NOT NULL,relation_type VARCHAR(48) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_type VARCHAR(48) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(48) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,
            event_key VARCHAR(128) NOT NULL UNIQUE,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) $db->pdo()->exec($sql);
    $db->pdo()->exec('INSERT INTO admin_users(id) VALUES(77)');

    $seed = static function (Database $db, int $id, string $requestNo): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,user_id,created_at,updated_at) VALUES(:id,:no,101,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-WEB-CANCEL-'.$id]
        );
        $db->execute(
            "INSERT INTO payment_orders(order_id,business_type,status,amount_cents) VALUES(:id,'order_booking','paid',1000)",
            ['id'=>$id]
        );
        $snapshot = json_encode(['refund_status'=>'none','publish_status'=>'hidden'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $db->execute(
            "INSERT INTO order_cancel_requests(public_id,order_id,applicant_type,applicant_id,request_type,order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,requested_at,created_at,updated_at)
             VALUES(:request_no,:id,'user',101,'cancel_refund',:snapshot,'user_request','需要平台核对退款','pending',1000,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['request_no'=>$requestNo,'id'=>$id,'snapshot'=>$snapshot]
        );
    };

    $repo = new DatabaseOrderCancellationRepository($db);

    $seed($db, 1, 'CR-WEB-1');
    $web = $repo->review(
        ['channel'=>'web_admin','admin_user_id'=>77],
        'OD-WEB-CANCEL-1','approve','no','platform','后台核对原支付后批准部分原路退款',700
    );
    webCancelMysqlSame((bool)$web['approved'],true,'Web 管理员复用统一退款事务批准申请');
    webCancelMysqlSame((int)$db->fetchValue('SELECT requested_by_admin_id FROM refunds WHERE order_id=1'),77,'Web admin_users.id 写入 refunds.requested_by_admin_id 外键');
    webCancelMysqlSame((int)$db->fetchValue('SELECT decided_by FROM order_cancel_decisions WHERE order_id=1'),77,'Web admin_users.id 写入 order_cancel_decisions.decided_by 外键');
    webCancelMysqlSame((int)$db->fetchValue('SELECT amount_cents FROM refunds WHERE order_id=1'),700,'Web 审核仍按统一事务的批准金额生成退款单');
    $webEvent = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=1 AND event_type='cancel_approved' ORDER BY id DESC LIMIT 1");
    webCancelMysqlSame((string)$webEvent['actor_type'],'web_admin','Web 退款审核事件保留 web_admin ID 域');
    webCancelMysqlSame((int)$webEvent['actor_id'],77,'Web 退款审核事件记录真实 admin_users.id');
    $webPayload = json_decode((string)$webEvent['payload_json'], true) ?: [];
    webCancelMysqlSame((int)($webPayload['operator_admin_id'] ?? 0),77,'Web 退款审核 payload 写 operator_admin_id');
    webCancelMysqlSame($webPayload['operator_user_id'] ?? null,null,'Web 退款审核不伪写 mini users.id');

    $seed($db, 2, 'CR-MINI-2');
    $mini = $repo->review(
        ['channel'=>'mini_admin','user_id'=>901],
        'OD-WEB-CANCEL-2','approve','no','platform','小程序管理员批准部分原路退款',500
    );
    webCancelMysqlSame((bool)$mini['approved'],true,'mini 管理员继续复用同一退款事务');
    webCancelMysqlSame((int)$db->fetchValue('SELECT requested_by_admin_id IS NULL FROM refunds WHERE order_id=2'),1,'mini users.id 不写 refunds.requested_by_admin_id');
    webCancelMysqlSame((int)$db->fetchValue('SELECT decided_by IS NULL FROM order_cancel_decisions WHERE order_id=2'),1,'mini users.id 不写 order_cancel_decisions.decided_by');
    $miniEvent = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=2 AND event_type='cancel_approved' ORDER BY id DESC LIMIT 1");
    webCancelMysqlSame((string)$miniEvent['actor_type'],'mini_admin','mini 退款审核事件保留 mini_admin ID 域');
    webCancelMysqlSame((int)$miniEvent['actor_id'],901,'mini 退款审核事件记录真实 users.id');
    $miniPayload = json_decode((string)$miniEvent['payload_json'], true) ?: [];
    webCancelMysqlSame((int)($miniPayload['operator_user_id'] ?? 0),901,'mini 退款审核 payload 写 operator_user_id');
    webCancelMysqlSame($miniPayload['operator_admin_id'] ?? null,null,'mini 退款审核不伪写 admin_users.id');

    webCancelMysqlSame(count(OrderNotificationService::$queued),2,'两次审核各生成一条统一结果通知');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 web cancellation refund review MySQL 5.7 integration: OK\n";
}

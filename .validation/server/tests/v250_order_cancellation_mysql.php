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
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once __DIR__.'/cancellation_risk_fixture.php';
    require_once $root.'/server/app/Contracts/OrderCancellationRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Domain/Order/FulfillmentActor.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderCancellationRepository.php';
    require_once $root.'/server/app/Domain/Order/NegotiationPolicy.php';
    require_once $root.'/server/app/Contracts/OrderNegotiationRepository.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderNegotiationRepository.php';

    if (!function_exists('public_id')) {
        function public_id(): string
        {
            static $i = 0;
            $i++;
            return 'PID'.str_pad((string)$i, 23, '0', STR_PAD_LEFT);
        }
    }
    if (!function_exists('business_no')) {
        function business_no(string $prefix='NO'): string
        {
            static $i = 0;
            $i++;
            return $prefix.'CI'.str_pad((string)$i, 20, '0', STR_PAD_LEFT);
        }
    }

    function cancelMysqlSame($actual, $expected, string $label): void
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
    cancelMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName = 'worker_v250_cancel_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);

    $ddl = [
        "CREATE TABLE orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,
            workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',
            payment_status VARCHAR(24) NOT NULL,lifecycle_status VARCHAR(48) NOT NULL,fulfillment_status VARCHAR(24) NOT NULL,
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',audit_status VARCHAR(24) NOT NULL DEFAULT 'approved',selected_quote_id BIGINT UNSIGNED NULL,
            assigned_worker_id BIGINT UNSIGNED NULL,current_assignment_id BIGINT UNSIGNED NULL,contact_revealed_at DATETIME NULL,
            completion_submitted_at DATETIME NULL,accepted_at DATETIME NULL,booking_fee_cents INT NOT NULL DEFAULT 0,
            publish_status VARCHAR(24) NOT NULL DEFAULT 'published',quote_status VARCHAR(24) NOT NULL DEFAULT 'quoting',
            assignment_status VARCHAR(24) NOT NULL DEFAULT 'none',cancelled_at DATETIME NULL,closed_at DATETIME NULL,version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
            business_type VARCHAR(48) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents INT NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64) NOT NULL UNIQUE,payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents INT NOT NULL,reason VARCHAR(1000) NOT NULL,
            status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,requested_at DATETIME NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
            applicant_type VARCHAR(24) NOT NULL,applicant_id BIGINT UNSIGNED NULL,request_type VARCHAR(48) NOT NULL,
            order_state_snapshot_json LONGTEXT NULL,reason_code VARCHAR(64) NOT NULL,reason VARCHAR(1000) NOT NULL,status VARCHAR(24) NOT NULL,
            requested_refund_cents INT NOT NULL DEFAULT 0,requested_at DATETIME NOT NULL,closed_at DATETIME NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_decisions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,cancel_request_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(24) NOT NULL,responsibility VARCHAR(24) NOT NULL,reason VARCHAR(1000) NOT NULL,
            refund_amount_cents INT NOT NULL DEFAULT 0,run_empty_fee_cents INT NOT NULL DEFAULT 0,refund_id BIGINT UNSIGNED NULL,
            decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE quote_selections(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE appointment_records(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE run_empty_fee_claims(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_relations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parent_order_id BIGINT UNSIGNED NOT NULL,child_order_id BIGINT UNSIGNED NOT NULL,relation_type VARCHAR(48) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_type VARCHAR(48) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(48) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,
            event_key VARCHAR(128) NOT NULL UNIQUE,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,
            payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) $db->pdo()->exec($sql);
    installCancellationRiskFixture($db);

    $db->pdo()->exec('ALTER TABLE assignments ADD worker_id BIGINT UNSIGNED NULL');
    $db->pdo()->exec("ALTER TABLE run_empty_fee_claims ADD status VARCHAR(24) NOT NULL DEFAULT 'pending'");
    $db->pdo()->exec('CREATE TABLE commission_receivables(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED) ENGINE=InnoDB');

    $seedOrder = static function (Database $db, int $id, string $source='consumer', int $amount=1000): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,user_id,workflow_profile,order_source_type,payment_status,lifecycle_status,
                fulfillment_status,refund_status,audit_status,booking_fee_cents,publish_status,quote_status,assignment_status,version,created_at,updated_at)
             VALUES(:id,:public_no,101,'construction',:source,'paid','active','not_started','none','approved',:amount,'published','quoting','none',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'public_no'=>'OD-CI-'.$id,'source'=>$source,'amount'=>$amount]
        );
        $db->execute(
            "INSERT INTO payment_orders(order_id,business_type,status,amount_cents) VALUES(:order_id,'order_booking','paid',:amount)",
            ['order_id'=>$id,'amount'=>$amount]
        );
    };

    $repo = new DatabaseOrderCancellationRepository($db);

    $seedOrder($db, 1);
    $auto = $repo->request(['user_id'=>101], 'OD-CI-1', '尚未成交，用户取消订单', 'user_request');
    cancelMysqlSame((bool)$auto['auto_approved'], true, '未成交普通用户单自动批准取消');
    cancelMysqlSame((int)$auto['refund_cents'], 1000, '自动取消按真实预约费全额登记退款');
    cancelMysqlSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'), 'cancel_approved_pending_refund', '自动取消进入待原路退款生命周期');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=1'), 'approved', '自动取消申请无需管理员审核');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=1 AND status=\'pending_review\''), 1, '自动取消生成唯一退款记录等待可靠重试桥');
    cancelMysqlSame((int)$db->fetchValue('SELECT refund_amount_cents FROM order_cancel_decisions WHERE order_id=1'), 1000, '自动取消决策记录真实退款金额');
    cancelMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_type='cancel_approved'"), 1, '自动取消写入可审计订单事件');

    $seedOrder($db, 2);
    $db->execute("INSERT INTO assignments(order_id,status,updated_at) VALUES(2,'cancelled',UTC_TIMESTAMP())");
    $manual = $repo->request(['user_id'=>101], 'OD-CI-2', '已有师傅处理记录，申请取消', 'user_request');
    cancelMysqlSame((bool)$manual['auto_approved'], false, '存在历史指派时必须转人工审核');
    cancelMysqlSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=2'), 'active', '人工审核前订单不直接取消');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=2'), 'pending', '有履约关系的取消保持待审核');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=2'), 0, '人工审核前不预先生成退款单');

    $seedOrder($db, 3, 'platform');
    $platform = $repo->request(['user_id'=>101], 'OD-CI-3', '平台承接单申请取消退款', 'user_request');
    cancelMysqlSame((bool)$platform['auto_approved'], false, '平台承接单即使未派师傅也不自动退款');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=3'), 'pending', '平台单继续进入人工资金审核');

    // 2026-10-08：真实事务验证协商准入、对方身份、反提案、幂等和资金执行阻断。
    $negotiation = new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderNegotiationRepository($db);
    $seedOrder($db, 14);
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status) VALUES(140,14,202,'accepted')");
    $db->execute("UPDATE orders SET assigned_worker_id=202,current_assignment_id=140 WHERE id=14");
    $proposal = $repo->request(['user_id'=>101], 'OD-CI-14', '双方协商取消退款', 'user_request');
    $reference = $proposal['request_id'];
    try { $repo->review(['channel'=>'mini_admin','user_id'=>901], 'OD-CI-14', 'approve', 'no', 'shared', '提前审核双方方案', 1000); cancelMysqlSame(true, false, '管理员不能提前裁定'); }
    catch (OrderOperationException $e) { cancelMysqlSame($e->apiCode(), 'NEGOTIATION_NOT_ESCALATED', '未申请介入时直接审核被阻断'); }
    cancelMysqlSame((string)$db->fetchValue('SELECT reason_code FROM order_cancel_requests WHERE order_id=14'), 'bilateral_refund_pending', '成交后先进入双方退款协商');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=14'), 0, '对方接受前不创建退款');
    foreach ([['user_id'=>101], ['user_id'=>909,'worker_id'=>999]] as $unauthorized) {
        try { $repo->approveByAgreement($unauthorized, $reference); cancelMysqlSame(true, false, '不能自批或越权接受'); }
        catch (\WorkerAdmin\Domain\Order\OrderOperationException $e) { cancelMysqlSame($e->apiCode(), 'FORBIDDEN', '拒绝自批和无关师傅'); }
    }
    try { $negotiation->escalate(['user_id'=>101], 'OD-CI-14', 'refund', $reference, '还没有响应就想介入'); cancelMysqlSame(true, false, '不允许立即升级'); }
    catch (\WorkerAdmin\Domain\Order\OrderOperationException $e) { cancelMysqlSame($e->apiCode(), 'NEGOTIATION_WAIT_REQUIRED', '直接API不能绕过等待'); }
    $negotiation->respond(['user_id'=>302,'worker_id'=>202], 'OD-CI-14', 'refund', $reference, 'rejected', '退款金额需要再协商');
    cancelMysqlSame((string)$db->fetchValue('SELECT reason_code FROM order_cancel_requests WHERE order_id=14'), 'bilateral_refund_rejected', '拒绝只开放介入，不自动升级');
    $negotiation->proposeRefund(['user_id'=>302,'worker_id'=>202], 'OD-CI-14', $reference, 1000, '同意全额退款的新方案');
    try { $repo->approveByAgreement(['user_id'=>302,'worker_id'=>202], $reference); cancelMysqlSame(true, false, '师傅不能自批反提案'); }
    catch (\WorkerAdmin\Domain\Order\OrderOperationException $e) { cancelMysqlSame($e->apiCode(), 'FORBIDDEN', '反提案由用户接受'); }
    $agreed = $repo->approveByAgreement(['user_id'=>101], $reference);
    cancelMysqlSame((int)$agreed['refund_cents'], 1000, '接受后真实线上款自动进入原路退款');
    cancelMysqlSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=14'), 'cancel_approved_pending_refund', '接受退款同步取消订单');
    try { $repo->approveByAgreement(['user_id'=>101], $reference); cancelMysqlSame(true, false, '重复接受不能重复退'); }
    catch (\WorkerAdmin\Domain\Order\OrderOperationException $e) { cancelMysqlSame($e->apiCode(), 'REFUND_PROPOSAL_NOT_PENDING', '重复接受返回业务状态冲突'); }
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=14'), 1, '重复请求只生成一笔退款');

    $seedOrder($db, 15);
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status) VALUES(150,15,202,'accepted')");
    $db->execute("UPDATE orders SET assigned_worker_id=202,current_assignment_id=150 WHERE id=15");
    $risky = $repo->request(['user_id'=>101], 'OD-CI-15', '有收入账目需要核对', 'user_request');
    $db->execute("INSERT INTO worker_balance_ledger(business_type,business_id,entry_type,status,amount_cents) VALUES('order',15,'credit','effective',1000)");
    $guarded = $repo->approveByAgreement(['user_id'=>302,'worker_id'=>202], $risky['request_id']);
    cancelMysqlSame((bool)$guarded['execution_requires_platform'], true, '已入账资金即使双方接受也要核账');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=15'), 0, '资金异常不提前生成实际退款');
    // 4. 人工审核：已上门且师傅责任，允许按当前可退线上余额部分退款。
    $seedOrder($db, 4, 'consumer', 1000);
    $db->execute("UPDATE orders SET assigned_worker_id=55,assignment_status='accepted',fulfillment_status='scheduled' WHERE id=4");
    $db->execute("INSERT INTO assignments(order_id,status,updated_at) VALUES(4,'accepted',UTC_TIMESTAMP())");
    $pending4 = $repo->request(['user_id'=>101], 'OD-CI-4', '师傅已上门但确认无法继续施工', 'user_request');
    cancelMysqlSame((bool)$pending4['auto_approved'], false, '已上门履约事实必须进入管理员退款审核');
    $reference4=(string)$db->fetchValue('SELECT public_id FROM order_cancel_requests WHERE order_id=4');
    $negotiation->respond(['user_id'=>304,'worker_id'=>55], 'OD-CI-4', 'refund', $reference4, 'rejected', '双方金额仍未谈拢');
    $negotiation->escalate(['user_id'=>101], 'OD-CI-4', 'refund', $reference4, '退款协商未解决，请平台介入');
    $review4 = $repo->review(
        ['channel'=>'mini_admin','user_id'=>901],
        'OD-CI-4','approve','yes','worker','师傅确认无法继续履约，同意退款',700
    );
    cancelMysqlSame((bool)$review4['approved'], true, '管理员可批准符合规则的部分退款');
    cancelMysqlSame((int)$review4['refund_cents'], 700, '管理员批准金额按重新计算后的金额落库');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=4'), 'processing', '有退款金额的申请进入 processing');
    cancelMysqlSame((int)$db->fetchValue('SELECT amount_cents FROM refunds WHERE order_id=4'), 700, '人工退款按原支付单生成退款记录');
    cancelMysqlSame((int)$db->fetchValue('SELECT requested_by_admin_id IS NULL FROM refunds WHERE order_id=4'), 1, 'mini users.id 不写入 refunds.requested_by_admin_id');
    cancelMysqlSame((int)$db->fetchValue('SELECT decided_by IS NULL FROM order_cancel_decisions WHERE order_id=4'), 1, 'mini users.id 不写入 cancel_decisions.decided_by');
    cancelMysqlSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=4'), 'cancel_approved_pending_refund', '人工退款批准进入待原路退款生命周期');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM assignments WHERE order_id=4'), 'cancelled', '人工退款批准同步取消有效指派');
    $event4 = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=4 AND event_type='cancel_approved' ORDER BY id DESC LIMIT 1");
    $payload4 = json_decode((string)$event4['payload_json'], true);
    cancelMysqlSame((string)$event4['actor_type'], 'mini_admin', '退款审核事件记录 mini_admin channel');
    cancelMysqlSame((int)$event4['actor_id'], 901, '退款审核事件记录真实 mini users.id');
    cancelMysqlSame((int)($payload4['operator_user_id']??0), 901, '退款审核 payload 保留带类型管理员ID');

    $duplicate=false;
    try {
        $repo->review(['channel'=>'mini_admin','user_id'=>901],'OD-CI-4','approve','yes','worker','重复点击',700);
    } catch (OrderOperationException $e) {
        $duplicate=$e->apiCode()==='REFUND_REVIEW_CHANGED';
    }
    cancelMysqlSame($duplicate,true,'重复退款审核被 pending 状态条件阻断');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=4'),1,'重复审核不会生成第二笔退款');

    // 5. 拒绝退款恢复申请前快照；不能根据当前审核状态猜测并误发布订单。
    $seedOrder($db, 5, 'platform');
    $db->execute("UPDATE orders SET publish_status='hidden' WHERE id=5");
    $repo->request(['user_id'=>101], 'OD-CI-5', '平台单申请退款等待人工判断', 'user_request');
    $snapshot5=json_decode((string)$db->fetchValue('SELECT order_state_snapshot_json FROM order_cancel_requests WHERE order_id=5'),true);
    cancelMysqlSame((string)($snapshot5['publish_status']??''),'hidden','退款申请保存原 publish_status 快照');
    $review5=$repo->review(['channel'=>'mini_admin','user_id'=>902],'OD-CI-5','reject','no','user','订单仍可继续履约，拒绝退款',0);
    cancelMysqlSame((bool)$review5['approved'],false,'管理员可拒绝退款申请');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=5'),'rejected','拒绝后取消申请关闭');
    cancelMysqlSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=5'),'none','拒绝后订单退出退款状态');
    cancelMysqlSame((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=5'),'hidden','原本隐藏的平台单拒绝退款后仍保持隐藏');
    cancelMysqlSame((int)$db->fetchValue('SELECT decided_by IS NULL FROM order_cancel_decisions WHERE order_id=5'),1,'拒绝退款也不混写 mini users.id');

    // 6. 前端传入金额不能突破事务内重新计算的当前可退余额。
    $seedOrder($db, 6, 'platform', 1000);
    $repo->request(['user_id'=>101], 'OD-CI-6', '申请退款等待平台审核', 'user_request');
    $tooMuch=false;
    try {
        $repo->review(['channel'=>'mini_admin','user_id'=>903],'OD-CI-6','approve','no','platform','核对退款金额',1200);
    } catch (OrderOperationException $e) {
        $tooMuch=$e->apiCode()==='REFUND_AMOUNT_EXCEEDS_AVAILABLE' && (int)($e->data()['max_refund_cents']??0)===1000;
    }
    cancelMysqlSame($tooMuch,true,'超出当前可退余额的管理员输入被服务端拒绝');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=6'),'pending','金额校验失败保持待审核');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=6'),0,'金额校验失败不生成退款记录');

    // 7. 已上门但责任不在师傅时，不能批准退款，应由管理员拒绝并记录原因。
    $seedOrder($db, 7, 'consumer', 1000);
    $db->execute("UPDATE orders SET assigned_worker_id=56,assignment_status='accepted',fulfillment_status='scheduled' WHERE id=7");
    $db->execute("INSERT INTO assignments(order_id,status,updated_at) VALUES(7,'accepted',UTC_TIMESTAMP())");
    $repo->request(['user_id'=>101], 'OD-CI-7', '已上门后申请取消退款', 'user_request');
    $reference7=(string)$db->fetchValue('SELECT public_id FROM order_cancel_requests WHERE order_id=7');
    $negotiation->respond(['user_id'=>307,'worker_id'=>56], 'OD-CI-7', 'refund', $reference7, 'rejected', '双方金额仍未谈拢');
    $negotiation->escalate(['user_id'=>101], 'OD-CI-7', 'refund', $reference7, '退款协商未解决，请平台介入');
    $arrivalConflict=false;
    try {
        $repo->review(['channel'=>'mini_admin','user_id'=>904],'OD-CI-7','approve','yes','user','用户原因取消但尝试退款',1000);
    } catch (OrderOperationException $e) {
        $arrivalConflict=$e->apiCode()==='REFUND_ARRIVAL_RESPONSIBILITY_CONFLICT';
    }
    cancelMysqlSame($arrivalConflict,true,'已上门且非师傅责任时禁止批准退款');
    cancelMysqlSame((string)$db->fetchValue('SELECT status FROM order_cancel_requests WHERE order_id=7'),'pending','责任冲突不推进退款申请');

    // 8. 原本公开的人工审核订单，拒绝退款后恢复公开，而不是永久保持 refund 期间的 hidden。
    $seedOrder($db, 8, 'consumer', 1000);
    $db->execute("INSERT INTO assignments(order_id,status,updated_at) VALUES(8,'cancelled',UTC_TIMESTAMP())");
    $repo->request(['user_id'=>101], 'OD-CI-8', '存在历史事实需要人工审核', 'user_request');
    cancelMysqlSame((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=8'),'hidden','退款待审核期间订单隐藏');
    $repo->review(['channel'=>'mini_admin','user_id'=>905],'OD-CI-8','reject','no','user','不满足退款条件，继续原流程',0);
    cancelMysqlSame((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=8'),'published','拒绝退款后恢复申请前 published 状态');

    // 9. 关联测量施工单即使快照原为 published，拒绝退款后也必须继续隐藏，不能绕开关联单限制。
    $seedOrder($db, 9, 'consumer', 1000);
    $db->execute("INSERT INTO assignments(order_id,status,updated_at) VALUES(9,'cancelled',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO order_relations(parent_order_id,child_order_id,relation_type,status) VALUES(90,9,'measurement_construction','active')");
    $repo->request(['user_id'=>101], 'OD-CI-9', '关联施工单申请退款', 'user_request');
    $repo->review(['channel'=>'mini_admin','user_id'=>906],'OD-CI-9','reject','no','user','关联施工仍需保持私有流转',0);
    cancelMysqlSame((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=9'),'hidden','关联测量施工单拒绝退款后继续隐藏');

    // 师傅自身原因退出：全额原路退款，不能计入客户取消损失。
    $seedOrder($db,20);
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,updated_at) VALUES(200,20,203,'accepted',UTC_TIMESTAMP())");
    $db->execute("UPDATE orders SET assigned_worker_id=203,current_assignment_id=200,assignment_status='accepted' WHERE id=20");
    try { $repo->request(['user_id'=>909,'worker_id'=>999],'OD-CI-20','尝试取消别人的服务','worker_unable_to_serve'); cancelMysqlSame(true,false,'无关师傅不可取消'); }
    catch(OrderOperationException $e) { cancelMysqlSame($e->apiCode(),'FORBIDDEN','无关师傅不可取消'); }
    $workerCancel=$repo->request(['user_id'=>303,'worker_id'=>203],'OD-CI-20','临时无法安排上门服务','worker_unable_to_serve');
    cancelMysqlSame($workerCancel['worker_cancelled'],true,'师傅主动承担自身无法服务责任');
    cancelMysqlSame($workerCancel['refund_cents'],1000,'师傅原因退出客户全额退款');
    cancelMysqlSame($db->fetchValue('SELECT responsibility FROM order_cancel_decisions WHERE order_id=20'),'worker','责任记为师傅');
    cancelMysqlSame((int)$db->fetchValue('SELECT run_empty_fee_cents FROM order_cancel_decisions WHERE order_id=20'),0,'客户不承担上门和时间补偿');
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancellation_risk_scores WHERE order_id=20'),0,'不向客户记录取消风控分');
    cancelMysqlSame($db->fetchValue("SELECT actor_type FROM order_events WHERE order_id=20 AND event_type='cancel_approved'"),'worker','审计保留真实取消发起人');
    try { $repo->request(['user_id'=>303,'worker_id'=>203],'OD-CI-20','重复点击取消服务','worker_unable_to_serve'); cancelMysqlSame(true,false,'重复取消不可重复退款'); }
    catch(OrderOperationException $e) { cancelMysqlSame($e->apiCode(),'ORDER_NOT_CANCELLABLE','重复取消被状态机拦截'); }
    cancelMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=20'),1,'师傅重复点击只生成一笔退款');
    $seedOrder($db,21);
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,updated_at) VALUES(210,21,203,'accepted',UTC_TIMESTAMP())");
    $db->execute("UPDATE orders SET assigned_worker_id=203,current_assignment_id=210,assignment_status='accepted',fulfillment_status='in_progress' WHERE id=21");
    try { $repo->request(['user_id'=>303,'worker_id'=>203],'OD-CI-21','已经施工不能直接退','worker_unable_to_serve'); cancelMysqlSame(true,false,'施工中须平台处理'); }
    catch(OrderOperationException $e) { cancelMysqlSame($e->apiCode(),'WORKER_CANCEL_NOT_ALLOWED','施工中的资金事实不走自动退款'); }

    $rootDb->execute('DROP DATABASE '.$dbName);
    echo "V2.5.0 order cancellation MySQL 5.7 integration: OK\n";
}

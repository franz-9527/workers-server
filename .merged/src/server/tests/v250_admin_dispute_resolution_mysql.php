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
        public static function queueAdminTask(Database $db, string $businessKey, string $eventType, string $title, array $payload = []): void {}
    }
}

namespace WorkerAdmin\Infrastructure\Persistence {
    use WorkerAdmin\Core\Database;

    // 争议集成测试只验证争议事务如何调用取消风控；风控自身有独立 MySQL 回归。
    final class DatabaseOrderCancellationRiskRepository
    {
        public static array $calls = [];
        public function __construct(Database $db) {}
        public function captureCancellation(array $order, string $responsibility = 'unclear', int $runEmptyCents = 0): void
        {
            self::$calls[] = [(int)($order['id'] ?? 0),$responsibility,$runEmptyCents];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRiskRepository;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDisputeRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
spl_autoload_register(static function(string $class)use($root):void{if(str_starts_with($class,'WorkerAdmin\\')){$file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($file))require_once $file;}});
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/OrderDisputeRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderDisputeRepository.php';

    if (!function_exists('business_no')) {
        function business_no($prefix): string
        {
            static $index = 0;
            $index++;
            return strtoupper((string)$prefix).'TEST'.str_pad((string)$index, 20, '0', STR_PAD_LEFT);
        }
    }

    function adminDisputeSame($actual, $expected, string $label): void
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
    $rootDb = new Database($config);
    adminDisputeSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v250_admin_dispute_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl = [
        "CREATE TABLE admin_users(
            id BIGINT UNSIGNED PRIMARY KEY,
            username VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 101,
            order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',
            lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'active',
            booking_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 5000,
            assigned_worker_id BIGINT UNSIGNED NULL,
            selected_quote_id BIGINT UNSIGNED NULL,
            assignment_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
            current_assignment_id BIGINT UNSIGNED NULL,
            publish_status VARCHAR(24) NOT NULL DEFAULT 'hidden',
            quote_status VARCHAR(24) NOT NULL DEFAULT 'selected',
            fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'in_progress',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
            dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',
            cancelled_at DATETIME NULL,
            closed_at DATETIME NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE disputes(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            responsibility VARCHAR(24) NOT NULL DEFAULT 'undetermined',
            resolution VARCHAR(1000) NULL,
            refund_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            run_empty_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            handled_by BIGINT UNSIGNED NULL,
            resolved_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_dispute_admin FOREIGN KEY(handled_by) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            business_type VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL,
            amount_cents BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            cancel_request_id BIGINT UNSIGNED NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            status VARCHAR(24) NOT NULL,
            requested_by_admin_id BIGINT UNSIGNED NULL,
            requested_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_refund_admin FOREIGN KEY(requested_by_admin_id) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(32) NOT NULL,
            direction VARCHAR(16) NOT NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            available_delta_cents BIGINT NOT NULL,
            frozen_delta_cents BIGINT NOT NULL,
            business_type VARCHAR(32) NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(128) NOT NULL UNIQUE,
            description VARCHAR(1000) NULL,
            status VARCHAR(24) NOT NULL,
            created_by_admin_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT fk_ledger_admin FOREIGN KEY(created_by_admin_id) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            cancelled_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE quotes(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NULL,
            status VARCHAR(24) NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            revoked_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            processed_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_key VARCHAR(128) NOT NULL,
            actor_type VARCHAR(24) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) $db->pdo()->exec($sql);
    $db->execute("INSERT INTO admin_users(id,username) VALUES(77,'web-admin'),(998,'seed-admin')");

    $seed = static function (Database $db, int $id, string $status='open', int $refund=0, int $runEmpty=0): void {
        $db->execute(
            "INSERT INTO orders(
                id,public_no,user_id,order_source_type,lifecycle_status,booking_fee_cents,assigned_worker_id,
                assignment_status,publish_status,quote_status,fulfillment_status,refund_status,dispute_status,version,updated_at
             ) VALUES(
                :id,:no,101,'consumer','active',5000,66,
                'accepted','hidden','selected','in_progress','none',:status,1,UTC_TIMESTAMP()
             )",
            ['id'=>$id,'no'=>'OD-ADMIN-'.$id,'status'=>$status]
        );
        $db->execute(
            "INSERT INTO disputes(public_no,order_id,status,responsibility,resolution,refund_amount_cents,run_empty_fee_cents,handled_by,resolved_at,updated_at)
             VALUES(:no,:order_id,:status,'undetermined',NULL,:refund,:run_empty,998,NULL,UTC_TIMESTAMP())",
            ['no'=>'DSP-ADMIN-'.$id,'order_id'=>$id,'status'=>$status,'refund'=>$refund,'run_empty'=>$runEmpty]
        );
    };

    $seedFinancial = static function (Database $db, int $id): void {
        $db->execute(
            "INSERT INTO orders(
                id,public_no,user_id,order_source_type,lifecycle_status,booking_fee_cents,assigned_worker_id,
                assignment_status,current_assignment_id,publish_status,quote_status,fulfillment_status,refund_status,dispute_status,version,updated_at
             ) VALUES(
                :id,:no,101,'platform','active',5000,66,
                'accepted',:assignment,'published','selected','in_progress','none','open',1,UTC_TIMESTAMP()
             )",
            ['id'=>$id,'no'=>'OD-ADMIN-'.$id,'assignment'=>900+$id]
        );
        $db->execute(
            "INSERT INTO disputes(public_no,order_id,status,responsibility,resolution,refund_amount_cents,run_empty_fee_cents,handled_by,resolved_at,updated_at)
             VALUES(:no,:order_id,'open','undetermined',NULL,0,0,998,NULL,UTC_TIMESTAMP())",
            ['no'=>'DSP-ADMIN-'.$id,'order_id'=>$id]
        );
        $db->execute(
            "INSERT INTO payment_orders(order_id,business_type,status,amount_cents) VALUES(:id,'booking_fee','paid',5000)",
            ['id'=>$id]
        );
        $db->execute(
            "INSERT INTO assignments(id,order_id,worker_id,status,cancelled_at,updated_at)
             VALUES(:assignment,:id,66,'accepted',NULL,UTC_TIMESTAMP())",
            ['assignment'=>900+$id,'id'=>$id]
        );
        $db->execute(
            "INSERT INTO quotes(order_id,worker_id,status,updated_at) VALUES(:id,66,'selected',UTC_TIMESTAMP())",
            ['id'=>$id]
        );
        $db->execute("INSERT INTO invite_tokens(order_id,status) VALUES(:id,'active')", ['id'=>$id]);
        $db->execute("INSERT INTO order_role_grants(order_id,status,revoked_at) VALUES(:id,'active',NULL)", ['id'=>$id]);
        $db->execute("INSERT INTO order_deadlines(order_id,status,processed_at,updated_at) VALUES(:id,'pending',NULL,UTC_TIMESTAMP())", ['id'=>$id]);
    };

    $repo = new DatabaseOrderDisputeRepository($db);
    $operator = ['channel'=>'mini_admin','user_id'=>701];

    // 1. 普通零金额 open 争议允许平台在小程序结案。
    $seed($db,1,'open');
    $result = $repo->resolveByAdmin($operator,'OD-ADMIN-1','双方证据核实完成，本次争议结案','shared');
    adminDisputeSame((string)$result['status'],'resolved','零金额 open 争议允许管理员结案');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM disputes WHERE order_id=1'),'resolved','争议状态更新为 resolved');
    adminDisputeSame((string)$db->fetchValue('SELECT dispute_status FROM orders WHERE id=1'),'resolved','订单争议状态同步 resolved');
    adminDisputeSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),2,'管理员结案递增订单版本');
    adminDisputeSame((int)$db->fetchValue('SELECT handled_by IS NULL FROM disputes WHERE order_id=1'),1,'mini users.id 不写入 handled_by');
    $event = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=1 AND event_type='dispute_resolved' ORDER BY id DESC LIMIT 1");
    $payload = json_decode((string)$event['payload_json'], true);
    adminDisputeSame((string)$event['actor_type'],'mini_admin','争议结案事件记录 mini_admin channel');
    adminDisputeSame((int)$event['actor_id'],701,'争议结案事件记录 mini users.id');
    adminDisputeSame((int)($payload['operator_user_id']??0),701,'事件 payload 保存带类型 mini 管理员 ID');
    adminDisputeSame(isset($payload['operator_admin_id']),false,'mini 事件不伪造 Web 管理员 ID');
    adminDisputeSame((string)($payload['responsibility']??''),'shared','事件记录责任归属');
    adminDisputeSame(count(OrderNotificationService::$queued),1,'管理员结案通知双方');

    // 2. processing 同样是平台已介入状态，可以结案。
    $seed($db,2,'processing');
    $repo->resolveByAdmin(['channel'=>'mini_admin','user_id'=>702],'OD-ADMIN-2','处理中事项已经确认完成','platform');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM disputes WHERE order_id=2'),'resolved','processing 争议允许管理员结案');

    // 3. 已经带真实退款金额的争议必须转网页后台，小程序不修改任何状态。
    $seed($db,3,'open',1200,0);
    $financial=false;
    try {
        $repo->resolveByAdmin($operator,'OD-ADMIN-3','尝试直接关闭退款争议','platform');
    } catch (OrderOperationException $e) {
        $financial=$e->apiCode()==='DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED';
    }
    adminDisputeSame($financial,true,'含退款金额争议拒绝小程序直接结案');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM disputes WHERE order_id=3'),'open','资金争议被拒绝后保持 open');
    adminDisputeSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=3'),1,'资金争议被拒绝后订单版本不变化');

    // 4. 跑空费同样属于资金处理，必须转网页后台。
    $seed($db,4,'open',0,800);
    $runEmptyBlocked=false;
    try {
        $repo->resolveByAdmin($operator,'OD-ADMIN-4','尝试直接关闭跑空费争议','worker');
    } catch (OrderOperationException $e) {
        $runEmptyBlocked=$e->apiCode()==='DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED';
    }
    adminDisputeSame($runEmptyBlocked,true,'含跑空费争议拒绝小程序直接结案');

    // 5. negotiating 仍属于双方自行协商阶段，不得由管理员提前介入。
    $seed($db,5,'negotiating');
    $notEscalated=false;
    try {
        $repo->resolveByAdmin($operator,'OD-ADMIN-5','平台不应提前介入','unclear');
    } catch (OrderOperationException $e) {
        $notEscalated=$e->apiCode()==='DISPUTE_ADMIN_NOT_FOUND';
    }
    adminDisputeSame($notEscalated,true,'双方协商阶段管理员不能提前结案');

    // 6. 重复提交在第一次成功后自然失效。
    $duplicate=false;
    try {
        $repo->resolveByAdmin($operator,'OD-ADMIN-1','重复点击','shared');
    } catch (OrderOperationException $e) {
        $duplicate=$e->apiCode()==='DISPUTE_ADMIN_NOT_FOUND';
    }
    adminDisputeSame($duplicate,true,'重复管理员结案被状态条件阻断');

    // 7. Web 资金裁定：1200退款 + 800跑空费后，剩余3000预约费自动补入退款。
    $seedFinancial($db,9);
    $web = $repo->resolveByAdmin(
        ['channel'=>'web_admin','admin_user_id'=>77],
        'OD-ADMIN-9',
        'Web 后台核对证据：用户承担部分跑空费，其余预约费退回',
        'user',
        1200,
        800
    );
    adminDisputeSame((int)$web['refund_cents'],4200,'Web 裁定自动把未分配3000预约费补入退款');
    adminDisputeSame((int)$web['run_empty_fee_cents'],800,'Web 裁定跑空费金额保持800');
    adminDisputeSame((int)$web['auto_refund_cents'],3000,'Web 返回自动补退款金额便于审计');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM disputes WHERE order_id=9'),'resolved','Web 资金争议原子结案');
    adminDisputeSame((int)$db->fetchValue('SELECT refund_amount_cents FROM disputes WHERE order_id=9'),4200,'争议记录保存实际退款总额');
    adminDisputeSame((int)$db->fetchValue('SELECT run_empty_fee_cents FROM disputes WHERE order_id=9'),800,'争议记录保存跑空费');
    adminDisputeSame((int)$db->fetchValue('SELECT handled_by FROM disputes WHERE order_id=9'),77,'Web handled_by 写入真实 admin_users.id');
    adminDisputeSame((int)$db->fetchValue('SELECT amount_cents FROM refunds WHERE order_id=9'),4200,'Web 争议生成待审核退款单');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM refunds WHERE order_id=9'),'pending_review','争议退款进入人工退款执行链');
    adminDisputeSame((int)$db->fetchValue('SELECT requested_by_admin_id FROM refunds WHERE order_id=9'),77,'争议退款 requested_by_admin_id 写真实 Web 管理员');
    adminDisputeSame((int)$db->fetchValue("SELECT amount_cents FROM worker_balance_ledger WHERE business_type='dispute' AND business_id=(SELECT id FROM disputes WHERE order_id=9)"),800,'跑空费计入师傅余额');
    adminDisputeSame((int)$db->fetchValue("SELECT created_by_admin_id FROM worker_balance_ledger WHERE business_type='dispute' AND business_id=(SELECT id FROM disputes WHERE order_id=9)"),77,'跑空费台账写真实 Web 管理员 ID');
    adminDisputeSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=9'),'cancel_approved_pending_refund','资金裁定后订单进入取消待退款');
    adminDisputeSame((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=9'),'cancelled','资金裁定终止履约');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM assignments WHERE order_id=9'),'cancelled','资金裁定撤销成交指派');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM quotes WHERE order_id=9'),'cancelled','资金裁定撤销报价');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM invite_tokens WHERE order_id=9'),'revoked','资金裁定撤销邀请');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM order_role_grants WHERE order_id=9'),'revoked','资金裁定撤销角色授权');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM order_deadlines WHERE order_id=9'),'cancelled','资金裁定取消后续自动截止任务');
    adminDisputeSame(DatabaseOrderCancellationRiskRepository::$calls[0] ?? null,[9,'user',800],'资金裁定在失效成交事实前调用取消风控取证');
    $webEvent = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=9 AND event_type='dispute_resolved' ORDER BY id DESC LIMIT 1");
    $webPayload = json_decode((string)$webEvent['payload_json'], true);
    adminDisputeSame((string)$webEvent['actor_type'],'web_admin','Web 争议事件记录 web_admin channel');
    adminDisputeSame((int)$webEvent['actor_id'],77,'Web 争议事件 actor_id 使用 admin_users.id');
    adminDisputeSame((int)($webPayload['operator_admin_id']??0),77,'Web 争议 payload 保存 operator_admin_id');
    adminDisputeSame(isset($webPayload['operator_user_id']),false,'Web 事件不污染 mini users.id 字段');
    adminDisputeSame((int)($webPayload['auto_refund_cents']??0),3000,'Web 事件记录自动补退款金额');

    // 8. 超出预约费的 Web 裁定必须整笔回滚，不能先结案或先入余额。
    $seedFinancial($db,10);
    $tooMuch=false;
    try {
        $repo->resolveByAdmin(
            ['channel'=>'web_admin','admin_user_id'=>77],
            'OD-ADMIN-10',
            '故意提交超额资金用于验证事务回滚',
            'shared',
            4500,
            1000
        );
    } catch (OrderOperationException $e) {
        $tooMuch=$e->apiCode()==='DISPUTE_FINANCE_EXCEEDS_BOOKING';
    }
    adminDisputeSame($tooMuch,true,'Web 资金裁定不能超过实收预约费');
    adminDisputeSame((string)$db->fetchValue('SELECT status FROM disputes WHERE order_id=10'),'open','超额裁定失败后争议保持 open');
    adminDisputeSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=10'),0,'超额裁定失败不创建退款');
    adminDisputeSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='dispute' AND business_id=(SELECT id FROM disputes WHERE order_id=10)"),0,'超额裁定失败不入师傅余额');
    adminDisputeSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=10'),'active','超额裁定失败不终止订单');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 admin dispute resolution MySQL 5.7 integration: OK\n";
}

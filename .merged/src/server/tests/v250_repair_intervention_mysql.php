<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;

    final class OrderNotificationService
    {
        public static array $queued = [];
        public static array $admin = [];

        public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void
        {
            self::$queued[] = [$orderId,$eventKey,$eventType,$payload];
        }

        public static function queueAdminTask(Database $db, string $eventKey, string $eventType, string $title, array $lines = []): void
        {
            self::$admin[] = [$eventKey,$eventType,$title,$lines];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminRepairResolutionRepository;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderRepairInterventionRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/OrderRepairInterventionRepository.php';
    require_once $root.'/server/app/Contracts/AdminRepairResolutionRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Domain/Order/RepairInterventionPolicy.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderRepairInterventionRepository.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseAdminRepairResolutionRepository.php';

    function repairMysqlSame($actual, $expected, string $label): void
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
    repairMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName = 'worker_v250_repair_intervention_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);
    $db->pdo()->exec("CREATE TABLE admin_users(
        id BIGINT UNSIGNED PRIMARY KEY
    ) ENGINE=InnoDB");
    $db->pdo()->exec("INSERT INTO admin_users(id) VALUES(77)");
    $db->pdo()->exec("CREATE TABLE orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_no VARCHAR(80) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,version INT NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE after_sales(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,handled_by BIGINT UNSIGNED NULL,resolution VARCHAR(2000) NULL,
        opened_at DATETIME NOT NULL,closed_at DATETIME NULL,updated_at DATETIME NOT NULL,
        CONSTRAINT fk_repair_handler FOREIGN KEY (handled_by) REFERENCES admin_users(id)
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_worker_contact_reveal_requests(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,context_type VARCHAR(24) NOT NULL,
        after_sale_id BIGINT UNSIGNED NULL,status VARCHAR(24) NOT NULL,review_note VARCHAR(500) NULL,reviewed_at DATETIME NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $db->pdo()->exec("CREATE TABLE order_events(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(80) NOT NULL,
        event_key VARCHAR(160) NOT NULL UNIQUE,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    $seed = static function (Database $db, int $id, string $afterSaleNo, string $openedExpr, ?string $resolution = null): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,user_id,version,updated_at) VALUES(:id,:no,101,1,UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-RI-'.$id]
        );
        $db->execute(
            "INSERT INTO after_sales(public_no,order_id,type,status,handled_by,resolution,opened_at,closed_at,updated_at)
             VALUES(:public_no,:order_id,'warranty_repair','processing',NULL,:resolution,".$openedExpr.",NULL,UTC_TIMESTAMP())",
            ['public_no'=>$afterSaleNo,'order_id'=>$id,'resolution'=>$resolution]
        );
    };

    $repo = new DatabaseOrderRepairInterventionRepository($db);
    $adminRepo = new DatabaseAdminRepairResolutionRepository($db);

    $seed($db, 1, 'AS-FRESH', 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)');
    try {
        $repo->request(['user_id'=>101], 'OD-RI-1', 'AS-FRESH', '希望平台提前处理');
        repairMysqlSame(true, false, '48小时前直接介入必须失败');
    } catch (OrderOperationException $exception) {
        repairMysqlSame($exception->apiCode(), 'REPAIR_INTERVENTION_WAIT_REQUIRED', '48小时前返回稳定等待错误码');
        repairMysqlSame((int)($exception->data()['wait_hours'] ?? 0), 48, '等待错误返回48小时规则');
        repairMysqlSame((string)$db->fetchValue("SELECT resolution FROM after_sales WHERE public_no='AS-FRESH'"), '', '等待期不改写维修处理结果');
        repairMysqlSame(count(OrderNotificationService::$admin), 0, '等待期不生成管理员待办');
    }

    OrderNotificationService::$queued = [];
    OrderNotificationService::$admin = [];
    $seed($db, 2, 'AS-TIMEOUT', 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 49 HOUR)');
    $timeout = $repo->request(['user_id'=>101], 'OD-RI-2', 'AS-TIMEOUT', '超过两天仍未处理');
    repairMysqlSame((string)$timeout['intervention_reason'], 'response_timeout', '超过48小时按超时原因介入');
    repairMysqlSame((string)$db->fetchValue("SELECT status FROM after_sales WHERE public_no='AS-TIMEOUT'"), 'processing', '介入后维修保持处理中');
    repairMysqlSame(strpos((string)$db->fetchValue("SELECT resolution FROM after_sales WHERE public_no='AS-TIMEOUT'"), '用户申请平台介入')===0, true, '介入结果写入售后记录');
    repairMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=2'), 2, '平台介入推进订单版本');
    repairMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=2 AND event_type='repair_platform_intervention_requested'"), 1, '平台介入写入唯一订单事件');
    repairMysqlSame(count(OrderNotificationService::$queued), 1, '平台介入生成一条订单通知事件');
    repairMysqlSame((OrderNotificationService::$queued[0][3]['notify_recipients'] ?? []), ['worker'], '订单事件只提醒师傅');
    repairMysqlSame(count(OrderNotificationService::$admin), 1, '平台介入只生成一条管理员待办');

    try {
        $repo->request(['user_id'=>101], 'OD-RI-2', 'AS-TIMEOUT', '再次提交');
        repairMysqlSame(true, false, '已介入不得重复提交');
    } catch (OrderOperationException $exception) {
        repairMysqlSame($exception->apiCode(), 'REPAIR_INTERVENTION_ALREADY_REQUESTED', '重复介入返回稳定错误码');
    }

    // 小程序管理员先要求继续维修：售后保持 processing，mini users.id 只进 typed event，不写 handled_by。
    OrderNotificationService::$queued = [];
    $continued = $adminRepo->resolve(
        ['channel'=>'mini_admin','user_id'=>901],
        'OD-RI-2','AS-TIMEOUT','continue','根据双方证据，要求师傅继续完成本次保修维修'
    );
    repairMysqlSame((string)$continued['status'],'processing','平台要求继续维修不提前结案');
    repairMysqlSame(strpos((string)$db->fetchValue("SELECT resolution FROM after_sales WHERE public_no='AS-TIMEOUT'"),'平台介入：要求继续维修')===0,true,'继续维修写入独立平台处理记录');
    repairMysqlSame((int)$db->fetchValue("SELECT handled_by IS NULL FROM after_sales WHERE public_no='AS-TIMEOUT'"),1,'mini users.id 不写 after_sales.handled_by');
    repairMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=2'),3,'平台处理继续推进订单版本');
    $continueEvent = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=2 AND event_type='repair_platform_continue' ORDER BY id DESC LIMIT 1");
    repairMysqlSame((string)$continueEvent['actor_type'],'mini_admin','继续维修事件记录 mini_admin 类型');
    repairMysqlSame((int)$continueEvent['actor_id'],901,'继续维修事件记录真实 mini users.id');
    $continuePayload = json_decode((string)$continueEvent['payload_json'], true) ?: [];
    repairMysqlSame((int)($continuePayload['operator_user_id'] ?? 0),901,'mini 管理员 ID 写入 operator_user_id');
    repairMysqlSame($continuePayload['operator_admin_id'] ?? null,null,'mini 管理员不得伪写 operator_admin_id');
    repairMysqlSame(count(OrderNotificationService::$queued),1,'平台继续维修只生成一条双方通知事件');

    $afterSale2Id = (int)$db->fetchValue("SELECT id FROM after_sales WHERE public_no='AS-TIMEOUT'");
    $db->execute(
        "INSERT INTO order_worker_contact_reveal_requests(public_no,order_id,user_id,worker_id,context_type,after_sale_id,status,updated_at)
         VALUES('CR-REPAIR-CI',2,101,55,'repair',:after_sale_id,'pending',UTC_TIMESTAMP())",
        ['after_sale_id'=>$afterSale2Id]
    );

    // 随后小程序管理员确认已解决：结案、关闭待处理电话公开申请，仍不写 handled_by。
    OrderNotificationService::$queued = [];
    $resolved = $adminRepo->resolve(
        ['channel'=>'mini_admin','user_id'=>902],
        'OD-RI-2','AS-TIMEOUT','resolved','用户确认维修结果符合约定，本次售后问题已解决'
    );
    repairMysqlSame((string)$resolved['status'],'resolved','平台可确认售后问题已解决');
    repairMysqlSame((string)$db->fetchValue("SELECT status FROM after_sales WHERE public_no='AS-TIMEOUT'"),'resolved','售后主记录进入 resolved');
    repairMysqlSame((int)$db->fetchValue("SELECT closed_at IS NOT NULL FROM after_sales WHERE public_no='AS-TIMEOUT'"),1,'平台最终裁定写入关闭时间');
    repairMysqlSame((int)$db->fetchValue("SELECT handled_by IS NULL FROM after_sales WHERE public_no='AS-TIMEOUT'"),1,'mini 最终裁定仍不混写 admin_users ID 域');
    repairMysqlSame((string)$db->fetchValue("SELECT status FROM order_worker_contact_reveal_requests WHERE public_no='CR-REPAIR-CI'"),'rejected','售后结案自动关闭维修电话公开申请');
    repairMysqlSame((string)$db->fetchValue("SELECT review_note FROM order_worker_contact_reveal_requests WHERE public_no='CR-REPAIR-CI'"),'售后已由平台结案','电话公开申请保留明确关闭原因');
    repairMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=2'),4,'平台最终裁定推进订单版本');
    $resolvedEvent = $db->fetch("SELECT actor_type,actor_id FROM order_events WHERE order_id=2 AND event_type='repair_platform_resolved' ORDER BY id DESC LIMIT 1");
    repairMysqlSame((string)$resolvedEvent['actor_type'],'mini_admin','最终裁定事件记录 mini_admin 类型');
    repairMysqlSame((int)$resolvedEvent['actor_id'],902,'最终裁定事件记录真实 mini users.id');
    repairMysqlSame(count(OrderNotificationService::$queued),1,'平台最终裁定只生成一条双方通知事件');

    $duplicateResolution = false;
    try {
        $adminRepo->resolve(['channel'=>'mini_admin','user_id'=>902],'OD-RI-2','AS-TIMEOUT','closed','重复关闭售后');
    } catch (OrderOperationException $exception) {
        $duplicateResolution = $exception->apiCode() === 'REPAIR_PLATFORM_REVIEW_CHANGED';
    }
    repairMysqlSame($duplicateResolution,true,'售后结案后重复平台裁定被状态阻断');

    OrderNotificationService::$queued = [];
    OrderNotificationService::$admin = [];
    $seed($db, 3, 'AS-REJECTED', 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)', '师傅拒绝维修：不属于保障范围');
    $rejected = $repo->request(['user_id'=>101], 'OD-RI-3', 'AS-REJECTED', '请平台核实责任');
    repairMysqlSame((string)$rejected['intervention_reason'], 'worker_rejected', '师傅拒绝后可立即介入');
    repairMysqlSame(count(OrderNotificationService::$admin), 1, '师傅拒绝后的介入仍只产生一条管理员待办');
    $closed = $adminRepo->resolve(
        ['channel'=>'mini_admin','user_id'=>903],
        'OD-RI-3','AS-REJECTED','closed','核对证据后确认不属于本次保修范围，关闭该售后'
    );
    repairMysqlSame((string)$closed['status'],'closed','平台可在介入后关闭不成立的售后');

    // Web 后台管理员复用同一 Repository；admin_users.id 可合法写 handled_by，typed event 使用 admin actor_type。
    OrderNotificationService::$queued = [];
    OrderNotificationService::$admin = [];
    $seed($db, 5, 'AS-WEB', 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 49 HOUR)');
    $webEscalated = $repo->request(['user_id'=>101], 'OD-RI-5', 'AS-WEB', '双方自行沟通后仍无法解决，请平台介入');
    repairMysqlSame((string)$webEscalated['status'],'processing','Web 裁定前仍先经过用户平台介入流程');
    $webResolved = $adminRepo->resolve(
        ['channel'=>'web_admin','user_id'=>77],
        'OD-RI-5','AS-WEB','resolved','Web 后台核对双方材料后确认维修问题已经解决'
    );
    repairMysqlSame((string)$webResolved['status'],'resolved','Web 管理员可通过同一事务确认售后解决');
    repairMysqlSame((int)$db->fetchValue("SELECT handled_by FROM after_sales WHERE public_no='AS-WEB'"),77,'Web admin_users.id 写入 after_sales.handled_by');
    repairMysqlSame((int)$db->fetchValue("SELECT closed_at IS NOT NULL FROM after_sales WHERE public_no='AS-WEB'"),1,'Web 最终裁定正常写入关闭时间');
    $webEvent = $db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=5 AND event_type='repair_platform_resolved' ORDER BY id DESC LIMIT 1");
    repairMysqlSame((string)$webEvent['actor_type'],'admin','Web 裁定事件使用 admin actor_type');
    repairMysqlSame((int)$webEvent['actor_id'],77,'Web 裁定事件记录真实 admin_users.id');
    $webPayload = json_decode((string)$webEvent['payload_json'], true) ?: [];
    repairMysqlSame((string)($webPayload['operator_channel'] ?? ''),'web_admin','Web 裁定 payload 保留显式通道');
    repairMysqlSame((int)($webPayload['operator_admin_id'] ?? 0),77,'Web 裁定 payload 写入 operator_admin_id');
    repairMysqlSame($webPayload['operator_user_id'] ?? null,null,'Web 管理员不得伪写 mini users.id');
    repairMysqlSame(count(OrderNotificationService::$queued),2,'Web 场景包含介入通知与最终裁定双方通知各一条');

    // 没有用户申请平台介入的维修，管理员不能越级直接裁定。
    $seed($db, 4, 'AS-NOT-ESCALATED', 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 HOUR)');
    $notEscalated = false;
    try {
        $adminRepo->resolve(
            ['channel'=>'mini_admin','user_id'=>904],
            'OD-RI-4','AS-NOT-ESCALATED','closed','虽然已超时但用户尚未申请平台介入'
        );
    } catch (OrderOperationException $exception) {
        $notEscalated = $exception->apiCode() === 'REPAIR_PLATFORM_INTERVENTION_REQUIRED';
    }
    repairMysqlSame($notEscalated,true,'平台必须等用户明确申请介入后才能裁定维修售后');
    repairMysqlSame((string)$db->fetchValue("SELECT status FROM after_sales WHERE public_no='AS-NOT-ESCALATED'"),'processing','越级裁定失败不改变售后状态');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 repair intervention MySQL 5.7 integration: OK\n";
}
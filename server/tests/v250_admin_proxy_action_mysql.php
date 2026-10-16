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
        public static function queuePersistedEvent(Database $db, int $orderId, int $eventId, string $eventType, array $payload = []): void
        {
            self::$queued[] = [$orderId,$eventId,$eventType,$payload];
        }
    }
}

namespace {
    use WorkerAdmin\Application\Admin\AdminOrderProxyAction;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderProxyActionRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/AdminOrderProxyActionRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseAdminOrderProxyActionRepository.php';
    require_once $root.'/server/app/Application/Admin/AdminOrderProxyAction.php';

    function adminProxyMysqlSame($actual, $expected, string $label): void
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
    adminProxyMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName = 'worker_v250_admin_proxy_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database'] = $dbName;
    $db = new Database($config);

    $ddl = [
        "CREATE TABLE orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            current_assignment_id BIGINT UNSIGNED NULL,
            assigned_worker_id BIGINT UNSIGNED NULL,
            assignment_status VARCHAR(24) NOT NULL DEFAULT 'none',
            publish_status VARCHAR(24) NOT NULL DEFAULT 'published',
            contact_revealed_at DATETIME NULL,
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(64) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            commission_rule_version_id BIGINT UNSIGNED NULL,
            status VARCHAR(24) NOT NULL,
            accepted_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE commission_rule_versions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            locked_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignment_history(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assignment_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            actor_type VARCHAR(24) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_key VARCHAR(160) NOT NULL UNIQUE,
            actor_type VARCHAR(24) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach ($ddl as $sql) $db->pdo()->exec($sql);

    $db->execute("INSERT INTO commission_rule_versions(id,locked_at) VALUES(7,NULL)");
    $db->execute("INSERT INTO orders(id,public_no,current_assignment_id,assignment_status,publish_status,version,updated_at)
                  VALUES(1,'OD-PROXY-MYSQL',10,'offered','published',3,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,commission_rule_version_id,status,updated_at)
                  VALUES(10,'ASG-PROXY-MYSQL',1,55,7,'offered',UTC_TIMESTAMP())");

    $repo = new DatabaseAdminOrderProxyActionRepository($db);
    $app = new AdminOrderProxyAction($repo);

    $blocked = false;
    try {
        $app->execute(
            ['channel'=>'mini_admin','user_id'=>901],
            ['order_id'=>'OD-PROXY-MYSQL','action'=>'assignment_accept','note'=>'旧版管理员代确认']
        );
    } catch (OrderOperationException $exception) {
        $blocked = $exception->apiCode() === 'ADMIN_ASSIGNMENT_PROXY_REMOVED';
    }

    adminProxyMysqlSame($blocked,true,'Application 层禁止管理员代师傅确认接单');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT status FROM assignments WHERE id=10'),'offered','拒绝后指派保持 offered');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT assignment_status FROM orders WHERE id=1'),'offered','拒绝后订单指派状态不变');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),3,'拒绝后订单版本不变化');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT locked_at IS NOT NULL FROM commission_rule_versions WHERE id=7'),0,'拒绝后不锁定佣金规则');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM assignment_history'),0,'拒绝后不制造指派历史');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_events'),0,'拒绝后不制造订单事件');
    adminProxyMysqlSame(count(OrderNotificationService::$queued),0,'拒绝后不排队领域通知');

    $repairBlocked = false;
    try {
        $app->execute(
            ['channel'=>'mini_admin','user_id'=>901],
            ['order_id'=>'OD-PROXY-MYSQL','action'=>'repair_complete','after_sale_id'=>'AS-1']
        );
    } catch (OrderOperationException $exception) {
        $repairBlocked = $exception->apiCode() === 'ADMIN_REPAIR_PROXY_REMOVED';
    }
    adminProxyMysqlSame($repairBlocked,true,'Application 层禁止管理员代师傅完成维修');

    $directBlocked = false;
    try { $repo->acceptAssignment(['channel'=>'mini_admin','user_id'=>901],'OD-PROXY-MYSQL','直接调用旧端口'); }
    catch (OrderOperationException $exception) { $directBlocked = $exception->apiCode()==='ADMIN_ASSIGNMENT_PROXY_REMOVED'; }
    adminProxyMysqlSame($directBlocked,true,'直接调用旧 Repository 也无法代确认接单');

    foreach ([
        '/Contracts/AdminOrderAssistanceRepository.php','/Contracts/OrderAppointmentRepository.php',
        '/Application/Admin/AdminOrderAssistance.php','/Application/Order/OrderAppointment.php',
        '/Infrastructure/Persistence/OrderFulfillmentWriteGuard.php',
        '/Infrastructure/Persistence/DatabaseOrderAppointmentRepository.php',
        '/Infrastructure/Persistence/DatabaseAdminOrderAssistanceRepository.php',
    ] as $file) require_once $root.'/server/app'.$file;
    $db->execute("ALTER TABLE orders ADD lifecycle_status VARCHAR(24) NOT NULL DEFAULT 'active',
        ADD fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'not_started', ADD workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',
        ADD order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer', ADD refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
        ADD dispute_status VARCHAR(24) NOT NULL DEFAULT 'none'");
    $db->execute('ALTER TABLE order_events MODIFY event_key VARCHAR(160) NULL');
    foreach ([
        "CREATE TABLE appointment_records(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,worker_id BIGINT,appointment_at DATETIME NULL,note VARCHAR(500),status VARCHAR(24),context_type VARCHAR(24),after_sale_id BIGINT NULL,wait_customer_notice TINYINT,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE after_sales(id BIGINT PRIMARY KEY,order_id BIGINT,public_no VARCHAR(80),type VARCHAR(40),status VARCHAR(24)) ENGINE=InnoDB",
        "CREATE TABLE order_worker_contact_reveal_requests(id BIGINT PRIMARY KEY,order_id BIGINT,worker_id BIGINT,context_type VARCHAR(24),after_sale_id BIGINT NULL,status VARCHAR(24),review_note VARCHAR(500),reviewed_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(24),closed_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(24)) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(24)) ENGINE=InnoDB",
    ] as $sql) $db->execute($sql);
    $db->execute("UPDATE assignments SET status='accepted' WHERE id=10");
    $db->execute("UPDATE orders SET assigned_worker_id=55,assignment_status='accepted' WHERE id=1");
    $appointments = new \WorkerAdmin\Application\Order\OrderAppointment(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderAppointmentRepository($db));
    $assistance = new \WorkerAdmin\Application\Admin\AdminOrderAssistance(new \WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderAssistanceRepository($db,$appointments));
    $operator = ['channel'=>'mini_admin','user_id'=>901];
    $input = ['order_id'=>'OD-PROXY-MYSQL','assignment_id'=>'ASG-PROXY-MYSQL','request_id'=>'mysql_assist_00000001',
        'action'=>'appointment','note'=>'微信沟通约定上门','occurred_at'=>'2026-01-01 10:00:00','appointment_at'=>'2026-01-03 09:00:00'];
    $context = $assistance->execute('admin.order.assistance.context',$operator,['order_id'=>'OD-PROXY-MYSQL']);
    adminProxyMysqlSame($context['assignment_id'],'ASG-PROXY-MYSQL','补录上下文使用当前 accepted 指派');
    $assistance->execute('admin.order.assistance.save',$operator,$input);
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM appointment_records'),1,'协助预约复用原预约表');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT appointment_at FROM appointment_records LIMIT 1'),'2026-01-03 01:00:00','上门时间独立按北京时间转换');
    adminProxyMysqlSame((string)$db->fetchValue("SELECT actor_type FROM order_events WHERE event_type='appointment_recorded'"),'mini_admin','预约领域事件记录真实管理员类型');
    adminProxyMysqlSame((int)$db->fetchValue("SELECT actor_id FROM order_events WHERE event_type='appointment_recorded'"),901,'预约领域事件记录真实管理员 users.id');
    $payload = json_decode((string)$db->fetchValue("SELECT payload_json FROM order_events WHERE event_type='admin_assistance_recorded'"),true);
    adminProxyMysqlSame($payload['occurred_at'],'2026-01-01 02:00:00','实际沟通时间未覆盖系统录入时间');
    adminProxyMysqlSame($payload['worker_id'],55,'经办记录保留对应师傅');
    adminProxyMysqlSame(count(OrderNotificationService::$queued),1,'协助预约只生成一次原领域通知');
    $version = (int)$db->fetchValue('SELECT version FROM orders WHERE id=1');
    $again = $assistance->execute('admin.order.assistance.save',$operator,$input);
    adminProxyMysqlSame($again['replayed'],true,'同请求安全重放');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM appointment_records'),1,'重放不重复预约');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),$version,'重放不重复改版本或启动业务计时');
    adminProxyMysqlSame(count(OrderNotificationService::$queued),1,'重放不重复通知');
    $conflict = false;
    try { $assistance->execute('admin.order.assistance.save',$operator,array_replace($input,['note'=>'同请求修改内容'])); }
    catch (OrderOperationException $exception) { $conflict = $exception->apiCode()==='ASSISTANCE_REQUEST_CONFLICT'; }
    adminProxyMysqlSame($conflict,true,'同幂等键不同内容拒绝覆盖');
    $db->execute("INSERT INTO assignments(id,public_id,order_id,worker_id,status,updated_at) VALUES(11,'ASG-NEW',1,55,'accepted',UTC_TIMESTAMP())");
    $db->execute('UPDATE orders SET current_assignment_id=11 WHERE id=1');
    $stale = false;
    try { $assistance->execute('admin.order.assistance.save',$operator,array_replace($input,['request_id'=>'mysql_assist_00000002'])); }
    catch (OrderOperationException $exception) { $stale = $exception->apiCode()==='ASSIGNMENT_CHANGED'; }
    adminProxyMysqlSame($stale,true,'即使改派后仍是同一师傅，旧指派也不能提交');
    $db->execute('UPDATE orders SET current_assignment_id=10 WHERE id=1');
    $db->execute("INSERT INTO refunds(id,order_id,status) VALUES(1,1,'processing')");
    $blockedByRefund = false;
    try { $assistance->execute('admin.order.assistance.save',$operator,array_replace($input,['request_id'=>'mysql_assist_00000003'])); }
    catch (OrderOperationException $exception) { $blockedByRefund = $exception->apiCode()==='ORDER_REFUND_PENDING'; }
    adminProxyMysqlSame($blockedByRefund,true,'协助预约不能绕过原退款阻断');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM appointment_records'),1,'业务失败整笔回滚，不留下预约');
    adminProxyMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_type='admin_assistance_recorded'"),1,'业务失败不留下成功补录');
    $db->execute('DELETE FROM refunds');
    $db->execute("UPDATE orders SET lifecycle_status='completed',fulfillment_status='accepted' WHERE id=1");
    $db->execute("INSERT INTO after_sales(id,order_id,public_no,type,status) VALUES(1,1,'REPAIR-CI','warranty_repair','processing')");
    $repairInput = array_replace($input,['request_id'=>'mysql_assist_00000004','context_type'=>'repair','after_sale_id'=>'REPAIR-CI','wait_customer_notice'=>true,'appointment_at'=>'']);
    $assistance->execute('admin.order.assistance.save',$operator,$repairInput);
    adminProxyMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE context_type='repair' AND after_sale_id=1 AND wait_customer_notice=1"),1,'已完成订单可补录当前维修的等待通知');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'completed','维修预约不倒退主订单终态');
    $beforeContact = count(OrderNotificationService::$queued);
    $assistance->execute('admin.order.assistance.save',$operator,array_replace($input,['request_id'=>'mysql_assist_00000005','action'=>'contact_note']));
    adminProxyMysqlSame(count(OrderNotificationService::$queued),$beforeContact,'仅联系说明不发送重复通知');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM appointment_records'),2,'联系记录不伪造预约');
    $records = $assistance->execute('admin.order.assistance.context',$operator,['order_id'=>'OD-PROXY-MYSQL']);
    adminProxyMysqlSame(count($records['records']),3,'管理员可回看经办记录');
    adminProxyMysqlSame($records['can_record_service_appointment'],false,'已验收订单不提供施工预约能力');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 admin proxy action MySQL 5.7 integration: OK\n";
}

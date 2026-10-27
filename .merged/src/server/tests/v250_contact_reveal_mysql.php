<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;
    final class OrderNotificationService
    {
        public static array $adminTasks = [];
        public static array $queued = [];
        public static function queue(Database $db, int $orderId, string $eventKey, string $eventType, array $payload = []): void
        {
            self::$queued[] = [$orderId,$eventKey,$eventType,$payload];
        }
        public static function queueAdminTask(Database $db, string $businessKey, string $eventType, string $title, array $payload = []): void
        {
            self::$adminTasks[] = [$businessKey,$eventType,$title,$payload];
        }
    }
}

namespace {
    use WorkerAdmin\Application\Order\ContactRevealReview;
    use WorkerAdmin\Contracts\OrderNotificationPort;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderContactRevealRepository;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderContactRevealRequestRepository;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/OrderContactRevealRequestRepository.php';
    require_once $root.'/server/app/Contracts/OrderContactRevealRepository.php';
    require_once $root.'/server/app/Contracts/OrderNotificationPort.php';
    require_once $root.'/server/app/Contracts/TransactionManager.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Domain/Order/ContactRevealReviewPolicy.php';
    require_once $root.'/server/app/Application/Order/ContactRevealReview.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseTransactionManager.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderContactRevealRepository.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderContactRevealRequestRepository.php';

    if (!function_exists('public_id')) {
        function public_id(): string { static $i=0; return 'CRTEST'.str_pad((string)++$i,20,'0',STR_PAD_LEFT); }
    }
    if (!function_exists('setting')) {
        function setting($key, $default=null) { return $default; }
    }
    function contactMysqlSame($actual, $expected, string $label): void
    {
        if ($actual !== $expected) {
            fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    final class ContactReviewNotificationSpy implements OrderNotificationPort
    {
        public array $queued = [];
        public function queue(int $orderId, string $idempotencyKey, string $eventType, array $payload = []): void
        {
            $this->queued[] = [$orderId,$idempotencyKey,$eventType,$payload];
        }
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
    contactMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v250_contact_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl=[
        "CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
        "CREATE TABLE orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,
            order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',assigned_worker_id BIGINT UNSIGNED NULL,current_assignment_id BIGINT UNSIGNED NULL,
            lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'active',fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'not_started',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,
            accepted_at DATETIME NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE after_sales(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,type VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL,opened_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE appointment_records(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,
            context_type VARCHAR(24) NOT NULL,after_sale_id BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_worker_contact_reveal_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(64) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,context_type VARCHAR(24) NOT NULL,after_sale_id BIGINT UNSIGNED NULL,reason VARCHAR(500) NULL,
            eligible_at DATETIME NULL,status VARCHAR(24) NOT NULL,requested_at DATETIME NOT NULL,reviewed_by BIGINT UNSIGNED NULL,review_note VARCHAR(500) NULL,
            reviewed_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
            CONSTRAINT fk_contact_review_admin FOREIGN KEY(reviewed_by) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,closed_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,
            actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach($ddl as $sql)$db->pdo()->exec($sql);
    $db->pdo()->exec('INSERT INTO admin_users(id) VALUES(77)');

    $seed = static function(Database $db,int $id,string $source='consumer',string $refund='none',string $dispute='none'): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,user_id,order_source_type,assigned_worker_id,current_assignment_id,lifecycle_status,fulfillment_status,refund_status,dispute_status,created_at,updated_at)
             VALUES(:id,:no,101,:source,201,:assignment,'active','not_started',:refund,:dispute,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-CONTACT-'.$id,'source'=>$source,'assignment'=>$id,'refund'=>$refund,'dispute'=>$dispute]
        );
        $db->execute(
            "INSERT INTO assignments(id,order_id,worker_id,status,accepted_at,created_at)
             VALUES(:id,:order_id,201,'accepted',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 HOUR),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 HOUR))",
            ['id'=>$id,'order_id'=>$id]
        );
    };

    $repo=new DatabaseOrderContactRevealRequestRepository($db);

    $seed($db,1);
    $normal=$repo->request(['user_id'=>101],'OD-CONTACT-1','师傅一直没有联系');
    contactMysqlSame((bool)$normal['auto_approved'],true,'超过等待期且无响应自动公开电话');
    contactMysqlSame((string)$db->fetchValue('SELECT status FROM order_worker_contact_reveal_requests WHERE order_id=1'),'approved','正常申请直接写 approved');
    contactMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_type='worker_contact_reveal_approved'"),1,'自动公开保留订单审计事件');
    contactMysqlSame(count(OrderNotificationService::$adminTasks),0,'正常公开不生成管理员待办');

    $seed($db,2);
    $db->execute("INSERT INTO appointment_records(order_id,worker_id,context_type,after_sale_id) VALUES(2,201,'service',NULL)");
    $responded=false;
    try{$repo->request(['user_id'=>101],'OD-CONTACT-2','申请查看电话');}
    catch(OrderOperationException $e){$responded=$e->apiCode()==='WORKER_ALREADY_RESPONDED';}
    contactMysqlSame($responded,true,'师傅已经预约响应后禁止公开电话');

    $seed($db,3,'consumer','none','negotiating');
    $risk=$repo->request(['user_id'=>101],'OD-CONTACT-3','争议中仍需联系');
    contactMysqlSame((bool)$risk['auto_approved'],false,'争议中电话公开转人工');
    contactMysqlSame((string)$db->fetchValue('SELECT status FROM order_worker_contact_reveal_requests WHERE order_id=3'),'pending','异常订单只写 pending');
    contactMysqlSame(count(OrderNotificationService::$adminTasks),1,'异常订单才生成管理员待办');

    $requestNo=(string)$risk['id'];
    $reviewNotifications=new ContactReviewNotificationSpy();
    $review=new ContactRevealReview(
        new DatabaseTransactionManager($db),
        new DatabaseOrderContactRevealRepository($db),
        $reviewNotifications
    );
    $reviewed=$review->reviewByPublicNo(
        $requestNo,
        'approve',
        'Web 后台核实当前争议不影响履约联系，同意本次场景公开',
        ['channel'=>'web_admin','admin_user_id'=>77]
    );
    contactMysqlSame((string)$reviewed['status'],'approved','Web 管理员可通过统一审批用例处理异常电话公开');
    contactMysqlSame((int)$db->fetchValue('SELECT reviewed_by FROM order_worker_contact_reveal_requests WHERE order_id=3'),77,'Web admin_users.id 写入 reviewed_by 外键');
    $reviewEvent=$db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=3 AND event_type='worker_contact_reveal_approved' ORDER BY id DESC LIMIT 1");
    contactMysqlSame((string)$reviewEvent['actor_type'],'web_admin','Web 审批事件保留 web_admin actor 类型');
    contactMysqlSame((int)$reviewEvent['actor_id'],77,'Web 审批事件记录真实 admin_users.id');
    $reviewPayload=json_decode((string)$reviewEvent['payload_json'],true) ?: [];
    contactMysqlSame((int)($reviewPayload['reviewer_admin_user_id'] ?? 0),77,'Web 审批 payload 保留 reviewer_admin_user_id');
    contactMysqlSame((string)($reviewPayload['reviewer_channel'] ?? ''),'web_admin','Web 审批 payload 保留通道');
    contactMysqlSame(count($reviewNotifications->queued),1,'Web 审批只生成一条结果通知');

    $seed($db,4,'merchant');
    $merchant=false;
    try{$repo->request(['user_id'=>101],'OD-CONTACT-4','申请查看电话');}
    catch(OrderOperationException $e){$merchant=$e->apiCode()==='MERCHANT_CONTACT_PROTECTED';}
    contactMysqlSame($merchant,true,'商家订单继续禁止直接公开师傅电话');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 contact reveal MySQL 5.7 integration: OK\n";
}

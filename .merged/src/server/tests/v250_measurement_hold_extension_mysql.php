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
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderMeasurementHoldExtensionRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root = dirname(__DIR__, 2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/SettingsProvider.php';
    require_once $root.'/server/app/Contracts/OrderMeasurementHoldExtensionRepository.php';
    require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseOrderMeasurementHoldExtensionRepository.php';

    if (!function_exists('public_id')) {
        function public_id(): string { static $i=0; return 'MH'.str_pad((string)++$i,24,'0',STR_PAD_LEFT); }
    }

    final class MeasurementHoldSettings implements SettingsProvider
    {
        public function get(string $key, $default = null) { return $key === 'measurement.auto_hold_extension_days' ? 5 : $default; }
        public function bool(string $key, bool $default = false): bool { return (bool)$this->get($key,$default); }
        public function int(string $key, int $default = 0): int { return (int)$this->get($key,$default); }
    }

    function holdMysqlSame($actual, $expected, string $label): void
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
    holdMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v250_measurement_hold_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl=[
        "CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
        "CREATE TABLE orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,
            workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',lifecycle_status VARCHAR(48) NOT NULL DEFAULT 'active',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_measurement_states(
            order_id BIGINT UNSIGNED PRIMARY KEY,settlement_status VARCHAR(24) NOT NULL,hold_until DATETIME NULL,max_hold_until DATETIME NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_measurement_hold_extensions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id CHAR(26) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,requested_by_user_id BIGINT UNSIGNED NOT NULL,
            old_hold_until DATETIME NOT NULL,requested_hold_until DATETIME NOT NULL,approved_hold_until DATETIME NULL,reason VARCHAR(500) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',reviewed_by BIGINT UNSIGNED NULL,review_note VARCHAR(500) NULL,reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,
            CONSTRAINT fk_hold_review_admin FOREIGN KEY(reviewed_by) REFERENCES admin_users(id)
        ) ENGINE=InnoDB",
        "CREATE TABLE order_relations(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,parent_order_id BIGINT UNSIGNED NOT NULL,child_order_id BIGINT UNSIGNED NOT NULL,
            relation_type VARCHAR(48) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'active'
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

    $seed = static function(Database $db,int $id,string $refund='none',string $dispute='none',int $holdDays=10,int $maxDays=30): void {
        $db->execute(
            "INSERT INTO orders(id,public_no,user_id,workflow_profile,lifecycle_status,refund_status,dispute_status,created_at,updated_at)
             VALUES(:id,:no,101,'measurement','active',:refund,:dispute,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'CL-HOLD-'.$id,'refund'=>$refund,'dispute'=>$dispute]
        );
        $db->execute(
            "INSERT INTO order_measurement_states(order_id,settlement_status,hold_until,max_hold_until,created_at,updated_at)
             VALUES(:id,'frozen',DATE_ADD(UTC_TIMESTAMP(),INTERVAL :hold_days DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL :max_days DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$id,'hold_days'=>$holdDays,'max_days'=>$maxDays]
        );
    };

    $repo=new DatabaseOrderMeasurementHoldExtensionRepository($db,new MeasurementHoldSettings());

    $seed($db,1);
    $before=(string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=1');
    $first=$repo->request(['user_id'=>101],'CL-HOLD-1',5,'材料方案还没有最终确定');
    holdMysqlSame((bool)$first['auto_approved'],true,'首次5天延期自动批准');
    holdMysqlSame((string)$db->fetchValue('SELECT status FROM order_measurement_hold_extensions WHERE order_id=1 ORDER BY id'),'approved','首次延期直接写 approved');
    $after=(string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=1');
    holdMysqlSame((int)((strtotime($after.' UTC')-strtotime($before.' UTC'))/86400),5,'自动批准真实延长冻结期5天');
    holdMysqlSame(count(OrderNotificationService::$adminTasks),0,'首次小范围延期不生成管理员待办');
    holdMysqlSame(count(OrderNotificationService::$queued),1,'自动延期通知用户和师傅');

    $second=$repo->request(['user_id'=>101],'CL-HOLD-1',5,'仍需继续保留测量优惠资格');
    holdMysqlSame((bool)$second['auto_approved'],false,'第二次延期转人工审核');
    holdMysqlSame((string)$db->fetchValue("SELECT status FROM order_measurement_hold_extensions WHERE order_id=1 ORDER BY id DESC LIMIT 1"),'pending','第二次延期只写 pending');
    holdMysqlSame(count(OrderNotificationService::$adminTasks),1,'第二次延期生成管理员待办');

    $seed($db,2,'none','negotiating');
    $risk=$repo->request(['user_id'=>101],'CL-HOLD-2',5,'争议尚未解决，需要保留时间');
    holdMysqlSame((bool)$risk['auto_approved'],false,'争议中的首次延期也必须人工审核');
    holdMysqlSame((string)$db->fetchValue('SELECT status FROM order_measurement_hold_extensions WHERE order_id=2'),'pending','争议延期保持 pending');

    $seed($db,3);
    $large=$repo->request(['user_id'=>101],'CL-HOLD-3',10,'需要更长时间决定后续施工');
    holdMysqlSame((bool)$large['auto_approved'],false,'超过5天自动范围的首次申请也转人工');

    $seed($db,4);
    $db->execute("INSERT INTO order_relations(parent_order_id,child_order_id,relation_type,status) VALUES(4,99,'measurement_construction','active')");
    $linked=false;
    try{$repo->request(['user_id'=>101],'CL-HOLD-4',5,'已经下施工单但仍申请延期');}
    catch(OrderOperationException $e){$linked=$e->apiCode()==='MEASUREMENT_ALREADY_LINKED';}
    holdMysqlSame($linked,true,'已经关联施工订单后禁止继续延期');

    $seed($db,5,'none','none',10,12);
    $capped=$repo->request(['user_id'=>101],'CL-HOLD-5',5,'剩余期限不足5天');
    holdMysqlSame((bool)$capped['auto_approved'],true,'首次申请仍可自动批准到原最长冻结上限');
    $state5=(string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=5');
    $max5=(string)$db->fetchValue('SELECT max_hold_until FROM order_measurement_states WHERE order_id=5');
    holdMysqlSame($state5,$max5,'自动延期绝不突破 max_hold_until');

    // 人工批准：小程序 users.id 不写入 reviewed_by，操作者类型只在事件中明确记录。
    $seed($db,6);
    $repo->request(['user_id'=>101],'CL-HOLD-6',5,'第一次先自动延长');
    $pending6=$repo->request(['user_id'=>101],'CL-HOLD-6',5,'第二次需要管理员确认');
    $hold6Before=(string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=6');
    $review6=$repo->review((string)$pending6['id'],'approved','确认仍需保留测量优惠',['channel'=>'mini_admin','user_id'=>909]);
    holdMysqlSame((string)$review6['status'],'approved','管理员可批准 pending 延期');
    holdMysqlSame((string)$db->fetchValue('SELECT status FROM order_measurement_hold_extensions WHERE public_id=:id',['id'=>$pending6['id']]),'approved','批准后申请状态落库');
    holdMysqlSame((int)$db->fetchValue('SELECT reviewed_by IS NULL FROM order_measurement_hold_extensions WHERE public_id=:id',['id'=>$pending6['id']]),1,'mini_admin users.id 不混写 reviewed_by 外键');
    $hold6After=(string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=6');
    holdMysqlSame(strtotime($hold6After.' UTC')>strtotime($hold6Before.' UTC'),true,'人工批准真实延长冻结截止');
    $event6=$db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=6 AND event_type='measurement_hold_extension_approved' ORDER BY id DESC LIMIT 1");
    $payload6=json_decode((string)$event6['payload_json'],true);
    holdMysqlSame((string)$event6['actor_type'],'mini_admin','人工批准事件记录 mini_admin channel');
    holdMysqlSame((int)$event6['actor_id'],909,'人工批准事件记录真实 users.id');
    holdMysqlSame((int)($payload6['operator_user_id']??0),909,'人工批准 payload 保留带类型审核人');
    holdMysqlSame($payload6['operator_admin_id']??null,null,'mini 审核不伪写 admin_users.id');

    $changed=false;
    try{$repo->review((string)$pending6['id'],'approved','重复点击',['channel'=>'mini_admin','user_id'=>909]);}
    catch(OrderOperationException $e){$changed=$e->apiCode()==='MEASUREMENT_EXTENSION_CHANGED';}
    holdMysqlSame($changed,true,'重复审核同一延期被409语义阻断');

    // 人工驳回：冻结时间不变化，mini 审核仍不写 reviewed_by。
    $seed($db,7);
    $pending7=$repo->request(['user_id'=>101],'CL-HOLD-7',10,'申请超出自动范围');
    $hold7Before=(string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=7');
    $review7=$repo->review((string)$pending7['id'],'rejected','当前风险未解除',['channel'=>'mini_admin','user_id'=>910]);
    holdMysqlSame((string)$review7['status'],'rejected','管理员可驳回 pending 延期');
    holdMysqlSame((string)$db->fetchValue('SELECT hold_until FROM order_measurement_states WHERE order_id=7'),$hold7Before,'驳回不改变冻结截止');
    holdMysqlSame((int)$db->fetchValue('SELECT reviewed_by IS NULL FROM order_measurement_hold_extensions WHERE public_id=:id',['id'=>$pending7['id']]),1,'mini 驳回同样不混写 reviewed_by');

    // Web 管理员：reviewed_by 明确属于 admin_users.id，并与事件 actor 域保持一致。
    $seed($db,9);
    $pending9=$repo->request(['user_id'=>101],'CL-HOLD-9',10,'Web 后台核实后再决定');
    $review9=$repo->review((string)$pending9['id'],'approved','后台确认当前仍需延长冻结期',['channel'=>'web_admin','admin_user_id'=>77]);
    holdMysqlSame((string)$review9['status'],'approved','Web 管理员复用统一事务批准延期');
    holdMysqlSame((int)$db->fetchValue('SELECT reviewed_by FROM order_measurement_hold_extensions WHERE public_id=:id',['id'=>$pending9['id']]),77,'Web admin_users.id 写入 reviewed_by 外键');
    $event9=$db->fetch("SELECT actor_type,actor_id,payload_json FROM order_events WHERE order_id=9 AND event_type='measurement_hold_extension_approved' ORDER BY id DESC LIMIT 1");
    $payload9=json_decode((string)$event9['payload_json'],true);
    holdMysqlSame((string)$event9['actor_type'],'web_admin','Web 延期审核事件保留 web_admin channel');
    holdMysqlSame((int)$event9['actor_id'],77,'Web 延期审核事件记录真实 admin_users.id');
    holdMysqlSame((int)($payload9['operator_admin_id']??0),77,'Web 延期审核 payload 写 operator_admin_id');
    holdMysqlSame($payload9['operator_user_id']??null,null,'Web 延期审核不伪写 mini users.id');

    // 页面停留期间冻结到期时，旧申请不能被强行批准。
    $seed($db,8);
    $pending8=$repo->request(['user_id'=>101],'CL-HOLD-8',10,'等待管理员期间可能到期');
    $db->execute("UPDATE order_measurement_states SET hold_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE order_id=8");
    $inactive=false;
    try{$repo->review((string)$pending8['id'],'approved','尝试过期批准',['channel'=>'mini_admin','user_id'=>911]);}
    catch(OrderOperationException $e){$inactive=$e->apiCode()==='MEASUREMENT_HOLD_INACTIVE';}
    holdMysqlSame($inactive,true,'冻结已到期时人工批准被阻断');
    holdMysqlSame((string)$db->fetchValue('SELECT status FROM order_measurement_hold_extensions WHERE public_id=:id',['id'=>$pending8['id']]),'pending','过期审核失败不伪造已处理状态');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 measurement hold extension MySQL 5.7 integration: OK\n";
}

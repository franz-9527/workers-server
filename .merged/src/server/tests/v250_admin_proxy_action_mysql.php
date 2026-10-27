<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;

    final class SchedulerService
    {
        public static int $estimates=0;
        public function __construct(Database $db) {}
        public function createCommissionEstimate(Database $db,int $orderId): void { ++self::$estimates; }
    }

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
    spl_autoload_register(static function(string $class) use($root): void {
        if (strpos($class,'WorkerAdmin\\')!==0) return;
        $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if (is_file($file)) require_once $file;
    });
    function public_id(): string { return 'CI'.bin2hex(random_bytes(15)); }

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
        '/Contracts/AdminOrderAssistanceRepository.php','/Contracts/AdminOrderAssistanceMediaRepository.php','/Contracts/OrderAppointmentRepository.php',
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


    // 同一真实 MySQL 事务夹具扩展到图片、普通/平台测量、完工和维修，经济命令桩一旦调用即失败。
    $db->execute("ALTER TABLE orders ADD user_id BIGINT NOT NULL DEFAULT 77,ADD audit_status VARCHAR(24) NOT NULL DEFAULT 'approved',
        ADD completion_submitted_at DATETIME NULL,ADD auto_accept_at DATETIME NULL,
        ADD accepted_at DATETIME NULL,ADD closed_at DATETIME NULL,
        ADD warranty_months INT NULL,ADD warranty_note VARCHAR(500) NULL,ADD warranty_expires_at DATETIME NULL,
        ADD final_amount_cents BIGINT NOT NULL DEFAULT 12345,ADD booking_fee_cents INT NOT NULL DEFAULT 100");
    $db->execute("ALTER TABLE after_sales ADD resolution VARCHAR(1000) NULL,ADD closed_at DATETIME NULL,ADD updated_at DATETIME NULL");
    foreach ([
        "CREATE TABLE order_measurement_states(order_id BIGINT PRIMARY KEY,result_status VARCHAR(24),service_payment_status VARCHAR(24),submitted_at DATETIME NULL,accepted_at DATETIME NULL,auto_accept_at DATETIME NULL,settlement_status VARCHAR(24),gross_amount_cents BIGINT DEFAULT 0,refunded_amount_cents BIGINT DEFAULT 0,service_rate_bp_snapshot INT DEFAULT 0,service_min_cents_snapshot INT DEFAULT 0,service_fee_cents BIGINT DEFAULT 0,worker_net_cents BIGINT DEFAULT 0,hold_until DATETIME NULL,max_hold_until DATETIME NULL,settled_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_measurement_spaces(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) UNIQUE,order_id BIGINT,name VARCHAR(120),sort_order INT,status VARCHAR(24),created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE media_files(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) UNIQUE,storage_disk VARCHAR(24),storage_key VARCHAR(255),mime_type VARCHAR(80),extension VARCHAR(12),size_bytes BIGINT,width_px INT,height_px INT,sha256 VARCHAR(64),compression_quality INT,metadata_json LONGTEXT,status VARCHAR(24),reference_count INT,created_at DATETIME,pending_delete_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE media_links(id BIGINT AUTO_INCREMENT PRIMARY KEY,media_file_id BIGINT,entity_type VARCHAR(40),entity_id BIGINT,purpose VARCHAR(40),sort_order INT,created_by_type VARCHAR(24),created_by_id BIGINT,created_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(24),payment_status VARCHAR(24)) ENGINE=InnoDB",
        "CREATE TABLE completion_submissions(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,worker_id BIGINT,description VARCHAR(1000),warranty_months INT,warranty_expires_at DATETIME NULL,warranty_note VARCHAR(500),status VARCHAR(24),submitted_at DATETIME,version INT,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,deadline_type VARCHAR(40),status VARCHAR(24),version INT,due_at DATETIME,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB",
    ] as $sql) $db->execute($sql);
    $settings=new class implements \WorkerAdmin\Contracts\SettingsProvider {
        public function get(string $key,$default=null) { return $key==='wechat.admin_openids' ? ['CI-ADMIN'] : $default; }
        public function bool(string $key,bool $default=false): bool { return $default; }
        public function int(string $key,int $default=0): int { return $default; }
    };
    $cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {
        public function hmac(string $value,string $context): string { return $value; }
        public function encrypt(string $value,string $context): ?string { return $value; }
        public function decrypt(string $value,string $context): string { return $value; }
    };
    $changes=new class implements \WorkerAdmin\Contracts\OrderChangeRepository {
        public function create(array $actor,array $order,int $amount,string $reason): array { throw new RuntimeException('assistance must not change amounts'); }
        public function decide(array $actor,array $order,string $id,string $decision,string $note): array { throw new RuntimeException('assistance must not confirm price changes'); }
    };
    $pricing=new class implements \WorkerAdmin\Contracts\OrderPricingRepository {
        public function updateActualQuantities(array $actor,array $order,array $items): array { throw new RuntimeException('assistance must not reprice'); }
    };
    $measurement=new \WorkerAdmin\Infrastructure\Persistence\PlatformPricingAwareOrderMeasurementCompletionRepository(
        new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderMeasurementCompletionRepository($db),$db);
    $fulfillment=new \WorkerAdmin\Application\Order\OrderFulfillment(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFulfillmentContextRepository($db),$changes,
        new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCompletionRepository($db),$measurement,$pricing,$settings);
    $auxiliary=new \WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFulfillmentAuxiliaryRepository($db,$settings,$cipher));
    $negotiation=new \WorkerAdmin\Application\Order\OrderNegotiation(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderNegotiationRepository($db));
    $mediaRepository=new \WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository($db,$settings);
    $factsRepository=new \WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderAssistanceRepository($db,$appointments,$fulfillment,$auxiliary,$negotiation,$mediaRepository);
    $assistance=new \WorkerAdmin\Application\Admin\AdminOrderAssistance($factsRepository);
    $command=function(string $action,array $overrides=[]) use($input): array {
        return array_replace($input,['request_id'=>'remaining_'.bin2hex(random_bytes(12)),'action'=>$action,'note'=>'管理员按微信反馈补录实际履约结果'],$overrides);
    };
    $reject=function(callable $call,string $code,string $label): void {
        $actual=''; try { $call(); } catch (OrderOperationException $exception) { $actual=$exception->apiCode(); }
        catch (\WorkerAdmin\Domain\Media\MediaOperationException $exception) { $actual=$exception->apiCode(); }
        adminProxyMysqlSame($actual,$code,$label);
    };
    $db->execute("UPDATE orders SET lifecycle_status='active',fulfillment_status='not_started',workflow_profile='measurement',completion_submitted_at=NULL WHERE id=1");
    $db->execute("INSERT INTO order_measurement_states(order_id,result_status,service_payment_status,settlement_status,gross_amount_cents) VALUES(1,'editing','paid','not_settled',8800)");
    $spaceRequest=$command('measurement_space',['name'=>'客厅']);
    $spaceResult=$assistance->execute('admin.order.assistance.save',$operator,$spaceRequest);
    $spaceId=$spaceResult['result']['id'];
    $assistance->execute('admin.order.assistance.save',$operator,$spaceRequest);
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_measurement_spaces'),1,'空间新增超时重试不会生成重复空间');
    $reject(fn()=>$assistance->execute('admin.order.assistance.save',$operator,$command('measurement_submit')),'MEASUREMENT_RESULT_REQUIRED','成果缺图时不能协助提交');

    $db->execute("CREATE TABLE users(id BIGINT PRIMARY KEY,status VARCHAR(24)) ENGINE=InnoDB");
    $db->execute("CREATE TABLE worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT) ENGINE=InnoDB");
    $db->execute("CREATE TABLE wechat_identities(id BIGINT PRIMARY KEY,user_id BIGINT,openid VARCHAR(80),status VARCHAR(24)) ENGINE=InnoDB");
    $db->execute("INSERT INTO users(id,status) VALUES(77,'active'),(66,'active'),(99,'active'),(901,'active')");
    $db->execute('INSERT INTO worker_profiles(id,user_id) VALUES(55,66)');
    $db->execute("INSERT INTO wechat_identities(id,user_id,openid,status) VALUES(1,901,'CI-ADMIN','active')");
    $source=tempnam(sys_get_temp_dir(),'bx_image_');
    file_put_contents($source,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    $optimizer=new class implements \WorkerAdmin\Contracts\ImageOptimizer {
        public int $calls=0; public $during=null; public array $paths=[];
        public function optimize(string $source): array {
            ++$this->calls;
            $path=tempnam(sys_get_temp_dir(),'bx_optimized_');copy($source,$path);$this->paths[]=$path;
            if ($this->during) ($this->during)();
            return ['path'=>$path,'storage_key'=>'test/'.bin2hex(random_bytes(8)).'.png','mime_type'=>'image/png','extension'=>'png','size_bytes'=>filesize($path),'width_px'=>1,'height_px'=>1,'sha256'=>hash_file('sha256',$path),'compression_quality'=>100];
        }
    };
    $storage=new class implements \WorkerAdmin\Contracts\ObjectStorage {
        public bool $enabledFlag=false;
        public function enabled(): bool { return $this->enabledFlag; }
        public function store(string $path,string $key,string $type='general',string $mime='application/octet-stream'): array { throw new RuntimeException('simulated cloud outage'); }
        public function downloadUrl(string $key,int $ttl=900): string { return ''; }
        public function usesHttp(): bool { return false; }
        public function read(string $key,int $ttl=900): string { return ''; }
        public function delete(string $key): void {}
    };
    $uploads=new \WorkerAdmin\Application\Admin\AdminOrderAssistanceMedia($assistance,$factsRepository,$optimizer,$storage);
    $uploadRequest=$command('upload_media',['purpose'=>'measurement_annotated','space_id'=>$spaceId]);
    $uploaded=$uploads->upload($operator,$uploadRequest,$source);
    $metadata=json_decode((string)$db->fetchValue('SELECT metadata_json FROM media_files WHERE public_id=:id',['id'=>$uploaded['id']]),true);
    adminProxyMysqlSame($metadata['operator_user_id'],901,'代上传保留真实管理员 users.id');
    adminProxyMysqlSame($metadata['watermarked'],false,'管理员代图不伪造师傅定位或水印');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT created_by_type FROM media_links LIMIT 1'),'mini_admin','测量媒体关联标记真实管理员来源');
    $replayedUpload=$uploads->upload($operator,$uploadRequest,$source);
    adminProxyMysqlSame($replayedUpload['id'],$uploaded['id'],'同请求同图返回原媒体');
    adminProxyMysqlSame($optimizer->calls,1,'已保存图片重试不重新压缩或占用配额');
    $badSource=tempnam(sys_get_temp_dir(),'bx_bad_image_');file_put_contents($badSource,'<?php echo 1;');
    $reject(fn()=>$uploads->upload($operator,$uploadRequest,$badSource),'INVALID_IMAGE_TYPE','伪装图片 MIME 不能绕过检查');unlink($badSource);
    $different=tempnam(sys_get_temp_dir(),'bx_different_');file_put_contents($different,file_get_contents($source).'different');
    $reject(fn()=>$uploads->upload($operator,$uploadRequest,$different),'ASSISTANCE_REQUEST_CONFLICT','同幂等键不能替换为另一张真实图片');unlink($different);
    $optimizer->during=function() use($db): void {
        adminProxyMysqlSame($db->pdo()->inTransaction(),false,'图片压缩期间不持有订单事务');
        $db->execute('UPDATE orders SET current_assignment_id=11 WHERE id=1');
    };
    $reject(fn()=>$uploads->upload($operator,$command('upload_media',['purpose'=>'measurement_photo','space_id'=>$spaceId]),$source),'ASSIGNMENT_CHANGED','压缩期间改派后，落库时再次拒绝旧关系');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM media_files'),1,'改派竞争不留下孤立媒体记录');
    adminProxyMysqlSame(is_file(end($optimizer->paths)),false,'落库失败清理本地优化副本');
    $optimizer->during=null; $db->execute('UPDATE orders SET current_assignment_id=10 WHERE id=1');
    $temporaryUpload=$uploads->upload($operator,$command('upload_media',['purpose'=>'measurement_photo','space_id'=>$spaceId]),$source);
    $deleteRequest=$command('media_delete',['purpose'=>'measurement_photo','space_id'=>$spaceId,'media_id'=>$temporaryUpload['id']]);
    $assistance->execute('admin.order.assistance.save',$operator,$deleteRequest);
    $assistance->execute('admin.order.assistance.save',$operator,$deleteRequest);
    adminProxyMysqlSame((string)$db->fetchValue('SELECT status FROM media_files WHERE public_id=:id',['id'=>$temporaryUpload['id']]),'pending_delete','未提交管理员图片可整理删除且保留延迟删除副本');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM media_links'),1,'图片删除重放不破坏其他成果');
    $emptySpace=$assistance->execute('admin.order.assistance.save',$operator,$command('measurement_space',['name'=>'未填空间']));
    $reject(fn()=>$assistance->execute('admin.order.assistance.save',$operator,$command('measurement_submit')),'MEASUREMENT_SPACE_IMAGE_REQUIRED','每个空间都必须有成果图片');
    $assistance->execute('admin.order.assistance.save',$operator,$command('space_delete',['space_id'=>$emptySpace['result']['id']]));
    $submitMeasurement=$command('measurement_submit');
    $notifyBefore=count(OrderNotificationService::$queued);
    $assistance->execute('admin.order.assistance.save',$operator,$submitMeasurement);
    $assistance->execute('admin.order.assistance.save',$operator,$submitMeasurement);
    adminProxyMysqlSame((string)$db->fetchValue('SELECT result_status FROM order_measurement_states WHERE order_id=1'),'submitted','普通测量协助提交后仍等待用户确认');
    adminProxyMysqlSame((string)$db->fetchValue("SELECT actor_type FROM order_events WHERE event_type='measurement_result_submitted' ORDER BY id DESC LIMIT 1"),'mini_admin','测量领域事件不冒充师傅');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT gross_amount_cents FROM order_measurement_states WHERE order_id=1'),8800,'普通测量提交不改变服务金额');
    adminProxyMysqlSame(count(OrderNotificationService::$queued),$notifyBefore+1,'测量重复提交只通知一次');
    $reject(fn()=>$assistance->execute('admin.order.assistance.save',$operator,$command('media_delete',['purpose'=>'measurement_annotated','space_id'=>$spaceId,'media_id'=>$uploaded['id']])),'MEASUREMENT_RESULT_LOCKED','已提交测量成果不能整理删除');

    // 同一图片成果在平台来源按既有平台规则转待核价，不执行客户确认或核价。
    $db->execute("UPDATE orders SET order_source_type='platform',fulfillment_status='not_started',completion_submitted_at=NULL WHERE id=1");
    $db->execute("UPDATE order_measurement_states SET result_status='editing',service_payment_status='not_required' WHERE order_id=1");
    $assistance->execute('admin.order.assistance.save',$operator,$command('measurement_submit'));
    adminProxyMysqlSame((string)$db->fetchValue('SELECT result_status FROM order_measurement_states WHERE order_id=1'),'accepted','平台测量按既有自动确认规则进入待核价');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT workflow_profile FROM orders WHERE id=1'),'measurement','平台测量协助提交不直接转施工或代核价');
    adminProxyMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_type='platform_measurement_pricing_confirmed'"),0,'不伪造客户核价确认');

    $db->execute("UPDATE orders SET workflow_profile='construction' WHERE id=1");
    $spaceDbId=(int)$db->fetchValue('SELECT id FROM order_measurement_spaces WHERE public_id=:id',['id'=>$spaceId]);
    $originalMedia=$mediaRepository->findPrivateMedia($uploaded['id']);
    adminProxyMysqlSame($mediaRepository->canAccessPrivateMedia($originalMedia,['actor_type'=>'user','actor_id'=>77,'user_id'=>77,'entity_type'=>'order_measurement_space','entity_id'=>$spaceDbId,'order_id'=>1]),true,'平台转施工后测量图片仍可只读回看');
    $db->execute("UPDATE orders SET order_source_type='consumer',workflow_profile='construction',fulfillment_status='not_started',completion_submitted_at=NULL,
        warranty_months=6,warranty_note='师傅已约定保障',warranty_expires_at='2027-01-10 12:00:00' WHERE id=1");
    $completionUpload=$uploads->upload($operator,$command('upload_media',['purpose'=>'completion']),$source);
    $completion=$command('completion_submit',['warranty_months'=>120,'warranty_note'=>'非法保障','amount_cents'=>1]);
    $assistance->execute('admin.order.assistance.save',$operator,$completion);
    $deadlineCount=(int)$db->fetchValue('SELECT COUNT(*) FROM order_deadlines');
    $notifyBefore=count(OrderNotificationService::$queued);
    $assistance->execute('admin.order.assistance.save',$operator,$completion);
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM completion_submissions'),1,'完工重放不生成第二次完工');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_deadlines'),$deadlineCount,'完工重放不重新启动验收计时');
    adminProxyMysqlSame(count(OrderNotificationService::$queued),$notifyBefore,'完工重放不重复通知');
    adminProxyMysqlSame(\WorkerAdmin\Services\SchedulerService::$estimates,1,'预计服务费沿用原规则且只生成一次');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=1'),'submitted','管理员提交完工仍等待客户验收');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT warranty_months FROM orders WHERE id=1'),6,'协助完工不能新增或改变保障月份');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT warranty_expires_at FROM orders WHERE id=1'),'2027-01-10 12:00:00','协助完工不能重置已约定保障截止时间');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT warranty_note FROM orders WHERE id=1'),'师傅已约定保障','保障说明保持原事实');
    adminProxyMysqlSame((int)$db->fetchValue('SELECT final_amount_cents FROM orders WHERE id=1'),12345,'协助完工不修改成交金额');
    $reject(fn()=>$assistance->execute('admin.order.assistance.save',$operator,$command('media_delete',['purpose'=>'completion','media_id'=>$completionUpload['id']])),'ASSISTANCE_MEDIA_LOCKED','完工提交后管理员也不能删除完工证据');
    $storage->enabledFlag=true;
    $collectionMaterial=$uploads->upload($operator,$command('upload_media',['purpose'=>'collection_material']),$source);
    adminProxyMysqlSame($collectionMaterial['cloud_synced'],false,'七牛失败不伪报云同步成功');
    adminProxyMysqlSame(is_file(end($optimizer->paths)),true,'七牛失败保留本站私有副本');
    $storage->enabledFlag=false;
    $privateMedia=$mediaRepository->findPrivateMedia($collectionMaterial['id']);
    adminProxyMysqlSame($mediaRepository->canAccessPrivateMedia($privateMedia,['actor_type'=>'user','actor_id'=>99,'user_id'=>99,'entity_type'=>'order','entity_id'=>1,'order_id'=>1]),false,'履约及收款材料不对普通浏览者公开');
    adminProxyMysqlSame($mediaRepository->canAccessPrivateMedia($privateMedia,['actor_type'=>'user','actor_id'=>77,'user_id'=>77,'entity_type'=>'order','entity_id'=>1,'order_id'=>1]),true,'订单客户可查看私有补充材料');
    $db->execute('UPDATE orders SET assigned_worker_id=99 WHERE id=1');
    adminProxyMysqlSame($mediaRepository->canAccessPrivateMedia($privateMedia,['actor_type'=>'worker','actor_id'=>55,'user_id'=>66,'entity_type'=>'order','entity_id'=>1,'order_id'=>1]),false,'改派后旧师傅的图片访问立即失效');
    $db->execute('UPDATE orders SET assigned_worker_id=55 WHERE id=1');
    adminProxyMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM media_links WHERE purpose='collection_receipt'"),0,'收款材料不能冒充师傅确认收款凭证');

    $db->execute("UPDATE orders SET lifecycle_status='completed',fulfillment_status='accepted' WHERE id=1");
    $db->execute("UPDATE after_sales SET resolution='师傅接受维修：已沟通',status='processing' WHERE id=1");
    $uploads->upload($operator,$command('upload_media',['purpose'=>'repair_result','after_sale_id'=>'REPAIR-CI']),$source);
    $repairResult=$command('repair_result',['after_sale_id'=>'REPAIR-CI']);
    $assistance->execute('admin.order.assistance.save',$operator,$repairResult);
    $notifyBefore=count(OrderNotificationService::$queued);
    $assistance->execute('admin.order.assistance.save',$operator,$repairResult);
    adminProxyMysqlSame((string)$db->fetchValue('SELECT status FROM after_sales WHERE id=1'),'resolved','维修结果复用既有处理结果领域命令');
    adminProxyMysqlSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'completed','维修结果不倒退已完成主订单');
    adminProxyMysqlSame((string)$db->fetchValue("SELECT actor_type FROM order_events WHERE event_type='repair_worker_completed' ORDER BY id DESC LIMIT 1"),'mini_admin','维修处理事件保留真实管理员来源');
    adminProxyMysqlSame(count(OrderNotificationService::$queued),$notifyBefore,'维修结果重放不重复通知');
    $db->execute("UPDATE after_sales SET status='processing',resolution='平台主动介入：待裁定' WHERE id=1");
    $reject(fn()=>$assistance->execute('admin.order.assistance.save',$operator,$command('repair_result',['after_sale_id'=>'REPAIR-CI'])),'REPAIR_NOT_ACTIVE','平台介入的维修必须走专用裁定，不能协助结案');
    foreach ($optimizer->paths as $path) if (is_file($path)) unlink($path);
    unlink($source);
    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.5.0 admin proxy action MySQL 5.7 integration: OK\n";
}

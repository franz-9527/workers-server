<?php
declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderAppointmentRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderChangeRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCompletionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDetailRepository;
use WorkerAdmin\Infrastructure\Persistence\OrderFulfillmentWriteGuard;
use WorkerAdmin\Services\OrderNotificationService;

require_once __DIR__.'/support/order_runtime.php';
if (getenv('V250_PLATFORM_MYSQL_TEST') !== '1' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('V250_DB_NAME') !== 'worker_test') {
    fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n"); exit(2);
}
function closureMysqlSame($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new RuntimeException($label.' actual='.var_export($actual,true));
    echo '[PASS] '.$label."\n";
}
function closureMysqlBlocked(callable $operation, string $label): void {
    $code='allowed';
    try { $operation(); } catch (OrderOperationException $error) { $code=$error->apiCode(); }
    closureMysqlSame($code,'ORDER_REFUND_PENDING',$label);
}
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'root'];
$rootDb=new Database($config);
closureMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');
$name='worker_v250_order_closure_ci';
$rootDb->execute('DROP DATABASE IF EXISTS `'.$name.'`');
$rootDb->execute('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$name; $db=new Database($config);
try {
    $ddl=[
        "CREATE TABLE orders(id BIGINT PRIMARY KEY,public_no VARCHAR(32),user_id BIGINT,assigned_worker_id BIGINT,current_assignment_id BIGINT,lifecycle_status VARCHAR(32),fulfillment_status VARCHAR(32),refund_status VARCHAR(32),dispute_status VARCHAR(32),order_source_type VARCHAR(32),workflow_profile VARCHAR(32),completion_submitted_at DATETIME NULL,version INT NOT NULL DEFAULT 1,is_demo TINYINT DEFAULT 1,final_amount_cents INT DEFAULT 50000,booking_fee_cents INT DEFAULT 5000) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT PRIMARY KEY,order_id BIGINT,worker_id BIGINT,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE users(id BIGINT PRIMARY KEY,mobile_ciphertext TEXT,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(id BIGINT PRIMARY KEY,user_id BIGINT,verification_status VARCHAR(32),invited_only TINYINT,cloud_record_mode VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(id BIGINT PRIMARY KEY,order_id BIGINT,claimed_worker_id BIGINT,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32),closed_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32),payment_status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE media_files(id BIGINT PRIMARY KEY,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE media_links(id BIGINT PRIMARY KEY,media_file_id BIGINT,entity_type VARCHAR(32),entity_id BIGINT,purpose VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE order_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,event_type VARCHAR(64),actor_type VARCHAR(32),actor_id BIGINT,payload_json LONGTEXT,created_at DATETIME) ENGINE=InnoDB",
        'CREATE TABLE notifications LIKE worker_test.notifications',
    ];
    foreach ($ddl as $sql) $db->execute($sql);
    $db->execute("INSERT INTO orders(id,public_no,user_id,assigned_worker_id,current_assignment_id,lifecycle_status,fulfillment_status,refund_status,dispute_status,order_source_type,workflow_profile) VALUES(1,'C1',8,7,71,'active','not_started','none','none','customer','construction')");
    $db->execute("INSERT INTO assignments VALUES(71,1,7,'accepted')");
    $db->execute("INSERT INTO users VALUES(8,'','active'),(9,'','active')");
    $db->execute("INSERT INTO media_files VALUES(1,'active')");
    $db->execute("INSERT INTO media_links VALUES(1,1,'order',1,'completion'),(2,1,'order',1,'collection_receipt')");
    $stale=$db->fetch('SELECT * FROM orders WHERE id=1');
    $db->execute("INSERT INTO order_cancel_requests VALUES(1,1,'pending',NULL)");
    $completion=new DatabaseOrderCompletionRepository($db);
    $changes=new DatabaseOrderChangeRepository($db);
    closureMysqlBlocked(static function () use ($db): void { (new DatabaseOrderAppointmentRepository($db))->save(['worker_id'=>7],['order_id'=>'C1','context_type'=>'service']); },'协商记录 pending、订单 none 时预约仍被阻断');
    closureMysqlBlocked(static function () use ($changes,$stale): void { $changes->create(['worker_id'=>7],$stale,1000,'加项'); },'旧页面不能创建加费');
    closureMysqlBlocked(static function () use ($changes,$stale): void { $changes->decide(['user_id'=>8],$stale,'X1','accepted',''); },'旧页面不能同意加费');
    closureMysqlBlocked(static function () use ($completion,$stale): void { $completion->complete(['worker_id'=>7],$stale,'',0,'',72); },'已上传照片仍不能越过协商提交完工');
    $db->execute("UPDATE orders SET fulfillment_status='submitted' WHERE id=1");
    $submitted=$db->fetch('SELECT * FROM orders WHERE id=1');
    closureMysqlBlocked(static function () use ($completion,$submitted): void { $completion->accept(['user_id'=>8],$submitted,5); },'已有收款凭证仍不能越过协商验收');
    closureMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),1,'所有阻断均不改变订单版本');
    closureMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_events'),0,'所有阻断均不写成功事件');
    $db->execute("UPDATE order_cancel_requests SET status='cancelled',closed_at=UTC_TIMESTAMP() WHERE id=1");
    $db->transaction(static function (Database $db): void { OrderFulfillmentWriteGuard::assertAllowed($db,$db->fetch('SELECT * FROM orders WHERE id=1 FOR UPDATE')); });
    closureMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),1,'撤回协商后闸门允许继续履约');

    $db->execute("INSERT INTO worker_profiles VALUES(7,9,'pending',0,'production')");
    $db->execute("INSERT INTO invite_tokens VALUES(1,1,7,'claimed')");
    $settings=new class implements SettingsProvider {
        public function get(string $key,$default=null) { return $default; }
        public function bool(string $key,bool $default=false): bool { return $default; }
        public function int(string $key,int $default=0): int { return $default; }
    };
    $reflection=new ReflectionClass(DatabaseOrderDetailRepository::class);
    $detail=$reflection->newInstanceWithoutConstructor();
    foreach (['db'=>$db,'settings'=>$settings] as $key=>$value) { $property=$reflection->getProperty($key); $property->setAccessible(true); $property->setValue($detail,$value); }
    $canQuote=$reflection->getMethod('canUsePendingPlatformInvite'); $canQuote->setAccessible(true);
    $quotable=['id'=>1,'user_id'=>8,'order_source_type'=>'platform','dispatch_mode'=>'multi_quote','audit_status'=>'approved','payment_status'=>'paid','publish_status'=>'published','lifecycle_status'=>'active','quote_status'=>'collecting','refund_status'=>'none'];
    $actor=['worker_id'=>7,'worker_status'=>'pending'];
    closureMysqlSame($canQuote->invoke($detail,$actor,$quotable),true,'真实领取邀请关系允许审核中师傅报价');
    $other=$quotable; $other['id']=2;
    closureMysqlSame($canQuote->invoke($detail,$actor,$other),false,'不能将本单邀请授权带到别的订单');
    $db->execute("UPDATE invite_tokens SET status='revoked' WHERE id=1");
    closureMysqlSame($canQuote->invoke($detail,$actor,$quotable),false,'撤销邀请立即撤销本单特殊报价资格');

    $event=new ReflectionMethod($completion,'event'); $event->setAccessible(true);
    $db->transaction(static function (Database $db) use ($event,$completion): void { $event->invoke($completion,$db,1,'completion_submitted','worker',7,[]); });
    $eventId=(int)$db->fetchValue('SELECT MAX(id) FROM order_events');
    closureMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM notifications'),1,'真实持久化事件排队一条客户提醒');
    OrderNotificationService::queuePersistedEvent($db,1,$eventId,'completion_submitted');
    closureMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM notifications'),1,'同一事件重放不重复落消息');
    closureMysqlSame((int)$db->fetchValue('SELECT CHAR_LENGTH(public_id) FROM notifications LIMIT 1'),26,'通知公开 ID 与正式数据库结构一致');
    $db->execute("CREATE TRIGGER audit_notice_failure BEFORE INSERT ON notifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_notices_unavailable'");
    $rolledBack=false;
    try {
        $db->transaction(static function (Database $db) use ($event,$completion): void {
            $db->execute('UPDATE orders SET version=version+1 WHERE id=1');
            $event->invoke($completion,$db,1,'completion_submitted','worker',7,[]);
        });
    } catch (PDOException $error) { $rolledBack=strpos($error->getMessage(),'audit_notices_unavailable')!==false; }
    closureMysqlSame($rolledBack,true,'真实通知写入失败触发事务异常');
    closureMysqlSame((int)$db->fetchValue('SELECT version FROM orders WHERE id=1'),1,'通知失败回滚业务状态');
    closureMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_events'),1,'通知失败回滚新增事件');
    closureMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM notifications'),1,'通知失败不留下部分消息');
    echo "V2.5.0 order closure MySQL 5.7 integration: OK\n";
} finally {
    $rootDb->execute('DROP DATABASE `'.$name.'`');
}

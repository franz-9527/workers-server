<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function setting($key,$default=null){return $default;}
}

namespace WorkerAdmin\Services {
    final class OrderNotificationService
    {
        public static $queued=[];
        public static $persisted=[];
        public static function queue($db,int $orderId,string $key,string $type,array $payload=[]): void
        {
            self::$queued[]=[$orderId,$key,$type,$payload];
        }
        public static function queuePersistedEvent($db,int $orderId,int $eventId,string $type,array $payload=[]): void
        {
            self::$persisted[]=[$orderId,$eventId,$type,$payload];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Scheduling\AutoAcceptanceProcessor;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Persistence/OrderAutomaticAcceptanceWriter.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/AutoAcceptanceProcessor.php';

    function autoAcceptMysqlAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_AUTO_ACCEPT_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
        fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");
        exit(2);
    }

    $config=[
        'host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),
        'port'=>(int)(getenv('V250_DB_PORT')?:3306),
        'database'=>'worker_test',
        'username'=>(string)(getenv('V250_DB_USER')?:'root'),
        'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root'),
    ];
    $rootDb=new Database($config);
    autoAcceptMysqlAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_auto_accept_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(40) NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 0,
            order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',
            lifecycle_status VARCHAR(24) NOT NULL,
            fulfillment_status VARCHAR(24) NOT NULL,
            completion_submitted_at DATETIME NULL,
            accepted_at DATETIME NULL,
            auto_accept_at DATETIME NULL,
            auto_review_at DATETIME NULL,
            closed_at DATETIME NULL,
            assigned_worker_id BIGINT UNSIGNED NULL,
            current_assignment_id BIGINT UNSIGNED NULL,
            dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE completion_submissions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL,
            accepted_at DATETIME NULL,
            accepted_type VARCHAR(32) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            deadline_type VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL,
            version INT NOT NULL,
            due_at DATETIME NOT NULL,
            block_reason VARCHAR(255) NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_order_deadline(order_id,deadline_type)
        ) ENGINE=InnoDB",
        "CREATE TABLE media_files(
            id BIGINT UNSIGNED PRIMARY KEY,
            status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE media_links(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            media_file_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            purpose VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(
            id BIGINT UNSIGNED PRIMARY KEY,
            completed_orders INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE scheduled_job_claims(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(64) NOT NULL,
            business_key VARCHAR(128) NOT NULL,
            business_version INT NOT NULL,
            scheduler_run_id BIGINT UNSIGNED NULL,
            status VARCHAR(24) NOT NULL,
            claimed_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            result_json LONGTEXT NULL,
            UNIQUE KEY uq_claim(job_name,business_key,business_version)
        ) ENGINE=InnoDB",
        "CREATE TABLE scheduled_job_failures(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(64) NOT NULL,
            business_key VARCHAR(128) NOT NULL,
            business_version INT NOT NULL,
            next_retry_at DATETIME NULL,
            resolved_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_key VARCHAR(128) NOT NULL UNIQUE,
            actor_type VARCHAR(24) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
            id BIGINT UNSIGNED PRIMARY KEY,
            scenario_key VARCHAR(128) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_no_snapshot VARCHAR(40) NOT NULL,
            build_status VARCHAR(24) NOT NULL,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE commission_finalizations(
            order_id BIGINT UNSIGNED PRIMARY KEY,
            finalized_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
    ];
    foreach($ddl as $sql)$db->pdo()->exec($sql);

    $seed=static function(Database $db,int $orderId,string $publicNo,int $workerId,bool $demo,bool $due=true,bool $receipt=true):void{
        $db->execute(
            "INSERT INTO orders(id,public_no,is_demo,order_source_type,lifecycle_status,fulfillment_status,completion_submitted_at,auto_accept_at,assigned_worker_id,current_assignment_id,dispute_status,refund_status,version,updated_at)
             VALUES(:id,:no,:demo,'consumer','active','submitted',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),"
             .($due?"DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)":"DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR)")
             .",:worker,:assignment,'none','none',7,UTC_TIMESTAMP())",
            ['id'=>$orderId,'no'=>$publicNo,'demo'=>$demo?1:0,'worker'=>$workerId,'assignment'=>$orderId+5000]
        );
        $db->execute("INSERT INTO completion_submissions(order_id,status,updated_at) VALUES(:id,'submitted',UTC_TIMESTAMP())",['id'=>$orderId]);
        $db->execute("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at) VALUES(:id,'auto_accept_completion','pending',7,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['id'=>$orderId]);
        $db->execute("INSERT IGNORE INTO worker_profiles(id,completed_orders,updated_at) VALUES(:worker,0,UTC_TIMESTAMP())",['worker'=>$workerId]);
        if($receipt){
            $media=$orderId+9000;
            $db->execute("INSERT INTO media_files(id,status) VALUES(:id,'active')",['id'=>$media]);
            $db->execute("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose) VALUES(:media,'order',:order,'collection_receipt')",['media'=>$media,'order'=>$orderId]);
        }
    };
    $finalize=static function(Database $db,int $orderId):void{
        $db->execute("INSERT INTO commission_finalizations(order_id,finalized_at) VALUES(:id,UTC_TIMESTAMP())",['id'=>$orderId]);
    };

    $seed($db,1,'BXPROD-A',201,false,true,true);
    $db->execute("INSERT INTO scheduled_job_failures(job_name,business_key,business_version,next_retry_at) VALUES('auto_accept_completion','order:1',7,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))");
    $processor=new AutoAcceptanceProcessor($db);
    autoAcceptMysqlAssert($processor->acceptProduction(1,7,9001,$finalize),true,'production processor accepts due non-demo order');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'completed','production order completed');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=1'),'accepted','production fulfillment accepted');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT accepted_type FROM completion_submissions WHERE order_id=1'),'auto_72h','completion submission marked auto accepted');
    autoAcceptMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=1 AND deadline_type='auto_review_order' AND status='pending'"),1,'auto review deadline created');
    autoAcceptMysqlAssert((string)$db->fetchValue("SELECT status FROM scheduled_job_claims WHERE job_name='auto_accept_completion' AND business_key='order:1' AND business_version=7"),'completed','production scheduler claim completed');
    autoAcceptMysqlAssert($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE job_name='auto_accept_completion' AND business_key='order:1'")!==null,true,'same-version scheduler failure resolved');
    autoAcceptMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM commission_finalizations WHERE order_id=1'),1,'production commission finalizer invoked once');
    autoAcceptMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_key='scheduler:auto_accept_completion:order:1:v7'"),1,'production deterministic auto-accept event written');
    autoAcceptMysqlAssert(count(OrderNotificationService::$queued),1,'production notification projection queued once');
    autoAcceptMysqlAssert($processor->acceptProduction(1,7,9001,$finalize),false,'production re-run is idempotent after acceptance');

    $seed($db,2,'BXPROD-BLOCK',202,false,true,true);
    $db->execute("UPDATE orders SET dispute_status='open' WHERE id=2");
    autoAcceptMysqlAssert($processor->acceptProduction(2,7,9002,$finalize),false,'blocking dispute prevents production auto accept');
    autoAcceptMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:2'"),0,'blocked production order creates no claim');

    $seed($db,101,'BXTEST-A',301,true,false,true);
    $seed($db,102,'BXTEST-B',302,true,false,true);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(11,'ordinary.construction.single_grab.waiting.open',101,'BXTEST-A','built'),(12,'ordinary.construction.single_grab.waiting.open',102,'BXTEST-B','built')");
    $testResult=$processor->acceptTestInstance(11,true,$finalize,'consumer');
    autoAcceptMysqlAssert(!empty($testResult['accepted']),true,'scoped test auto accept succeeds after deadline clock fixture');
    autoAcceptMysqlAssert((string)$testResult['scope'],'test_instance','scoped result declares test-instance scope');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=101'),'completed','target test order completed');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=101'),'accepted','target test fulfillment accepted');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=102'),'active','neighbor test order remains active');
    autoAcceptMysqlAssert((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=102'),'submitted','neighbor test order remains submitted');
    autoAcceptMysqlAssert($db->fetchValue('SELECT accepted_at FROM orders WHERE id=102'),null,'neighbor test order remains unaccepted');
    autoAcceptMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:101'"),0,'scoped test execution does not fabricate global scheduler claim');
    autoAcceptMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_key='test-auto:11:auto_accept_completion:v7'"),1,'scoped auto accept writes instance event key');
    autoAcceptMysqlAssert(count(OrderNotificationService::$persisted),1,'scoped notification projection uses persisted event');
    autoAcceptMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM commission_finalizations WHERE order_id=101'),1,'scoped ordinary auto accept invokes commission finalizer');

    $seed($db,103,'BXDEMOHALL77',303,true,false,true);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(13,'ordinary.construction.single_grab.waiting.open',103,'BXDEMOHALL77','built')");
    $blocked=false;
    try{$processor->acceptTestInstance(13,true,$finalize,'consumer');}catch(\RuntimeException $e){$blocked=true;}
    autoAcceptMysqlAssert($blocked,true,'review hall sample is blocked from scoped auto accept');

    $seed($db,104,'BXTEST-NORECEIPT',304,true,false,false);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(14,'ordinary.construction.single_grab.waiting.open',104,'BXTEST-NORECEIPT','built')");
    $blocked=false;
    try{$processor->acceptTestInstance(14,true,$finalize,'consumer');}catch(\RuntimeException $e){$blocked=true;}
    autoAcceptMysqlAssert($blocked,true,'scoped auto accept requires active collection receipt evidence');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 auto acceptance processor MySQL 5.7 integration: OK\n";
}

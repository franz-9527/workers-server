<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function setting($key,$default=null){
        if($key==='order.auto_review_days')return 5;
        if($key==='measurement.default_freeze_days')return 15;
        if($key==='measurement.max_freeze_days')return 30;
        return $default;
    }
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
    use WorkerAdmin\Infrastructure\Scheduling\MeasurementAutoAcceptanceProcessor;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Persistence/OrdinaryMeasurementAcceptanceWriter.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/MeasurementAutoAcceptanceProcessor.php';

    function maMysqlAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_MEASUREMENT_ACCEPT_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    maMysqlAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_measure_accept_ci';
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
            workflow_profile VARCHAR(24) NOT NULL,
            lifecycle_status VARCHAR(24) NOT NULL,
            fulfillment_status VARCHAR(24) NOT NULL,
            accepted_at DATETIME NULL,
            auto_review_at DATETIME NULL,
            closed_at DATETIME NULL,
            assigned_worker_id BIGINT UNSIGNED NULL,
            final_amount_cents BIGINT NOT NULL DEFAULT 0,
            dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_measurement_states(
            order_id BIGINT UNSIGNED PRIMARY KEY,
            result_status VARCHAR(24) NOT NULL,
            auto_accept_at DATETIME NULL,
            accepted_at DATETIME NULL,
            settlement_status VARCHAR(24) NOT NULL,
            gross_amount_cents BIGINT NOT NULL DEFAULT 0,
            service_rate_bp_snapshot INT NOT NULL DEFAULT 0,
            service_min_cents_snapshot BIGINT NOT NULL DEFAULT 0,
            service_fee_cents BIGINT NOT NULL DEFAULT 0,
            worker_net_cents BIGINT NOT NULL DEFAULT 0,
            hold_until DATETIME NULL,
            max_hold_until DATETIME NULL,
            settled_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            commission_rate_bp_snapshot INT NOT NULL DEFAULT 0,
            commission_min_cents_snapshot BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            business_type VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL,
            amount_cents BIGINT NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(64) NOT NULL,
            direction VARCHAR(16) NOT NULL,
            amount_cents BIGINT NOT NULL,
            available_delta_cents BIGINT NOT NULL,
            frozen_delta_cents BIGINT NOT NULL,
            business_type VARCHAR(64) NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            description VARCHAR(255) NOT NULL,
            status VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_ledger_key(idempotency_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            deadline_type VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL,
            version INT NOT NULL,
            due_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
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
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB"
    ];
    foreach($ddl as $sql)$db->pdo()->exec($sql);

    $seed=static function(Database $db,int $orderId,string $publicNo,int $workerId,bool $demo,bool $due=true,int $paid=12000):void{
        $db->execute(
            "INSERT INTO orders(
                id,public_no,is_demo,order_source_type,workflow_profile,lifecycle_status,fulfillment_status,
                assigned_worker_id,final_amount_cents,dispute_status,refund_status,version,updated_at
             ) VALUES(
                :id,:no,:demo,'consumer','measurement','active','submitted',
                :worker,12000,'none','none',7,UTC_TIMESTAMP()
             )",
            ['id'=>$orderId,'no'=>$publicNo,'demo'=>$demo?1:0,'worker'=>$workerId]
        );
        $db->execute(
            "INSERT INTO order_measurement_states(order_id,result_status,auto_accept_at,settlement_status,updated_at)
             VALUES(:id,'submitted',".($due?"DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)":"DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR)").",'pending',UTC_TIMESTAMP())",
            ['id'=>$orderId]
        );
        $db->execute(
            "INSERT INTO assignments(id,order_id,worker_id,status,commission_rate_bp_snapshot,commission_min_cents_snapshot,updated_at)
             VALUES(:assignment,:order,:worker,'accepted',1000,500,UTC_TIMESTAMP())",
            ['assignment'=>$orderId+5000,'order'=>$orderId,'worker'=>$workerId]
        );
        $db->execute(
            "INSERT INTO payment_orders(order_id,business_type,status,amount_cents)
             VALUES(:order,'order_booking','paid',2000),(:order2,'measurement_balance','paid',:paid)",
            ['order'=>$orderId,'order2'=>$orderId,'paid'=>max(0,$paid-2000)]
        );
    };

    $processor=new MeasurementAutoAcceptanceProcessor($db);

    $seed($db,1,'BXMEASURE-PROD-A',201,false,true,12000);
    $db->execute("INSERT INTO scheduled_job_failures(job_name,business_key,business_version,next_retry_at) VALUES('measurement_accept','order:1',7,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))");
    maMysqlAssert($processor->acceptProduction(1,7,9001),true,'production processor accepts due non-demo measurement');
    maMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'completed','production measurement order completed');
    maMysqlAssert((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=1'),'accepted','production measurement fulfillment accepted');
    maMysqlAssert((string)$db->fetchValue('SELECT result_status FROM order_measurement_states WHERE order_id=1'),'accepted','production measurement result accepted');
    maMysqlAssert((string)$db->fetchValue('SELECT settlement_status FROM order_measurement_states WHERE order_id=1'),'frozen','production measurement settlement frozen');
    maMysqlAssert((int)$db->fetchValue('SELECT worker_net_cents FROM order_measurement_states WHERE order_id=1'),10800,'production net income calculated from assignment snapshot');
    maMysqlAssert((int)$db->fetchValue("SELECT frozen_delta_cents FROM worker_balance_ledger WHERE business_id=1 AND entry_type='measurement_income_frozen'"),10800,'production net income frozen in ledger');
    maMysqlAssert((string)$db->fetchValue('SELECT status FROM assignments WHERE id=5001'),'completed','production measurement assignment completed');
    maMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=1 AND deadline_type='auto_review' AND status='pending'"),1,'production measurement auto-review deadline created');
    maMysqlAssert((string)$db->fetchValue("SELECT status FROM scheduled_job_claims WHERE job_name='measurement_accept' AND business_key='order:1' AND business_version=7"),'completed','production measurement scheduler claim completed');
    maMysqlAssert($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE job_name='measurement_accept' AND business_key='order:1'")!==null,true,'production measurement same-version failure resolved');
    maMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_key='scheduler:measurement_accept:order:1:v8'"),1,'production deterministic measurement event written');
    maMysqlAssert(count(OrderNotificationService::$queued),1,'production measurement notification projection queued once');
    maMysqlAssert($processor->acceptProduction(1,7,9001),false,'production measurement re-run is idempotent');

    $seed($db,2,'BXMEASURE-PROD-BLOCK',202,false,true,12000);
    $db->execute("UPDATE orders SET dispute_status='open' WHERE id=2");
    maMysqlAssert($processor->acceptProduction(2,7,9002),false,'blocking dispute prevents production measurement auto accept');
    maMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:2'"),0,'blocked production measurement creates no claim');

    $seed($db,101,'BXMEASURE-TEST-A',301,true,false,12000);
    $seed($db,102,'BXMEASURE-TEST-B',302,true,false,12000);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES
         (11,'ordinary.measurement.multi_quote.waiting.zero_quotes',101,'BXMEASURE-TEST-A','built'),
         (12,'ordinary.measurement.multi_quote.waiting.zero_quotes',102,'BXMEASURE-TEST-B','built')"
    );

    $testResult=$processor->acceptTestInstance(11,true);
    maMysqlAssert(!empty($testResult['accepted']),true,'scoped measurement auto accept succeeds with deadline clock fixture');
    maMysqlAssert((string)$testResult['scope'],'test_instance','scoped measurement result declares instance scope');
    maMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=101'),'completed','target measurement instance completed');
    maMysqlAssert((string)$db->fetchValue('SELECT result_status FROM order_measurement_states WHERE order_id=101'),'accepted','target measurement result accepted');
    maMysqlAssert((string)$db->fetchValue('SELECT settlement_status FROM order_measurement_states WHERE order_id=101'),'frozen','target measurement settlement frozen');
    maMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=102'),'active','neighbor measurement instance remains active');
    maMysqlAssert((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=102'),'submitted','neighbor measurement instance remains submitted');
    maMysqlAssert((string)$db->fetchValue('SELECT result_status FROM order_measurement_states WHERE order_id=102'),'submitted','neighbor measurement result remains submitted');
    maMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=102"),0,'neighbor measurement instance receives no frozen ledger');
    maMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:101'"),0,'scoped measurement execution does not fabricate global scheduler claim');
    maMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_key='test-measurement-auto:11:measurement_accept:v8'"),1,'scoped measurement event is instance-keyed');
    maMysqlAssert(count(OrderNotificationService::$persisted),1,'scoped measurement notification uses persisted event');

    $seed($db,103,'BXDEMOHALL77',303,true,false,12000);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(13,'ordinary.measurement.multi_quote.waiting.zero_quotes',103,'BXDEMOHALL77','built')");
    $blocked=false;
    try{$processor->acceptTestInstance(13,true);}catch(\RuntimeException $e){$blocked=true;}
    maMysqlAssert($blocked,true,'review hall sample is blocked from scoped measurement accept');

    $seed($db,104,'BXMEASURE-UNDERPAID',304,true,false,7000);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(14,'ordinary.measurement.multi_quote.waiting.zero_quotes',104,'BXMEASURE-UNDERPAID','built')");
    $blocked=false;
    try{$processor->acceptTestInstance(14,true);}catch(\RuntimeException $e){$blocked=true;}
    maMysqlAssert($blocked,true,'underpaid measurement is blocked from scoped auto accept');
    maMysqlAssert((string)$db->fetchValue('SELECT result_status FROM order_measurement_states WHERE order_id=104'),'submitted','underpaid rejection rolls back measurement state');
    maMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=104'),0,'underpaid rejection creates no frozen ledger');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 measurement auto acceptance processor MySQL 5.7 integration: OK\n";
}

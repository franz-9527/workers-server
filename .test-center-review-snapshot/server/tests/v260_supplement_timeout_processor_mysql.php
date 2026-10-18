<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function public_id(){static $i=0;$i++;return 'SUPP'.str_pad((string)$i,20,'0',STR_PAD_LEFT);}
    function business_no($prefix){static $i=0;$i++;return strtoupper($prefix).gmdate('ymd').str_pad((string)$i,8,'0',STR_PAD_LEFT);}
}

namespace WorkerAdmin\Services {
    final class OrderNotificationService
    {
        public static array $queued=[];
        public static array $persisted=[];
        public static function queue($db,int $orderId,string $key,string $type,array $payload=[]): void{self::$queued[]=[$orderId,$key,$type,$payload];}
        public static function queuePersistedEvent($db,int $orderId,int $eventId,string $type,array $payload=[]): void{self::$persisted[]=[$orderId,$eventId,$type,$payload];}
    }
}

namespace {
    use RuntimeException;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Scheduling\SupplementTimeoutProcessor;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/SupplementTimeoutProcessor.php';

    function stSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_SUPPLEMENT_TIMEOUT_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    stSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_supplement_timeout_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;$db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(
          id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
          is_demo TINYINT NOT NULL DEFAULT 0,order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',
          workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',audit_status VARCHAR(24) NOT NULL,
          audit_note VARCHAR(500) NULL,supplement_due_at DATETIME NULL,lifecycle_status VARCHAR(48) NOT NULL,
          publish_status VARCHAR(24) NOT NULL,quote_status VARCHAR(24) NOT NULL,assignment_status VARCHAR(24) NOT NULL,
          fulfillment_status VARCHAR(24) NOT NULL,payment_status VARCHAR(24) NOT NULL,refund_status VARCHAR(24) NOT NULL,
          cancelled_at DATETIME NULL,closed_at DATETIME NULL,version INT NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,deadline_type VARCHAR(64) NOT NULL,
          status VARCHAR(24) NOT NULL,version INT NOT NULL,due_at DATETIME NOT NULL,reminded_at DATETIME NULL,
          processed_at DATETIME NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,
          status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,app_id VARCHAR(80) NULL,mch_id VARCHAR(80) NULL,
          payer_openid VARCHAR(160) NULL,wechat_prepay_id VARCHAR(128) NULL,wechat_transaction_id VARCHAR(128) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,
          simulated_provider VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,
          order_id BIGINT UNSIGNED NOT NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents BIGINT UNSIGNED NOT NULL,
          reason VARCHAR(500) NOT NULL,status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,
          wechat_refund_id VARCHAR(128) NULL,requested_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
          applicant_type VARCHAR(24) NOT NULL,applicant_id BIGINT UNSIGNED NULL,request_type VARCHAR(48) NOT NULL,
          order_state_snapshot_json LONGTEXT NOT NULL,reason_code VARCHAR(64) NOT NULL,reason VARCHAR(500) NOT NULL,status VARCHAR(24) NOT NULL,
          requested_refund_cents BIGINT UNSIGNED NOT NULL,requested_at DATETIME NOT NULL,closed_at DATETIME NULL,
          created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_decisions(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,cancel_request_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
          decision VARCHAR(24) NOT NULL,responsibility VARCHAR(48) NOT NULL,reason VARCHAR(500) NOT NULL,
          refund_amount_cents BIGINT UNSIGNED NOT NULL,run_empty_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
          refund_id BIGINT UNSIGNED NULL,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,
          event_key VARCHAR(128) NOT NULL UNIQUE,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,
          payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE scheduled_job_claims(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(64) NOT NULL,business_key VARCHAR(128) NOT NULL,
          business_version INT NOT NULL,scheduler_run_id BIGINT UNSIGNED NULL,status VARCHAR(24) NOT NULL,claimed_at DATETIME NOT NULL,
          completed_at DATETIME NULL,result_json LONGTEXT NULL,UNIQUE KEY uq_claim(job_name,business_key,business_version)
        ) ENGINE=InnoDB",
        "CREATE TABLE scheduled_job_failures(
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_name VARCHAR(64) NOT NULL,business_key VARCHAR(128) NOT NULL,
          business_version INT NOT NULL,scheduler_run_id BIGINT UNSIGNED NULL,attempt_no INT NOT NULL DEFAULT 1,
          error_class VARCHAR(191) NULL,error_message VARCHAR(1000) NULL,next_retry_at DATETIME NULL,resolved_at DATETIME NULL,created_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
          id BIGINT UNSIGNED PRIMARY KEY,scenario_key VARCHAR(128) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,
          order_no_snapshot VARCHAR(80) NOT NULL,build_status VARCHAR(24) NOT NULL,archived_at DATETIME NULL,deleted_at DATETIME NULL
        ) ENGINE=InnoDB"
    ];
    foreach($ddl as$sql)$db->pdo()->exec($sql);

    $seedOrder=static function(Database $db,int $id,string $no,bool $demo,int $version=3):void{
        $db->execute(
            "INSERT INTO orders(
                id,public_no,user_id,is_demo,order_source_type,workflow_profile,audit_status,audit_note,supplement_due_at,
                lifecycle_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,version,updated_at
             ) VALUES(
                :id,:no,:user,:demo,'consumer','construction','supplement_required','请补资料',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR),
                'active','hidden','waiting','unassigned','not_started','paid','none',:version,UTC_TIMESTAMP()
             )",
            ['id'=>$id,'no'=>$no,'user'=>1000+$id,'demo'=>$demo?1:0,'version'=>$version]
        );
    };
    $seedPayment=static function(Database $db,int $orderId,int $amount,bool $internal=false,?int $instanceId=null):int{
        $id=$db->insert(
            "INSERT INTO payment_orders(order_id,business_type,status,amount_cents,app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id)
             VALUES(:order,'order_booking','paid',:amount,:app,:mch,:openid,:prepay,:txn)",
            [
                'order'=>$orderId,'amount'=>$amount,
                'app'=>$internal?'TEST_INTERNAL':'wx-app','mch'=>$internal?'TEST_INTERNAL':'mch-real',
                'openid'=>$internal?('TEST_INTERNAL:'.$instanceId.':'.$orderId):'openid-real',
                'prepay'=>$internal?null:'prepay-real','txn'=>$internal?null:'txn-real',
            ]
        );
        if($internal&&$instanceId!==null)$db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(:i,:p,'internal_test')",['i'=>$instanceId,'p'=>$id]);
        return$id;
    };

    $processor=new SupplementTimeoutProcessor($db);

    // reminder and expiration must have distinct deterministic events.
    $seedOrder($db,1,'BX-SUPP-PROD',false,7);$pay1=$seedPayment($db,1,5000,false,null);
    $deadline1=$db->insert("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,updated_at) VALUES(1,'audit_supplement_timeout','pending',7,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())");
    stSame($processor->processProduction($deadline1,7,9001),'reminded','production future deadline sends reminder');
    stSame($db->fetchValue('SELECT reminded_at FROM order_deadlines WHERE id=:id',['id'=>$deadline1])!==null,true,'reminder timestamp recorded');
    stSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_type='supplement_deadline_reminder'"),1,'reminder event recorded');
    stSame((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:1'"),0,'reminder does not claim terminal job');

    $db->execute("UPDATE order_deadlines SET due_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=:id",['id'=>$deadline1]);
    $db->execute("INSERT INTO scheduled_job_failures(job_name,business_key,business_version,scheduler_run_id,next_retry_at,created_at) VALUES('supplement_timeout','order:1',7,8001,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),UTC_TIMESTAMP())");
    stSame($processor->processProduction($deadline1,7,9002),'expired','production due supplement order expires');
    stSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'cancel_approved_pending_refund','production paid supplement timeout waits for refund');
    stSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=1'),'pending_review','production order stays pending_review before provider acceptance');
    stSame((string)$db->fetchValue('SELECT status FROM refunds WHERE order_id=1'),'pending_review','production refund row starts pending_review');
    stSame($db->fetchValue('SELECT wechat_refund_id FROM refunds WHERE order_id=1'),null,'processor never creates provider refund id');
    stSame($db->fetchValue('SELECT closed_at FROM order_cancel_requests WHERE order_id=1'),null,'cancel request remains open while refund outstanding');
    stSame((string)$db->fetchValue("SELECT status FROM scheduled_job_claims WHERE job_name='supplement_timeout' AND business_key='order:1' AND business_version=7"),'completed','production supplement claim completed');
    stSame($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE job_name='supplement_timeout' AND business_key='order:1'")!==null,true,'successful expiration resolves same-version failure');
    stSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1"),2,'reminder and expiration keep distinct events');
    stSame(count(OrderNotificationService::$queued),2,'production reminder and expiration both queue notifications');

    // no payment closes immediately.
    $seedOrder($db,2,'BX-SUPP-NOPAY',false,4);
    $deadline2=$db->insert("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,updated_at) VALUES(2,'audit_supplement_timeout','pending',4,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),UTC_TIMESTAMP())");
    stSame($processor->processProduction($deadline2,4,9003),'expired','production no-payment supplement timeout expires');
    stSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=2'),'cancelled','no-payment timeout closes order');
    stSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=2'),'none','no-payment timeout has no refund state');
    stSame($db->fetchValue('SELECT closed_at FROM order_cancel_requests WHERE order_id=2')!==null,true,'no-payment cancel request closes immediately');

    // scoped A/B isolation with future DB deadline and virtual deadline+1 fixture.
    $seedOrder($db,101,'BX-SUPP-TEST-A',true,5);$seedPayment($db,101,5000,true,11);
    $seedOrder($db,102,'BX-SUPP-TEST-B',true,5);$seedPayment($db,102,5000,true,12);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES
        (11,'ordinary.construction.proxy.draft',101,'BX-SUPP-TEST-A','built'),
        (12,'ordinary.construction.proxy.draft',102,'BX-SUPP-TEST-B','built')");
    $d101=$db->insert("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,updated_at) VALUES(101,'audit_supplement_timeout','pending',5,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),UTC_TIMESTAMP())");
    $d102=$db->insert("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,updated_at) VALUES(102,'audit_supplement_timeout','pending',5,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 DAY),UTC_TIMESTAMP())");
    $available=$processor->inspectTestInstance(11);
    stSame((int)($available['deadline_id']??0),$d101,'scoped inspector binds exact supplement deadline');
    $scoped=$processor->expireTestInstance(11,true);
    stSame(!empty($scoped['expired']),true,'scoped deadline+1 fixture expires target');
    stSame((string)$db->fetchValue('SELECT audit_status FROM orders WHERE id=101'),'rejected','scoped target audit rejected');
    stSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=101'),'pending_review','scoped target refund remains pending_review');
    stSame((string)$db->fetchValue('SELECT status FROM refunds WHERE order_id=101'),'pending_review','scoped target internal refund recorded');
    stSame((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:101'"),0,'scoped action creates no global claim');
    stSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_key LIKE 'test-supplement-timeout:11:test-expired:v%'"),1,'scoped event is instance keyed');
    stSame((string)$db->fetchValue('SELECT audit_status FROM orders WHERE id=102'),'supplement_required','neighbor supplement order remains unchanged');
    stSame((string)$db->fetchValue('SELECT status FROM order_deadlines WHERE id=:id',['id'=>$d102]),'pending','neighbor deadline remains pending');
    stSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=102'),0,'neighbor creates no refund');
    stSame(count(OrderNotificationService::$persisted),1,'scoped timeout uses persisted event notification');

    // unowned payment and BXDEMOHALL are blocked.
    $seedOrder($db,103,'BX-SUPP-UNOWNED',true,2);$seedPayment($db,103,5000,false,null);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(13,'ordinary.construction.proxy.draft',103,'BX-SUPP-UNOWNED','built')");
    $db->execute("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,updated_at) VALUES(103,'audit_supplement_timeout','pending',2,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP())");
    stSame($processor->inspectTestInstance(13),null,'unowned payment not exposed by scoped inspector');
    $blocked=false;try{$processor->expireTestInstance(13,true);}catch(RuntimeException $e){$blocked=true;}
    stSame($blocked,true,'unowned payment blocks scoped supplement timeout');

    $seedOrder($db,104,'BXDEMOHALL-SUPP',true,2);$seedPayment($db,104,5000,true,14);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(14,'ordinary.construction.proxy.draft',104,'BXDEMOHALL-SUPP','built')");
    $db->execute("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,updated_at) VALUES(104,'audit_supplement_timeout','pending',2,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP())");
    $blocked=false;try{$processor->expireTestInstance(14,true);}catch(RuntimeException $e){$blocked=true;}
    stSame($blocked,true,'BXDEMOHALL supplement sample is blocked');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 supplement timeout processor MySQL 5.7 integration: OK\n";
}

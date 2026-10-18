<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function public_id(){static $i=0;$i++;return 'TEST-PUBLIC-'.str_pad((string)$i,6,'0',STR_PAD_LEFT);}
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
    use WorkerAdmin\Infrastructure\Scheduling\AutoReviewProcessor;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/ReviewRepository.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseReviewRepository.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/AutoReviewProcessor.php';

    function autoReviewMysqlAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_AUTO_REVIEW_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    autoReviewMysqlAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_auto_review_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(40) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 0,
            order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',
            workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',
            lifecycle_status VARCHAR(24) NOT NULL DEFAULT 'completed',
            fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
            assigned_worker_id BIGINT UNSIGNED NULL,
            accepted_at DATETIME NULL,
            auto_review_at DATETIME NULL,
            dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE reviews(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            attitude_rating TINYINT NOT NULL,
            craft_rating TINYINT NOT NULL,
            content TEXT NULL,
            is_auto TINYINT NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_reviews_order(order_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE rating_summaries(
            worker_id BIGINT UNSIGNED PRIMARY KEY,
            review_count INT NOT NULL DEFAULT 0,
            rating_sum BIGINT NOT NULL DEFAULT 0,
            rating_average DECIMAL(4,2) NOT NULL DEFAULT 5.00,
            five_star_count INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(
            id BIGINT UNSIGNED PRIMARY KEY,
            rating DECIMAL(4,2) NOT NULL DEFAULT 5.00,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            deadline_type VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL,
            version INT NOT NULL DEFAULT 1,
            due_at DATETIME NOT NULL,
            block_reason VARCHAR(255) NULL,
            processed_at DATETIME NULL,
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

    $seed=static function(Database $db,int $orderId,string $publicNo,int $userId,int $workerId,bool $demo,bool $due=true):void{
        $db->execute(
            "INSERT INTO orders(
                id,public_no,user_id,is_demo,order_source_type,workflow_profile,lifecycle_status,
                fulfillment_status,assigned_worker_id,accepted_at,auto_review_at,dispute_status,refund_status,version,updated_at
             ) VALUES(
                :id,:no,:user,:demo,'consumer','construction','completed',
                'accepted',:worker,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 DAY),
                ".($due?"DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)":"DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY)").",
                'none','none',7,UTC_TIMESTAMP()
             )",
            ['id'=>$orderId,'no'=>$publicNo,'user'=>$userId,'demo'=>$demo?1:0,'worker'=>$workerId]
        );
        $db->execute(
            "INSERT INTO worker_profiles(id,rating,updated_at)
             VALUES(:id,4.00,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE updated_at=UTC_TIMESTAMP()",
            ['id'=>$workerId]
        );
        $db->execute(
            "INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at)
             VALUES(:id,'auto_review_order','pending',7,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['id'=>$orderId]
        );
    };

    $processor=new AutoReviewProcessor($db);

    // 为生产目标师傅预置一条 4/4 可见评价，验证自动五星后 summary 真实重算为 4.50。
    $db->execute("INSERT INTO worker_profiles(id,rating,updated_at) VALUES(201,4.00,UTC_TIMESTAMP())");
    $db->execute(
        "INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at)
         VALUES('PREVIOUS-REVIEW',9001,9001,201,4,4,'既有评价',0,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO rating_summaries(worker_id,review_count,rating_sum,rating_average,five_star_count,updated_at)
         VALUES(201,1,4,4.00,0,UTC_TIMESTAMP())"
    );

    $seed($db,1,'BXAUTO-REVIEW-PROD',101,201,false,true);
    $db->execute(
        "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,next_retry_at)
         VALUES('auto_review_order','order:1',7,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))"
    );
    autoReviewMysqlAssert($processor->reviewProduction(1,7,9001),true,'production processor creates due auto review');
    $review=$db->fetch('SELECT * FROM reviews WHERE order_id=1');
    autoReviewMysqlAssert($review!==null,true,'production auto review row created');
    autoReviewMysqlAssert((int)$review['attitude_rating'],5,'production attitude rating is five');
    autoReviewMysqlAssert((int)$review['craft_rating'],5,'production craft rating is five');
    autoReviewMysqlAssert((int)$review['is_auto'],1,'production review marked automatic');
    autoReviewMysqlAssert((string)$db->fetchValue("SELECT status FROM order_deadlines WHERE order_id=1 AND deadline_type='auto_review_order'"),'completed','production auto-review deadline completed');
    autoReviewMysqlAssert((string)$db->fetchValue("SELECT status FROM scheduled_job_claims WHERE job_name='auto_review_order' AND business_key='order:1' AND business_version=7"),'completed','production auto review claim completed');
    autoReviewMysqlAssert($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE job_name='auto_review_order' AND business_key='order:1'")!==null,true,'production auto review failure resolved');
    autoReviewMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_key='scheduler:auto_review_order:order:1:v7'"),1,'production deterministic auto-review event written');
    autoReviewMysqlAssert(count(OrderNotificationService::$queued),1,'production auto-review notification queued once');
    autoReviewMysqlAssert((int)$db->fetchValue('SELECT review_count FROM rating_summaries WHERE worker_id=201'),2,'rating summary matches visible reviews');
    autoReviewMysqlAssert((string)$db->fetchValue('SELECT rating_average FROM rating_summaries WHERE worker_id=201'),'4.50','production rating average recomputed');
    autoReviewMysqlAssert((string)$db->fetchValue('SELECT rating FROM worker_profiles WHERE id=201'),'4.50','worker profile rating projection recomputed');
    autoReviewMysqlAssert($processor->reviewProduction(1,7,9001),false,'production auto review re-run is idempotent');

    $seed($db,2,'BXAUTO-REVIEW-EXISTS',102,202,false,true);
    $db->execute(
        "INSERT INTO reviews(public_id,order_id,user_id,worker_id,attitude_rating,craft_rating,content,is_auto,status,reviewed_at,created_at,updated_at)
         VALUES('EXISTING-ORDER-2',2,102,202,3,4,'已有评价',0,'visible',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    autoReviewMysqlAssert($processor->reviewProduction(2,7,9002),false,'existing review blocks production auto review');
    autoReviewMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:2'"),0,'existing review creates no production claim');

    $seed($db,101,'BXAUTO-REVIEW-TEST-A',301,401,true,false);
    $seed($db,102,'BXAUTO-REVIEW-TEST-B',302,402,true,false);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES
         (11,'ordinary.construction.multi_quote.waiting.zero_quotes',101,'BXAUTO-REVIEW-TEST-A','built'),
         (12,'ordinary.measurement.multi_quote.waiting.zero_quotes',102,'BXAUTO-REVIEW-TEST-B','built')"
    );
    $testResult=$processor->reviewTestInstance(11,true);
    autoReviewMysqlAssert(!empty($testResult['reviewed']),true,'scoped auto review succeeds with deadline clock fixture');
    autoReviewMysqlAssert((string)$testResult['scope'],'test_instance','scoped auto review declares instance scope');
    autoReviewMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM reviews WHERE order_id=101 AND is_auto=1 AND attitude_rating=5 AND craft_rating=5"),1,'target test order receives automatic five-star review');
    autoReviewMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM reviews WHERE order_id=102"),0,'neighbor test order remains without review');
    autoReviewMysqlAssert((string)$db->fetchValue("SELECT status FROM order_deadlines WHERE order_id=101 AND deadline_type='auto_review_order'"),'completed','target test auto-review deadline completed');
    autoReviewMysqlAssert((string)$db->fetchValue("SELECT status FROM order_deadlines WHERE order_id=102 AND deadline_type='auto_review_order'"),'pending','neighbor test auto-review deadline remains pending');
    autoReviewMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:101'"),0,'scoped auto review creates no global scheduler claim');
    autoReviewMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_key='test-auto-review:11:auto_review_order:v7'"),1,'scoped auto review event is instance-keyed');
    autoReviewMysqlAssert(count(OrderNotificationService::$persisted),1,'scoped auto review uses persisted event notification');
    autoReviewMysqlAssert((int)$db->fetchValue('SELECT review_count FROM rating_summaries WHERE worker_id=401'),1,'scoped rating summary matches visible reviews');

    $seed($db,103,'BXAUTO-REVIEW-BLOCKED',303,403,true,false);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(13,'ordinary.construction.single_grab.waiting.open',103,'BXAUTO-REVIEW-BLOCKED','built')");
    $db->execute("UPDATE orders SET dispute_status='open' WHERE id=103");
    $blocked=false;
    try{$processor->reviewTestInstance(13,true);}catch(\RuntimeException $e){$blocked=true;}
    autoReviewMysqlAssert($blocked,true,'blocking dispute prevents scoped auto review');
    autoReviewMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM reviews WHERE order_id=103'),0,'blocked scoped auto review creates no review');

    $seed($db,104,'BXDEMOHALL-AUTO-REVIEW',304,404,true,false);
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(14,'ordinary.construction.multi_quote.waiting.zero_quotes',104,'BXDEMOHALL-AUTO-REVIEW','built')");
    $blocked=false;
    try{$processor->reviewTestInstance(14,true);}catch(\RuntimeException $e){$blocked=true;}
    autoReviewMysqlAssert($blocked,true,'review hall sample is blocked from scoped auto review');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 auto review processor MySQL 5.7 integration: OK\n";
}

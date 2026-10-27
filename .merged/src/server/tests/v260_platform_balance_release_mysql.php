<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\PlatformOrderFrozenBalanceReleaser;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Persistence/PlatformOrderFrozenBalanceReleaser.php';

    function pbrSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_PLATFORM_BALANCE_RELEASE_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    pbrSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_platform_balance_release_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            version INT NOT NULL DEFAULT 1,
            public_no VARCHAR(80) NOT NULL,
            order_source_type VARCHAR(24) NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 0,
            lifecycle_status VARCHAR(40) NOT NULL,
            fulfillment_status VARCHAR(40) NOT NULL,
            accepted_at DATETIME NULL,
            assigned_worker_id BIGINT UNSIGNED NOT NULL,
            dispute_status VARCHAR(24) NOT NULL DEFAULT 'none',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none'
        ) ENGINE=InnoDB",
        "CREATE TABLE order_platform_terms(
            order_id BIGINT UNSIGNED PRIMARY KEY,
            worker_settlement_mode VARCHAR(24) NOT NULL,
            settlement_status VARCHAR(32) NOT NULL,
            worker_balance_credit_cents BIGINT UNSIGNED NOT NULL,
            worker_balance_freeze_days_snapshot INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            worker_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(48) NOT NULL,
            direction VARCHAR(16) NOT NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            available_delta_cents BIGINT NOT NULL,
            frozen_delta_cents BIGINT NOT NULL,
            business_type VARCHAR(32) NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(180) NOT NULL,
            description VARCHAR(255) NULL,
            status VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_ledger_key(idempotency_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE order_events(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(70) NOT NULL,
            event_key VARCHAR(128) NOT NULL,
            actor_type VARCHAR(24) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            payload_json TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_event_key(event_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE scheduled_job_failures(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(80) NOT NULL,
            business_key VARCHAR(120) NOT NULL,
            business_version INT NOT NULL,
            resolved_at DATETIME NULL,
            next_retry_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_order_id BIGINT UNSIGNED NULL,order_id BIGINT UNSIGNED NULL,status VARCHAR(24) NOT NULL,failure_code VARCHAR(64) NULL) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE after_sales(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
            id BIGINT UNSIGNED PRIMARY KEY,
            scenario_key VARCHAR(128) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_no_snapshot VARCHAR(80) NOT NULL,
            build_status VARCHAR(24) NOT NULL,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB"
    ]as$sql)$db->pdo()->exec($sql);

    $seed=static function(
        Database $db,
        int $id,
        bool $demo,
        int $acceptedDaysAgo,
        int $freezeDays=3,
        int $credit=70000,
        int $ledgerAmount=70000,
        string $publicNo=''
    ):void{
        if($publicNo==='')$publicNo=($demo?'BXTEST-PBR-':'BX-PBR-').$id;
        $db->execute(
            "INSERT INTO orders(
                id,version,public_no,order_source_type,is_demo,lifecycle_status,fulfillment_status,
                accepted_at,assigned_worker_id,dispute_status,refund_status
             ) VALUES(
                :id,7,:no,'platform',:demo,'completed','accepted',
                DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$acceptedDaysAgo} DAY),:worker,'none','none'
             )",
            ['id'=>$id,'no'=>$publicNo,'demo'=>$demo?1:0,'worker'=>$id+1000]
        );
        $db->execute(
            "INSERT INTO order_platform_terms(
                order_id,worker_settlement_mode,settlement_status,worker_balance_credit_cents,worker_balance_freeze_days_snapshot
             ) VALUES(:id,'platform_balance','awaiting_platform_income',:credit,:freeze)",
            ['id'=>$id,'credit'=>$credit,'freeze'=>$freezeDays]
        );
        $db->execute(
            "INSERT INTO worker_balance_ledger(
                worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                business_type,business_id,idempotency_key,description,status,created_at
             ) VALUES(
                :worker,'platform_order_income_frozen','credit',:amount,0,:frozen,
                'platform_order',:id,:key,'测试冻结工费','effective',UTC_TIMESTAMP()
             )",
            [
                'worker'=>$id+1000,
                'amount'=>$ledgerAmount,
                'frozen'=>$ledgerAmount,
                'id'=>$id,
                'key'=>'platform-order:'.$id.':worker-income',
            ]
        );
    };

    $releaser=new PlatformOrderFrozenBalanceReleaser();

    // production single-order release.
    $seed($db,1,false,5);
    $db->execute(
        "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,resolved_at,next_retry_at)
         VALUES('platform_balance_release','order:1',7,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))"
    );
    pbrSame($releaser->releaseOne($db,1),true,'production due platform balance releases');
    $sum=$db->fetch(
        "SELECT SUM(available_delta_cents) available,SUM(frozen_delta_cents) frozen
         FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=1"
    );
    pbrSame((int)$sum['available'],70000,'production release increases available balance');
    pbrSame((int)$sum['frozen'],0,'production release clears frozen balance');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_type='platform_worker_income_released' AND event_key='platform-order:1:worker-income-release'"),1,'production release event persisted');
    pbrSame($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE business_key='order:1'")!==null,true,'production release resolves scheduler failure');
    pbrSame($releaser->releaseOne($db,1),false,'production release is idempotent');

    // production blocking business.
    $seed($db,2,false,5);
    $db->execute("INSERT INTO disputes(order_id,status) VALUES(2,'processing')");
    pbrSame($releaser->releaseOne($db,2),false,'production dispute blocks release');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=2 AND entry_type='platform_order_income_release'"),0,'blocked production release writes no transfer');

    // bad source ledger raises hard error and rolls back.
    $seed($db,3,false,5,3,70000,69999);
    $failed=false;
    try{$releaser->releaseOne($db,3);}catch(RuntimeException $e){$failed=true;}
    pbrSame($failed,true,'mismatched production frozen ledger fails hard');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=3 AND entry_type='platform_order_income_release'"),0,'mismatched ledger writes no release');

    // scoped A/B: actual clock is before deadline, fixture only advances A.
    $seed($db,101,true,0);
    $seed($db,102,true,0);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES
         (11,'platform.measurement.single_grab.waiting.balance',101,'BXTEST-PBR-101','built'),
         (12,'platform.measurement.single_grab.waiting.balance',102,'BXTEST-PBR-102','built')"
    );
    $db->execute(
        "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,resolved_at,next_retry_at)
         VALUES('platform_balance_release','order:101',7,NULL,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))"
    );

    $before=$releaser->releaseTestInstance($db,11,false);
    pbrSame((bool)$before['released'],false,'scoped platform balance does not release before deadline without fixture');

    $scoped=$releaser->releaseTestInstance($db,11,true);
    pbrSame((bool)$scoped['released'],true,'scoped platform balance releases with deadline clock fixture');
    pbrSame((string)$scoped['scope'],'test_instance','scoped platform balance declares instance scope');
    pbrSame((bool)$scoped['global_scheduler_scan_verified'],false,'scoped release does not claim global scan verification');
    $sumA=$db->fetch(
        "SELECT SUM(available_delta_cents) available,SUM(frozen_delta_cents) frozen
         FROM worker_balance_ledger WHERE business_type='platform_order' AND business_id=101"
    );
    pbrSame((int)$sumA['available'],70000,'scoped target available balance increases');
    pbrSame((int)$sumA['frozen'],0,'scoped target frozen balance clears');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=102 AND entry_type='platform_order_income_release'"),0,'neighbor test instance remains frozen');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_key='test-platform-balance-release:11:101'"),1,'scoped release event is instance keyed');
    pbrSame($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE business_key='order:101'"),null,'scoped release does not resolve global scheduler failure');

    // scoped blocker still applies.
    $seed($db,103,true,0);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(13,'platform.measurement.single_grab.waiting.balance',103,'BXTEST-PBR-103','built')"
    );
    $db->execute("INSERT INTO after_sales(order_id,status) VALUES(103,'open')");
    $blocked=$releaser->releaseTestInstance($db,13,true);
    pbrSame((bool)$blocked['released'],false,'open after-sales blocks scoped platform balance release');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=103 AND entry_type='platform_order_income_release'"),0,'blocked scoped release writes no transfer');

    // review hall is permanently protected.
    $seed($db,104,true,0,3,70000,70000,'BXDEMOHALL-PBR-104');
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(14,'platform.measurement.single_grab.waiting.balance',104,'BXDEMOHALL-PBR-104','built')"
    );
    $blockedHall=false;
    try{$releaser->releaseTestInstance($db,14,true);}catch(RuntimeException $e){$blockedHall=true;}
    pbrSame($blockedHall,true,'review hall platform balance fixture is protected');

    // global scanner processes only non-demo candidates.
    $seed($db,4,false,5);
    $seed($db,105,true,5);
    $failures=[];
    $stats=$releaser->run($db,100,static function(int $id,int $version,Throwable $e)use(&$failures):void{$failures[$id]=$e->getMessage();});
    pbrSame((int)$stats['success'],1,'global scanner releases one remaining production candidate');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=4 AND entry_type='platform_order_income_release'"),1,'global scanner releases production order');
    pbrSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=105 AND entry_type='platform_order_income_release'"),0,'global scanner excludes demo order');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 platform balance release MySQL 5.7 integration: OK\n";
}

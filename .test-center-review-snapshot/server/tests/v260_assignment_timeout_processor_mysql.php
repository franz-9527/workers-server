<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
}

namespace WorkerAdmin\Services {
    final class OrderNotificationService
    {
        public static $calls=[];
        public static function queue($db,int $orderId,string $eventKey,string $eventType,array $payload): void
        {
            self::$calls[]=['order_id'=>$orderId,'event_key'=>$eventKey,'event_type'=>$eventType,'payload'=>$payload];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Scheduling\AssignmentTimeoutProcessor;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/AssignmentTimeoutProcessor.php';

    function assignmentMysqlAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_ASSIGNMENT_TIMEOUT_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    assignmentMysqlAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_assignment_timeout_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(40) NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 0,
            lifecycle_status VARCHAR(24) NOT NULL,
            assignment_status VARCHAR(24) NOT NULL,
            publish_status VARCHAR(24) NOT NULL,
            quote_status VARCHAR(24) NOT NULL,
            assigned_worker_id BIGINT UNSIGNED NULL,
            current_assignment_id BIGINT UNSIGNED NULL,
            selected_quote_id BIGINT UNSIGNED NULL,
            contact_revealed_at DATETIME NULL,
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            assignment_type VARCHAR(24) NOT NULL,
            status VARCHAR(24) NOT NULL,
            offered_at DATETIME NOT NULL,
            accept_deadline_at DATETIME NULL,
            rejected_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignment_history(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            assignment_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            actor_type VARCHAR(24) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(
            id BIGINT UNSIGNED PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            revoked_at DATETIME NULL
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
    ];
    foreach($ddl as $sql)$db->pdo()->exec($sql);

    $seedTest=static function(Database $db,int $instanceId,int $orderId,int $assignmentId,int $workerId,string $publicNo):void{
        $db->execute(
            "INSERT INTO orders(id,public_no,is_demo,lifecycle_status,assignment_status,publish_status,quote_status,assigned_worker_id,current_assignment_id,selected_quote_id,version,updated_at)
             VALUES(:id,:no,1,'active','offered','hidden','waiting',:worker,:assignment,NULL,7,UTC_TIMESTAMP())",
            ['id'=>$orderId,'no'=>$publicNo,'worker'=>$workerId,'assignment'=>$assignmentId]
        );
        $db->execute(
            "INSERT INTO assignments(id,order_id,worker_id,assignment_type,status,offered_at,accept_deadline_at,updated_at)
             VALUES(:id,:order,:worker,'single_grab','offered',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),UTC_TIMESTAMP())",
            ['id'=>$assignmentId,'order'=>$orderId,'worker'=>$workerId]
        );
        $db->execute(
            "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
             VALUES(:id,'ordinary.construction.single_grab.waiting.negotiated',:order,:no,'built')",
            ['id'=>$instanceId,'order'=>$orderId,'no'=>$publicNo]
        );
        $db->execute("INSERT INTO worker_profiles(id,user_id) VALUES(:worker,:user)",['worker'=>$workerId,'user'=>$workerId+1000]);
        $db->execute("INSERT INTO order_role_grants(order_id,user_id,status) VALUES(:order,:user,'active')",['order'=>$orderId,'user'=>$workerId+1000]);
    };

    $seedTest($db,1,101,1001,201,'BXTESTA');
    $seedTest($db,2,102,1002,202,'BXTESTB');

    $processor=new AssignmentTimeoutProcessor($db);
    $result=$processor->expireTestInstance(1,true);
    assignmentMysqlAssert(!empty($result['expired']),true,'target test assignment expired with scoped clock fixture');
    assignmentMysqlAssert((string)$result['scope'],'test_instance','result states test-instance scope');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT status FROM assignments WHERE id=1001'),'expired','target assignment status expired');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT assignment_status FROM orders WHERE id=101'),'unassigned','target order assignment released');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=101'),'published','single-grab order republished');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT quote_status FROM orders WHERE id=101'),'waiting','single-grab order returns to waiting');
    assignmentMysqlAssert($db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=101'),null,'current assignment pointer cleared');
    assignmentMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM assignment_history WHERE assignment_id=1001 AND action='expired' AND actor_type='system'"),1,'expiry history written once');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT status FROM order_role_grants WHERE order_id=101'),'revoked','worker role grant revoked');
    assignmentMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_type='assignment_expired'"),1,'deterministic scheduler event written');
    assignmentMysqlAssert(count(OrderNotificationService::$calls),1,'notification projection invoked once');

    assignmentMysqlAssert((string)$db->fetchValue('SELECT status FROM assignments WHERE id=1002'),'offered','neighbor test assignment remains offered');
    assignmentMysqlAssert((int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=102'),1002,'neighbor order pointer remains unchanged');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT status FROM order_role_grants WHERE order_id=102'),'active','neighbor role grant remains active');

    $db->execute(
        "INSERT INTO orders(id,public_no,is_demo,lifecycle_status,assignment_status,publish_status,quote_status,assigned_worker_id,current_assignment_id,version,updated_at)
         VALUES(103,'BXPROD',0,'active','offered','hidden','waiting',203,1003,4,UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO assignments(id,order_id,worker_id,assignment_type,status,offered_at,accept_deadline_at,updated_at)
         VALUES(1003,103,203,'admin','offered',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 HOUR),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 HOUR),UTC_TIMESTAMP())"
    );
    $db->execute("INSERT INTO worker_profiles(id,user_id) VALUES(203,1203)");
    $db->execute("INSERT INTO order_role_grants(order_id,user_id,status) VALUES(103,1203,'active')");
    assignmentMysqlAssert($processor->expireProduction(1003),true,'production entry still expires overdue non-demo assignment');
    assignmentMysqlAssert((string)$db->fetchValue('SELECT status FROM assignments WHERE id=1003'),'expired','production assignment expired');
    assignmentMysqlAssert($processor->expireProduction(1003),false,'production re-run is idempotent after assignment leaves offered');

    $db->execute(
        "INSERT INTO orders(id,public_no,is_demo,lifecycle_status,assignment_status,publish_status,quote_status,assigned_worker_id,current_assignment_id,version,updated_at)
         VALUES(104,'BXDEMOHALL99',1,'active','offered','hidden','waiting',204,1004,1,UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO assignments(id,order_id,worker_id,assignment_type,status,offered_at,accept_deadline_at,updated_at)
         VALUES(1004,104,204,'single_grab','offered',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(4,'ordinary.construction.single_grab.waiting.negotiated',104,'BXDEMOHALL99','built')"
    );
    $blocked=false;
    try{$processor->expireTestInstance(4,true);}catch(\RuntimeException $exception){$blocked=true;}
    assignmentMysqlAssert($blocked,true,'review hall sample is blocked from scoped timeout');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 assignment timeout processor MySQL 5.7 integration: OK\n";
}

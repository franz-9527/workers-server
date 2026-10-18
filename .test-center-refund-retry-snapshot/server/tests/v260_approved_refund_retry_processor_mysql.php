<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Scheduling\ApprovedOrderRefundRetryProcessor;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php';

    function rrSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_REFUND_RETRY_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    rrSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_refund_retry_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            lifecycle_status VARCHAR(48) NOT NULL,
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
            is_demo TINYINT NOT NULL DEFAULT 0,
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            status VARCHAR(24) NOT NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            api_version VARCHAR(8) NOT NULL DEFAULT 'v3',
            app_id VARCHAR(80) NULL,
            mch_id VARCHAR(80) NULL,
            payer_openid VARCHAR(160) NULL,
            wechat_prepay_id VARCHAR(128) NULL,
            wechat_transaction_id VARCHAR(128) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED PRIMARY KEY,
            order_id BIGINT UNSIGNED NULL,
            status VARCHAR(24) NOT NULL,
            reason_code VARCHAR(64) NOT NULL DEFAULT '',
            closed_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE disputes(
            id BIGINT UNSIGNED PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            handled_by BIGINT UNSIGNED NULL,
            refund_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            resolved_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            cancel_request_id BIGINT UNSIGNED NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            status VARCHAR(24) NOT NULL,
            requested_by_admin_id BIGINT UNSIGNED NULL,
            requested_at DATETIME NULL,
            wechat_refund_id VARCHAR(128) NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
            id BIGINT UNSIGNED PRIMARY KEY,
            scenario_key VARCHAR(128) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_no_snapshot VARCHAR(80) NOT NULL,
            build_status VARCHAR(24) NOT NULL,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id BIGINT UNSIGNED NOT NULL,
            payment_order_id BIGINT UNSIGNED NOT NULL,
            simulated_provider VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB"
    ]as$sql)$db->pdo()->exec($sql);

    $seed=static function(Database $db,int $id,bool $demo,string $refundStatus='pending_review',string $lifecycle='cancel_approved_pending_refund'):void{
        $db->execute(
            "INSERT INTO orders(id,public_no,lifecycle_status,refund_status,is_demo,version,updated_at)
             VALUES(:id,:no,:lifecycle,:refund_status,:demo,1,UTC_TIMESTAMP())",
            ['id'=>$id,'no'=>'OD-RR-'.$id,'lifecycle'=>$lifecycle,'refund_status'=>$refundStatus,'demo'=>$demo?1:0]
        );
        $db->execute(
            "INSERT INTO payment_orders(
                id,public_no,status,amount_cents,api_version,app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id
             ) VALUES(
                :id,:no,'paid',5000,'v3',:app,:mch,:openid,:prepay,:txn
             )",
            [
                'id'=>$id,'no'=>'PAY-RR-'.$id,
                'app'=>$demo?'TEST_INTERNAL':'wx-app',
                'mch'=>$demo?'TEST_INTERNAL':'mch-real',
                'openid'=>$demo?('TEST_INTERNAL:'.$id.':'.$id):'openid-real',
                'prepay'=>$demo?null:'prepay-real',
                'txn'=>$demo?null:'txn-real',
            ]
        );
    };
    $processor=new ApprovedOrderRefundRetryProcessor($db);

    $seed($db,1,false);
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(101,1,'approved','admin_approved',NULL)");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(1,'RF-RR-CANCEL',1,1,101,5000,'取消退款','pending_review',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $submitted=[];
    $submit=static function(array $row)use(&$submitted):array{$submitted[]=$row['public_no'];return['refund_id'=>'WX-RR-CANCEL'];};
    rrSame(count($processor->productionCandidates(100)),1,'production candidate scanner finds approved cancellation refund');
    rrSame($processor->executeProduction(1,$submit),true,'production approved cancellation refund submitted');
    rrSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=1'),'processing','production refund enters processing');
    rrSame((string)$db->fetchValue('SELECT wechat_refund_id FROM refunds WHERE id=1'),'WX-RR-CANCEL','production provider refund id persisted');
    rrSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=1'),'processing','production order refund status enters processing');
    rrSame($submitted,['RF-RR-CANCEL'],'production uses original out_refund_no');

    $seed($db,2,false);
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(102,2,'approved','admin_approved',NULL)");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(2,'RF-RR-FAIL',2,2,102,3000,'网络失败退款','pending_review',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $failed=false;
    try{$processor->executeProduction(2,static function(array $row):array{throw new RuntimeException('provider timeout');});}
    catch(RuntimeException $e){$failed=true;}
    rrSame($failed,true,'production provider failure surfaces');
    rrSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=2'),'pending_review','claimed pending_review restores after provider failure');
    $submitted=[];
    rrSame($processor->executeProduction(2,$submit),true,'production failed refund retries on next pass');
    rrSame($submitted,['RF-RR-FAIL'],'production retry reuses same out_refund_no');

    $seed($db,3,false,'approved');
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(3,'RF-RR-APPROVED',3,3,NULL,1500,'已批准退款','approved',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $failed=false;
    try{$processor->executeProduction(3,static function(array $row):array{throw new RuntimeException('provider timeout');});}
    catch(RuntimeException $e){$failed=true;}
    rrSame($failed,true,'originally approved provider failure surfaces');
    rrSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=3'),'approved','originally approved refund remains approved after failure');

    $seed($db,4,false);
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(104,4,'processing','bilateral_refund_execution_prepared',NULL)");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(4,'RF-RR-AGREED',4,4,104,2600,'双方协议退款','created',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $failed=false;
    try{$processor->executeProduction(4,static function(array $row):array{throw new RuntimeException('provider timeout');});}
    catch(RuntimeException $e){$failed=true;}
    rrSame($failed,true,'prepared agreed refund provider failure surfaces');
    rrSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=4'),'created','prepared agreed refund restores created after failure');

    $seed($db,5,false);
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(5,'RF-RR-MANUAL',5,5,NULL,1000,'普通人工退款','pending_review',77,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $called=0;
    rrSame($processor->executeProduction(5,static function(array $row)use(&$called):array{$called++;return[];}),false,'ordinary pending_review is ineligible');
    rrSame($called,0,'ineligible refund never calls provider');

    $seed($db,101,true);
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(201,101,'approved','auto_timeout',NULL)");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(101,'RF-RR-TEST-A',101,101,201,5000,'测试取消退款','pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(11,'ordinary.construction.multi_quote.waiting.zero_quotes',101,'OD-RR-101','built')"
    );
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(11,101,'internal_test')");
    $inspect=$processor->inspectTestInstance(11);
    rrSame((int)($inspect['refund_id']??0),101,'scoped inspector finds unique owned retry refund');
    $probe=$processor->probeTestInstance(11);
    rrSame((string)$probe['provider_simulation'],'network_timeout_before_acceptance','scoped probe simulates provider pre-accept timeout');
    rrSame((bool)$probe['retryable'],true,'scoped probe remains retryable');
    rrSame((bool)$probe['external_refund_executed'],false,'scoped probe never executes external refund');
    rrSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=101'),'pending_review','scoped probe restores pending_review');
    rrSame($db->fetchValue('SELECT wechat_refund_id FROM refunds WHERE id=101'),null,'scoped probe leaves wechat_refund_id empty');
    rrSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=101'),'pending_review','scoped probe does not fake order processing');

    $seed($db,102,true);
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(202,102,'approved','auto_timeout',NULL)");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(102,'RF-RR-TEST-B',102,102,202,5000,'邻居测试退款','pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(12,'ordinary.construction.multi_quote.waiting.zero_quotes',102,'OD-RR-102','built')"
    );
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(12,102,'internal_test')");
    rrSame((string)$db->fetchValue('SELECT status FROM refunds WHERE id=102'),'pending_review','neighbor refund remains unchanged');

    $seed($db,103,true);
    $db->execute("UPDATE payment_orders SET app_id='wx-real',mch_id='mch-real',payer_openid='openid-real' WHERE id=103");
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(203,103,'approved','auto_timeout',NULL)");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES(103,'RF-RR-UNOWNED',103,103,203,5000,'外部付款退款','pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(13,'ordinary.construction.multi_quote.waiting.zero_quotes',103,'OD-RR-103','built')"
    );
    rrSame($processor->inspectTestInstance(13),null,'unowned testing payment is not exposed by inspector');
    $blocked=false;try{$processor->probeTestInstance(13);}catch(RuntimeException $e){$blocked=true;}
    rrSame($blocked,true,'unowned testing payment blocks scoped probe');

    $seed($db,104,true);
    $db->execute("INSERT INTO order_cancel_requests(id,order_id,status,reason_code,closed_at) VALUES(204,104,'approved','auto_timeout',NULL)");
    $db->execute("INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status) VALUES(14,'ordinary.construction.multi_quote.waiting.zero_quotes',104,'OD-RR-104','built')");
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(14,104,'internal_test')");
    $db->execute(
        "INSERT INTO refunds(id,public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,updated_at)
         VALUES
         (104,'RF-RR-MULTI-A',104,104,204,1000,'多退款A','pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
         (105,'RF-RR-MULTI-B',104,104,204,1000,'多退款B','pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    rrSame($processor->inspectTestInstance(14),null,'multiple eligible refunds are not exposed');
    $blocked=false;try{$processor->probeTestInstance(14);}catch(RuntimeException $e){$blocked=true;}
    rrSame($blocked,true,'multiple eligible refunds fail closed');

    $productionIds=array_map('intval',array_column($processor->productionCandidates(100),'id'));
    rrSame(in_array(101,$productionIds,true),false,'production candidate scanner excludes demo refund');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 approved refund retry processor MySQL 5.7 integration: OK\n";
}

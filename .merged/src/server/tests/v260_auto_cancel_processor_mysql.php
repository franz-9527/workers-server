<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function public_id(){static $i=0;$i++;return 'AUTOCANCEL'.str_pad((string)$i,16,'0',STR_PAD_LEFT);}
    function business_no($prefix){static $i=0;$i++;return strtoupper((string)$prefix).gmdate('ymd').str_pad((string)$i,8,'0',STR_PAD_LEFT);}
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
    use WorkerAdmin\Infrastructure\Scheduling\AutoCancelProcessor;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/AutoCancelProcessor.php';

    function autoCancelMysqlAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    if(getenv('V260_AUTO_CANCEL_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    autoCancelMysqlAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'MySQL 5.7 connected');

    $dbName='worker_v260_auto_cancel_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 0,
            order_source_type VARCHAR(24) NOT NULL DEFAULT 'consumer',
            workflow_profile VARCHAR(24) NOT NULL DEFAULT 'construction',
            audit_status VARCHAR(24) NOT NULL DEFAULT 'approved',
            publish_status VARCHAR(24) NOT NULL DEFAULT 'published',
            quote_status VARCHAR(24) NOT NULL DEFAULT 'waiting',
            assignment_status VARCHAR(24) NOT NULL DEFAULT 'unassigned',
            fulfillment_status VARCHAR(24) NOT NULL DEFAULT 'not_started',
            payment_status VARCHAR(24) NOT NULL DEFAULT 'paid',
            refund_status VARCHAR(24) NOT NULL DEFAULT 'none',
            lifecycle_status VARCHAR(40) NOT NULL DEFAULT 'active',
            booking_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            published_at DATETIME NULL,
            quote_deadline_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            closed_at DATETIME NULL,
            version INT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE quotes(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            business_type VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            app_id VARCHAR(80) NULL,
            mch_id VARCHAR(80) NULL,
            payer_openid VARCHAR(160) NULL,
            wechat_prepay_id VARCHAR(128) NULL,
            wechat_transaction_id VARCHAR(128) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id BIGINT UNSIGNED NOT NULL,
            payment_order_id BIGINT UNSIGNED NOT NULL,
            simulated_provider VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            cancel_request_id BIGINT UNSIGNED NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status VARCHAR(24) NOT NULL,
            requested_by_admin_id BIGINT UNSIGNED NULL,
            wechat_refund_id VARCHAR(128) NULL,
            requested_at DATETIME NULL,
            succeeded_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE disputes(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_type VARCHAR(48) NOT NULL,
            business_id BIGINT UNSIGNED NOT NULL,
            entry_type VARCHAR(48) NOT NULL,
            status VARCHAR(24) NOT NULL,
            amount_cents BIGINT NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            applicant_type VARCHAR(24) NOT NULL,
            applicant_id BIGINT UNSIGNED NULL,
            request_type VARCHAR(48) NOT NULL,
            order_state_snapshot_json LONGTEXT NOT NULL,
            reason_code VARCHAR(64) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status VARCHAR(24) NOT NULL,
            requested_refund_cents BIGINT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_decisions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cancel_request_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(24) NOT NULL,
            responsibility VARCHAR(48) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            refund_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            run_empty_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            refund_id BIGINT UNSIGNED NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE quote_selections(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            released_at DATETIME NULL,
            payment_expires_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE assignments(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            assignment_type VARCHAR(32) NOT NULL,
            status VARCHAR(24) NOT NULL,
            accept_deadline_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            revoked_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            deadline_type VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL,
            processed_at DATETIME NULL,
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
        "CREATE TABLE notifications(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL,
            recipient_type VARCHAR(24) NOT NULL,
            recipient_id BIGINT UNSIGNED NOT NULL,
            channel VARCHAR(24) NOT NULL,
            template_key VARCHAR(64) NOT NULL,
            title VARCHAR(255) NOT NULL,
            content VARCHAR(1000) NOT NULL,
            data_json LONGTEXT NOT NULL,
            status VARCHAR(24) NOT NULL,
            scheduled_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL
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
        ) ENGINE=InnoDB"
    ];
    foreach($ddl as$sql)$db->pdo()->exec($sql);

    $seedOrder=static function(
        Database $db,
        int $id,
        string $no,
        int $user,
        bool $demo,
        int $booking,
        bool $due=true
    ):void{
        $db->execute(
            "INSERT INTO orders(
                id,public_no,user_id,is_demo,order_source_type,workflow_profile,audit_status,
                publish_status,quote_status,assignment_status,fulfillment_status,payment_status,
                refund_status,lifecycle_status,booking_fee_cents,published_at,quote_deadline_at,
                version,updated_at
             ) VALUES(
                :id,:no,:user,:demo,'consumer','construction','approved',
                'published','waiting','unassigned','not_started',:payment,
                'none','active',:booking,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 8 DAY),
                ".($due?"DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)":"DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY)").",
                7,UTC_TIMESTAMP()
             )",
            [
                'id'=>$id,'no'=>$no,'user'=>$user,'demo'=>$demo?1:0,'booking'=>$booking,
                'payment'=>$booking>0?'paid':'not_required',
            ]
        );
        $db->execute(
            "INSERT INTO order_deadlines(order_id,deadline_type,status,updated_at)
             VALUES(:id,'auto_cancel_unaccepted','pending',UTC_TIMESTAMP())",
            ['id'=>$id]
        );
    };

    $seedPayment=static function(Database $db,int $orderId,int $amount,bool $internal=false,?int $instanceId=null):int{
        $payment=$db->insert(
            "INSERT INTO payment_orders(
                public_no,order_id,business_type,status,amount_cents,
                app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id
             ) VALUES(
                :no,:order_id,'order_booking','paid',:amount,
                :app,:mch,:openid,:prepay,:txn
             )",
            [
                'no'=>'PAY-'.$orderId,
                'order_id'=>$orderId,
                'amount'=>$amount,
                'app'=>$internal?'TEST_INTERNAL':'wx-app',
                'mch'=>$internal?'TEST_INTERNAL':'mch-real',
                'openid'=>$internal?('TEST_INTERNAL:'.$instanceId.':'.$orderId):'openid-real',
                'prepay'=>$internal?null:'wx-prepay',
                'txn'=>$internal?null:'wx-transaction',
            ]
        );
        if($internal&&$instanceId!==null){
            $db->execute(
                "INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider)
                 VALUES(:instance,:payment,'internal_test')",
                ['instance'=>$instanceId,'payment'=>$payment]
            );
        }
        return$payment;
    };

    $processor=new AutoCancelProcessor($db);

    // 生产：无报价、实收预约费，创建待退款取消链并完成 claim。
    $seedOrder($db,1,'BX-AUTO-CANCEL-PROD-1',101,false,5000,true);
    $seedPayment($db,1,5000,false,null);
    $db->execute(
        "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,next_retry_at)
         VALUES('auto_cancel_unaccepted','order:1',7,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))"
    );
    autoCancelMysqlAssert($processor->cancelProduction(1,7,9001),true,'production due order auto-cancelled');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1'),'cancel_approved_pending_refund','production paid order waits for refund');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=1'),'hidden','production order hidden');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT quote_status FROM orders WHERE id=1'),'expired','production quote state expired');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT reason_code FROM order_cancel_requests WHERE order_id=1'),'seven_days_no_valid_quote','production no-quote reason recorded');
    autoCancelMysqlAssert((int)$db->fetchValue('SELECT requested_refund_cents FROM order_cancel_requests WHERE order_id=1'),5000,'production refund request matches booking cap');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT status FROM refunds WHERE order_id=1'),'pending_review','production auto refund starts pending_review');
    autoCancelMysqlAssert((string)$db->fetchValue("SELECT status FROM scheduled_job_claims WHERE job_name='auto_cancel_unaccepted' AND business_key='order:1' AND business_version=7"),'completed','production auto cancel claim completed');
    autoCancelMysqlAssert($db->fetchValue("SELECT resolved_at FROM scheduled_job_failures WHERE job_name='auto_cancel_unaccepted' AND business_key='order:1'")!==null,true,'production auto cancel failure resolved');
    autoCancelMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=1 AND event_key='scheduler:auto_cancel_unaccepted:order:1:v7'"),1,'production deterministic auto-cancel event written');
    autoCancelMysqlAssert(count(OrderNotificationService::$queued),1,'production auto-cancel notification queued');
    autoCancelMysqlAssert($processor->cancelProduction(1,7,9001),false,'production auto cancel re-run is idempotent');

    // 生产：仍有有效报价但未接受，原因应区分并统一失效报价。
    $seedOrder($db,2,'BX-AUTO-CANCEL-PROD-2',102,false,3000,true);
    $seedPayment($db,2,3000,false,null);
    $db->execute("INSERT INTO quotes(order_id,status,updated_at) VALUES(2,'active',UTC_TIMESTAMP())");
    autoCancelMysqlAssert($processor->cancelProduction(2,7,9002),true,'production unaccepted quote order auto-cancelled');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT reason_code FROM order_cancel_requests WHERE order_id=2'),'seven_days_unaccepted_quote','production active quote uses unaccepted reason');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT status FROM quotes WHERE order_id=2'),'expired','production active quote expired');

    // 生产：已成交 assignment 不得取消，不得 claim。
    $seedOrder($db,3,'BX-AUTO-CANCEL-PROD-3',103,false,3000,true);
    $seedPayment($db,3,3000,false,null);
    $db->execute(
        "INSERT INTO assignments(order_id,assignment_type,status,updated_at)
         VALUES(3,'worker_quote','accepted',UTC_TIMESTAMP())"
    );
    autoCancelMysqlAssert($processor->cancelProduction(3,7,9003),false,'accepted assignment blocks production auto cancel');
    autoCancelMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:3'"),0,'blocked production order creates no claim');

    // 生产：无付款、无预约费可直接取消关闭。
    $seedOrder($db,4,'BX-AUTO-CANCEL-PROD-4',104,false,0,true);
    autoCancelMysqlAssert($processor->cancelProduction(4,7,9004),true,'zero-booking production order auto-cancelled');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=4'),'cancelled','zero-booking order closes directly');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=4'),'none','zero-booking order has no refund state');
    autoCancelMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=4'),0,'zero-booking order creates no refund');
    autoCancelMysqlAssert($db->fetchValue('SELECT closed_at FROM orders WHERE id=4')!==null,true,'zero-booking order records closed_at');

    // scoped A/B：只推进 A，B 保持公开。
    $seedOrder($db,101,'BX-AUTO-CANCEL-TEST-A',301,true,5000,false);
    $seedOrder($db,102,'BX-AUTO-CANCEL-TEST-B',302,true,5000,false);
    $seedPayment($db,101,5000,true,11);
    $seedPayment($db,102,5000,true,12);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES
         (11,'ordinary.construction.multi_quote.waiting.zero_quotes',101,'BX-AUTO-CANCEL-TEST-A','built'),
         (12,'ordinary.construction.multi_quote.waiting.one_quote',102,'BX-AUTO-CANCEL-TEST-B','built')"
    );
    $db->execute("INSERT INTO quotes(order_id,status,updated_at) VALUES(102,'active',UTC_TIMESTAMP())");
    $scoped=$processor->cancelTestInstance(11,true);
    autoCancelMysqlAssert(!empty($scoped['cancelled']),true,'scoped auto cancel succeeds with deadline clock fixture');
    autoCancelMysqlAssert((string)$scoped['scope'],'test_instance','scoped auto cancel declares instance scope');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=101'),'cancel_approved_pending_refund','target test order enters pending refund terminal state');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT status FROM refunds WHERE order_id=101'),'pending_review','scoped TEST_INTERNAL refund remains pending_review');
    autoCancelMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id WHERE r.order_id=101 AND p.app_id='TEST_INTERNAL' AND p.wechat_transaction_id IS NULL AND r.wechat_refund_id IS NULL"),1,'scoped refund has no external WeChat identifiers');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=102'),'active','neighbor test order remains published');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT publish_status FROM orders WHERE id=102'),'published','neighbor publish state unchanged');
    autoCancelMysqlAssert((string)$db->fetchValue('SELECT status FROM quotes WHERE order_id=102'),'active','neighbor active quote unchanged');
    autoCancelMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_cancel_requests WHERE order_id=102"),0,'neighbor has no auto-cancel request');
    autoCancelMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims WHERE business_key='order:101'"),0,'scoped auto cancel creates no global scheduler claim');
    autoCancelMysqlAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=101 AND event_key='test-auto-cancel:11:auto_cancel_unaccepted:v7'"),1,'scoped auto cancel event is instance-keyed');
    autoCancelMysqlAssert(count(OrderNotificationService::$persisted),1,'scoped auto cancel uses persisted event notification');

    // 未登记 test_payment_facts 的“测试”付款必须阻断。
    $seedOrder($db,103,'BX-AUTO-CANCEL-UNOWNED',303,true,5000,false);
    $seedPayment($db,103,5000,false,null);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(13,'ordinary.construction.multi_quote.waiting.zero_quotes',103,'BX-AUTO-CANCEL-UNOWNED','built')"
    );
    $blocked=false;
    try{$processor->cancelTestInstance(13,true);}catch(\RuntimeException $e){$blocked=true;}
    autoCancelMysqlAssert($blocked,true,'unowned testing payment blocks scoped auto cancel');
    autoCancelMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancel_requests WHERE order_id=103'),0,'unowned payment rollback leaves no cancel request');

    // 即使主单指针异常仍为 unassigned，只要存在尚有效 single_grab offered assignment，也必须阻断。
    $seedOrder($db,104,'BX-AUTO-CANCEL-OFFERED',304,true,5000,false);
    $seedPayment($db,104,5000,true,14);
    $db->execute(
        "INSERT INTO assignments(order_id,assignment_type,status,accept_deadline_at,updated_at)
         VALUES(104,'single_grab','offered',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 DAY),UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(14,'ordinary.construction.single_grab.waiting.open',104,'BX-AUTO-CANCEL-OFFERED','built')"
    );
    $blocked=false;
    try{$processor->cancelTestInstance(14,true);}catch(\RuntimeException $e){$blocked=true;}
    autoCancelMysqlAssert($blocked,true,'valid offered single-grab assignment blocks auto cancel');
    autoCancelMysqlAssert((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancel_requests WHERE order_id=104'),0,'offered assignment rollback leaves no cancel request');

    $seedOrder($db,105,'BXDEMOHALL-AUTO-CANCEL',305,true,0,false);
    $db->execute(
        "INSERT INTO test_instances(id,scenario_key,order_id,order_no_snapshot,build_status)
         VALUES(15,'ordinary.construction.multi_quote.waiting.zero_quotes',105,'BXDEMOHALL-AUTO-CANCEL','built')"
    );
    $blocked=false;
    try{$processor->cancelTestInstance(15,true);}catch(\RuntimeException $e){$blocked=true;}
    autoCancelMysqlAssert($blocked,true,'review hall sample is blocked from scoped auto cancel');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 auto cancel processor MySQL 5.7 integration: OK\n";
}

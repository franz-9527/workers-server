<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function request_id(){return 'REQ-PAID-SMS-RECEIPT';}
    function public_id(){static $i=0;$i++;return 'RUN-PAID-SMS-'.str_pad((string)$i,6,'0',STR_PAD_LEFT);}
}

namespace {
    use WorkerAdmin\Contracts\TestCenterActionRunner;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterPaidSmsReceiptActionRunner;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($path))require_once $path;
    });

    function paidMysqlSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    final class PaidReceiptInner implements TestCenterActionRunner
    {
        public function available(string $instancePublicId): array{return['items'=>[]];}
        public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
        {
            throw new RuntimeException('unexpected inner action '.$actionKey);
        }
    }

    if(getenv('V260_PAID_SMS_RECEIPT_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    paidMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'paid SMS receipt probe MySQL 5.7 connected');

    $dbName='worker_v260_paid_sms_receipt_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            is_demo TINYINT NOT NULL DEFAULT 1,
            lifecycle_status VARCHAR(40) NOT NULL,
            quote_status VARCHAR(40) NOT NULL,
            selected_quote_id BIGINT UNSIGNED NULL,
            assigned_worker_id BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            order_no_snapshot VARCHAR(80) NOT NULL,
            build_status VARCHAR(24) NOT NULL,
            verification_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            version INT NOT NULL DEFAULT 1,
            archived_at DATETIME NULL,
            deleted_at DATETIME NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE test_runs(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL UNIQUE,
            instance_id BIGINT UNSIGNED NOT NULL,
            action_key VARCHAR(100) NOT NULL,
            actor_role VARCHAR(24) NOT NULL,
            operation_mode VARCHAR(40) NOT NULL,
            idempotency_key VARCHAR(96) NOT NULL,
            request_digest CHAR(64) NOT NULL,
            request_id VARCHAR(100) NULL,
            before_summary_json TEXT NULL,
            after_summary_json TEXT NULL,
            result VARCHAR(24) NOT NULL,
            error_code VARCHAR(80) NULL,
            error_message VARCHAR(1000) NULL,
            duration_ms INT NULL,
            created_by_admin_id BIGINT UNSIGNED NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_test_run_idem(instance_id,idempotency_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE test_message_outbox(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            business_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            event_type VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            channel VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            recipient_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            recipient_id BIGINT UNSIGNED NULL,
            scene_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
            projection_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            reason_code VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
            payload_json TEXT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uniq_test_message_business(instance_id,business_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE message_outbox(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            topic VARCHAR(40) NOT NULL,
            business_key VARCHAR(190) NOT NULL,
            payload_json TEXT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_worker_sms_invites(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            delivery_status VARCHAR(40) NOT NULL,
            billing_status VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB"
    ]as$sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO orders(id,public_no,is_demo,lifecycle_status,quote_status,selected_quote_id,assigned_worker_id)
                  VALUES(101,'BXTEST-PAID-SMS-101',1,'active','waiting',NULL,NULL)");
    $db->execute("INSERT INTO test_instances(id,public_id,order_id,order_no_snapshot,build_status,verification_status,version,updated_at)
                  VALUES(11,'INSTANCE-PAID-SMS-11',101,'BXTEST-PAID-SMS-101','built','pending',1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO message_outbox(topic,business_key,payload_json)
                  VALUES('aliyun_paid_sms','existing-paid-sms','{\"order_id\":101}')");
    $db->execute("INSERT INTO order_worker_sms_invites(order_id,delivery_status,billing_status)
                  VALUES(101,'provider_accepted','held')");

    $runner=new DatabaseTestCenterPaidSmsReceiptActionRunner($db,new PaidReceiptInner());
    $available=$runner->available('INSTANCE-PAID-SMS-11');
    paidMysqlSame(isset($available['items']['system_paid_sms_receipt_probe']),true,'paid SMS receipt probe exposed for waiting demo order');

    $outid=$runner->run(
        'INSTANCE-PAID-SMS-11','system_paid_sms_receipt_probe',
        ['receipt_scenario'=>'delivered_outid'],9,'paid-outid'
    );
    paidMysqlSame((string)$outid['business']['decision']['billing_status'],'charged','OutId delivered result simulates charged billing');
    paidMysqlSame((string)$outid['business']['simulated_wallet_effect'],'charge_once','OutId delivered simulates one charge');
    paidMysqlSame((int)$outid['business']['test_fact_count'],1,'duplicate delivered receipt creates one test fact');
    paidMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:paid-outid:%'"),1,'OutId terminal fact persisted once');

    $replay=$runner->run(
        'INSTANCE-PAID-SMS-11','system_paid_sms_receipt_probe',
        ['receipt_scenario'=>'delivered_outid'],9,'paid-outid'
    );
    paidMysqlSame((bool)$replay['replayed'],true,'same paid SMS receipt request replays idempotently');
    paidMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:paid-outid:%'"),1,'action replay does not duplicate receipt evidence');

    $biz=$runner->run(
        'INSTANCE-PAID-SMS-11','system_paid_sms_receipt_probe',
        ['receipt_scenario'=>'delivered_bizid'],9,'paid-bizid'
    );
    paidMysqlSame((string)$biz['business']['match_mode'],'biz_id_query_without_out_id','BizId query accepts missing OutId');
    paidMysqlSame((string)$biz['business']['decision']['billing_status'],'charged','BizId delivered result simulates charged billing');

    $failed=$runner->run(
        'INSTANCE-PAID-SMS-11','system_paid_sms_receipt_probe',
        ['receipt_scenario'=>'delivery_failed'],9,'paid-failed'
    );
    paidMysqlSame((string)$failed['business']['decision']['billing_status'],'released','failed provider receipt simulates released billing');
    paidMysqlSame((string)$failed['business']['simulated_wallet_effect'],'release_once','failed provider receipt releases once');

    $timeout=$runner->run(
        'INSTANCE-PAID-SMS-11','system_paid_sms_receipt_probe',
        ['receipt_scenario'=>'missing_until_timeout'],9,'paid-timeout'
    );
    paidMysqlSame($timeout['business']['retry_delay_seconds'],[30,120,600,1800,3600],'missing receipt follows production retry schedule');
    paidMysqlSame((string)$timeout['business']['timeout_decision']['delivery_status'],'unknown','long missing receipt closes as unknown');
    paidMysqlSame((string)$timeout['business']['timeout_decision']['billing_status'],'released','long missing receipt releases held billing');
    paidMysqlSame((bool)$timeout['business']['timeout_decision']['retain_active_dedupe_key'],true,'timeout retains anti-resend key');
    paidMysqlSame((int)$timeout['business']['test_fact_count'],6,'five receipt attempts plus one timeout fact persisted');
    paidMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:paid-timeout:%'"),6,'timeout terminal duplicate is deduplicated');

    paidMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM message_outbox WHERE topic='aliyun_paid_sms'"),1,'all receipt probes leave real paid SMS outbox unchanged');
    paidMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_worker_sms_invites WHERE order_id=101'),1,'all receipt probes leave real invite rows unchanged');
    paidMysqlSame((string)$db->fetchValue('SELECT delivery_status FROM order_worker_sms_invites WHERE order_id=101'),'provider_accepted','real invite delivery state unchanged');
    paidMysqlSame((string)$db->fetchValue('SELECT billing_status FROM order_worker_sms_invites WHERE order_id=101'),'held','real invite billing state unchanged');
    paidMysqlSame((string)$db->fetchValue('SELECT operation_mode FROM test_runs WHERE idempotency_key=:key',['key'=>'paid-bizid']),'simulated_external','receipt probe audit mode is simulated_external');
    paidMysqlSame((string)$db->fetchValue('SELECT actor_role FROM test_runs WHERE idempotency_key=:key',['key'=>'paid-timeout']),'system','receipt probe actor is system');

    $payloads=implode(' ',array_column($db->fetchAll("SELECT payload_json FROM test_message_outbox WHERE instance_id=11 AND channel='aliyun_paid_sms'"),'payload_json'));
    paidMysqlSame(strpos($payloads,'TEST_MOBILE')===false&&strpos($payloads,'TEST_BIZ')===false,true,'receipt evidence stores no simulated mobile or BizId value');

    $db->execute("UPDATE orders SET quote_status='selected' WHERE id=101");
    $notAvailable=$runner->available('INSTANCE-PAID-SMS-11');
    paidMysqlSame(isset($notAvailable['items']['system_paid_sms_receipt_probe']),false,'receipt probe hidden after quote stage ends');
    $blocked=false;
    try{
        $runner->run(
            'INSTANCE-PAID-SMS-11','system_paid_sms_receipt_probe',
            ['receipt_scenario'=>'delivered_outid'],9,'paid-after-stage'
        );
    }catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){
        $blocked=$e->businessCode()==='TEST_PAID_SMS_RECEIPT_ORDER_CHANGED';
    }
    paidMysqlSame($blocked,true,'receipt probe fails closed after order stage changes');

    $db->execute("INSERT INTO orders(id,public_no,is_demo,lifecycle_status,quote_status,selected_quote_id,assigned_worker_id)
                  VALUES(102,'BXDEMOHALL-PAID-SMS-102',1,'active','waiting',NULL,NULL)");
    $db->execute("INSERT INTO test_instances(id,public_id,order_id,order_no_snapshot,build_status,verification_status,version,updated_at)
                  VALUES(12,'INSTANCE-PAID-SMS-12',102,'BXDEMOHALL-PAID-SMS-102','built','pending',1,UTC_TIMESTAMP())");
    $reviewBlocked=false;
    try{$runner->available('INSTANCE-PAID-SMS-12');}
    catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){$reviewBlocked=$e->businessCode()==='TEST_ACTION_ORDER_BOUNDARY';}
    paidMysqlSame($reviewBlocked,true,'BXDEMOHALL paid SMS receipt probe remains blocked');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 paid SMS receipt probe MySQL 5.7 integration: OK\n";
}

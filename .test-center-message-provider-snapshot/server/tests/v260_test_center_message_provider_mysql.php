<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function request_id(){return 'REQ-MSG-PROVIDER';}
    function public_id(){static $i=0;$i++;return 'RUN-MSG-PROVIDER-'.str_pad((string)$i,4,'0',STR_PAD_LEFT);}
}

namespace {
    use WorkerAdmin\Contracts\TestCenterActionRunner;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterMessageProviderFailureActionRunner;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/TestCenterActionRunner.php';
    require_once $root.'/server/app/Domain/Testing/TestCenterException.php';
    require_once $root.'/server/app/Domain/Messaging/MessageDeliveryFailurePolicy.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Testing/TestingActionExecution.php';
    require_once $root.'/server/app/Infrastructure/Testing/DatabaseTestCenterMessageProviderFailureActionRunner.php';

    function providerMysqlSame($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    final class ProviderProbeInner implements TestCenterActionRunner
    {
        public function available(string $instancePublicId): array{return['items'=>[],'message'=>'inner'];}
        public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey): array
        {
            throw new RuntimeException('unexpected inner action '.$actionKey);
        }
    }

    if(getenv('V260_MESSAGE_PROVIDER_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    providerMysqlSame(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'provider probe MySQL 5.7 connected');

    $dbName='worker_v260_message_provider_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL UNIQUE,
            is_demo TINYINT NOT NULL DEFAULT 1
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
            payload_json TEXT NULL,
            status VARCHAR(24) NOT NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB"
    ] as $sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO orders(id,public_no,is_demo) VALUES(101,'BXTEST-MSG-PROVIDER-101',1)");
    $db->execute("INSERT INTO test_instances(id,public_id,order_id,order_no_snapshot,build_status,verification_status,version,updated_at)
                  VALUES(11,'INSTANCE-MSG-PROVIDER-11',101,'BXTEST-MSG-PROVIDER-101','built','pending',1,UTC_TIMESTAMP())");
    $db->execute(
        "INSERT INTO test_message_outbox(instance_id,order_id,business_key,event_key,event_type,channel,recipient_type,recipient_id,scene_key,projection_status,reason_code,payload_json,created_at)
         VALUES
         (11,101,'source-wx','event-source','worker_assigned','wechat_subscribe','user',701,'user_order_update','demo_suppressed','demo_order_external_delivery_suppressed','{}',UTC_TIMESTAMP()),
         (11,101,'source-sms','event-source','worker_assigned','aliyun_sms','user',701,'user_order_update','not_run','subscription_fallback_not_triggered_in_demo','{}',UTC_TIMESTAMP())"
    );
    $db->execute(
        "INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,created_at)
         VALUES('wechat_subscribe','existing-real-row','{\"order_id\":101}','failed',5,UTC_TIMESTAMP())"
    );

    $runner=new DatabaseTestCenterMessageProviderFailureActionRunner($db,new ProviderProbeInner());
    $available=$runner->available('INSTANCE-MSG-PROVIDER-11');
    providerMysqlSame(isset($available['items']['system_message_provider_failure_probe']),true,'provider probe exposed for paired projection');

    $runPermanent=$runner->run(
        'INSTANCE-MSG-PROVIDER-11',
        'system_message_provider_failure_probe',
        ['source_message_id'=>1,'provider_scenario'=>'wechat_permanent_43101'],
        9,
        'provider-permanent-43101'
    );
    providerMysqlSame((int)$runPermanent['business']['fallback_count'],1,'43101 produces one simulated fallback');
    providerMysqlSame((bool)$runPermanent['business']['duplicate_provider_result_deduplicated'],true,'43101 duplicate provider result deduplicated');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-permanent-43101:%' AND channel='wechat_subscribe'"),1,'43101 leaves one subscription failure fact');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-permanent-43101:%' AND channel='aliyun_sms'"),1,'43101 leaves one SMS fallback fact');

    $replay=$runner->run(
        'INSTANCE-MSG-PROVIDER-11',
        'system_message_provider_failure_probe',
        ['source_message_id'=>1,'provider_scenario'=>'wechat_permanent_43101'],
        9,
        'provider-permanent-43101'
    );
    providerMysqlSame((bool)$replay['replayed'],true,'same request key replays without duplicate writes');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-permanent-43101:%'"),2,'idempotent action replay keeps two projection facts total');

    $runWechat=$runner->run(
        'INSTANCE-MSG-PROVIDER-11',
        'system_message_provider_failure_probe',
        ['source_message_id'=>1,'provider_scenario'=>'wechat_transient_exhausted'],
        9,
        'provider-wechat-transient'
    );
    providerMysqlSame(array_column($runWechat['business']['attempts'],'retry_delay_minutes'),[1,2,4,8,null],'wechat transient chain uses 1/2/4/8 then terminal');
    providerMysqlSame((int)$runWechat['business']['fallback_count'],1,'wechat retry exhaustion falls back exactly once');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-wechat-transient:%' AND channel='wechat_subscribe'"),5,'wechat transient chain stores exactly five attempts');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-wechat-transient:%' AND channel='aliyun_sms'"),1,'wechat transient chain stores one fallback');

    $runSms=$runner->run(
        'INSTANCE-MSG-PROVIDER-11',
        'system_message_provider_failure_probe',
        ['source_message_id'=>1,'provider_scenario'=>'sms_transient_exhausted'],
        9,
        'provider-sms-transient'
    );
    providerMysqlSame(array_column($runSms['business']['attempts'],'retry_delay_minutes'),[1,2,4,8,null],'SMS transient chain uses 1/2/4/8 then terminal');
    providerMysqlSame((int)$runSms['business']['fallback_count'],0,'SMS retry exhaustion never recursively falls back');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-sms-transient:%' AND channel='aliyun_sms'"),5,'SMS transient chain stores exactly five attempts');
    providerMysqlSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_message_outbox WHERE instance_id=11 AND business_key LIKE '%:provider-sms-transient:%' AND channel='wechat_subscribe'"),0,'SMS terminal failure creates no recursive channel fact');

    providerMysqlSame((int)$db->fetchValue('SELECT COUNT(*) FROM message_outbox'),1,'all provider probes leave real message_outbox unchanged');
    providerMysqlSame((string)$db->fetchValue('SELECT operation_mode FROM test_runs WHERE idempotency_key=:key',['key'=>'provider-wechat-transient']),'simulated_external','provider probe audit mode is simulated_external');
    providerMysqlSame((string)$db->fetchValue('SELECT actor_role FROM test_runs WHERE idempotency_key=:key',['key'=>'provider-sms-transient']),'system','provider probe actor is system');

    $blocked=false;
    try{
        $runner->run(
            'INSTANCE-MSG-PROVIDER-11',
            'system_message_provider_failure_probe',
            ['source_message_id'=>999,'provider_scenario'=>'wechat_permanent_43101'],
            9,
            'provider-invalid-source'
        );
    }catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){
        $blocked=$e->businessCode()==='TEST_MESSAGE_PROVIDER_PROBE_SOURCE_CHANGED';
    }
    providerMysqlSame($blocked,true,'source message outside current paired projection fails closed');

    $db->execute("INSERT INTO orders(id,public_no,is_demo) VALUES(102,'BXDEMOHALL-PROVIDER-102',1)");
    $db->execute("INSERT INTO test_instances(id,public_id,order_id,order_no_snapshot,build_status,verification_status,version,updated_at)
                  VALUES(12,'INSTANCE-MSG-PROVIDER-12',102,'BXDEMOHALL-PROVIDER-102','built','pending',1,UTC_TIMESTAMP())");
    $reviewBlocked=false;
    try{$runner->available('INSTANCE-MSG-PROVIDER-12');}
    catch(\WorkerAdmin\Domain\Testing\TestCenterException $e){$reviewBlocked=$e->businessCode()==='TEST_ACTION_ORDER_BOUNDARY';}
    providerMysqlSame($reviewBlocked,true,'BXDEMOHALL review samples remain blocked');

    $rootDb->pdo()->exec('DROP DATABASE '.$dbName);
    echo "V2.6.0 message provider probe MySQL 5.7 integration: OK\n";
}

<?php

declare(strict_types=1);

namespace {
    function app_config($key,$default=null){return $key==='app.env'?'testing':$default;}
    function audit($action,$entityType,$entityId,$description=''){return null;}
}

namespace {
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAutoCancelCleanupRepository;

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Contracts/TestCenterRepository.php';
    require_once $root.'/server/app/Domain/Testing/TestCenterException.php';
    require_once $root.'/server/app/Infrastructure/Safety/TestingEnvironmentPolicy.php';
    require_once $root.'/server/app/Infrastructure/Testing/DatabaseTestCenterAutoCancelCleanupRepository.php';

    function autoCancelCleanupAssert($actual,$expected,string $label): void
    {
        if($actual!==$expected){
            fwrite(STDERR,'[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)."\n");
            exit(1);
        }
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }

    final class AutoCancelCleanupInner implements TestCenterRepository
    {
        private Database $db;
        private int $instanceId;
        public bool $cleanupCalled=false;

        public function __construct(Database $db,int $instanceId){$this->db=$db;$this->instanceId=$instanceId;}
        public function environmentDiagnostics(): array{return[];}
        public function listInstances(array $filters=[]): array{return[];}
        public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null): array{return[];}
        public function verify(string $publicId,int $adminId): array{return[];}
        public function rebuildSource(string $publicId): array{return[];}

        public function instanceDetail(string $publicId): array
        {
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);
            $order=$instance?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;
            return['instance'=>$instance?:[],'order'=>$order?:null];
        }

        public function cleanupPreview(string $publicId): array
        {
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE id=:id',['id'=>$this->instanceId]);
            $orderId=(int)($instance['order_id']??0);
            $counts=[
                'refunds'=>$orderId>0?(int)$this->db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=:id',['id'=>$orderId]):0,
                'order_cancel_requests'=>$orderId>0?(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_cancel_requests WHERE order_id=:id',['id'=>$orderId]):0,
                'order_cancel_decisions'=>$orderId>0?(int)$this->db->fetchValue('SELECT COUNT(*) FROM order_cancel_decisions WHERE order_id=:id',['id'=>$orderId]):0,
            ];
            $blocking=[];
            foreach($counts as$key=>$count)if($count>0)$blocking[$key]=$count;
            return[
                'allowed'=>empty($blocking),
                'reason'=>empty($blocking)?'inner clean':'auto-cancel facts block base cleanup',
                'counts'=>[],
                'blocking_relations'=>$blocking,
                'plan_digest'=>empty($blocking)?'inner-clean':'inner-with-auto-cancel',
            ];
        }

        public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest): array
        {
            if($expectedPlanDigest!=='inner-clean')throw new RuntimeException('unexpected inner digest '.$expectedPlanDigest);
            $this->cleanupCalled=true;
            return['ok'=>true,'inner_cleanup'=>true];
        }
    }

    if(getenv('V260_AUTO_CANCEL_CLEANUP_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V250_DB_NAME')!=='worker_test'){
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
    autoCancelCleanupAssert(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,true,'cleanup MySQL 5.7 connected');

    $dbName='worker_v260_auto_cancel_cleanup_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    $rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    foreach([
        "CREATE TABLE orders(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_no VARCHAR(80) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            order_source_type VARCHAR(24) NOT NULL,
            workflow_profile VARCHAR(24) NOT NULL,
            is_demo TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB",
        "CREATE TABLE test_instances(
            id BIGINT UNSIGNED PRIMARY KEY,
            public_id VARCHAR(80) NOT NULL,
            scenario_key VARCHAR(128) NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_no_snapshot VARCHAR(80) NOT NULL,
            version INT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB",
        "CREATE TABLE test_runs(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id BIGINT UNSIGNED NOT NULL,
            action_key VARCHAR(80) NOT NULL,
            actor_role VARCHAR(24) NOT NULL,
            operation_mode VARCHAR(40) NOT NULL,
            result VARCHAR(24) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
        "CREATE TABLE order_cancel_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            applicant_type VARCHAR(24) NOT NULL,
            applicant_id BIGINT UNSIGNED NULL,
            request_type VARCHAR(48) NOT NULL,
            reason_code VARCHAR(64) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            status VARCHAR(24) NOT NULL,
            requested_refund_cents BIGINT UNSIGNED NOT NULL,
            closed_at DATETIME NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE refunds(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payment_order_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            cancel_request_id BIGINT UNSIGNED NULL,
            amount_cents BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL,
            requested_by_admin_id BIGINT UNSIGNED NULL,
            wechat_refund_id VARCHAR(128) NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE order_cancel_decisions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cancel_request_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(24) NOT NULL,
            responsibility VARCHAR(48) NOT NULL,
            refund_amount_cents BIGINT UNSIGNED NOT NULL,
            run_empty_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
            refund_id BIGINT UNSIGNED NULL,
            decided_by BIGINT UNSIGNED NULL
        ) ENGINE=InnoDB"
    ]as$sql)$db->pdo()->exec($sql);

    // 可安全清理的当前实例。
    $db->execute(
        "INSERT INTO orders(id,public_no,user_id,order_source_type,workflow_profile,is_demo)
         VALUES(201,'BX-AUTO-CANCEL-CLEAN',701,'consumer','construction',1)"
    );
    $db->execute(
        "INSERT INTO test_instances(id,public_id,scenario_key,order_id,order_no_snapshot,version)
         VALUES(21,'INSTANCE-AUTO-CANCEL-21','ordinary.construction.multi_quote.waiting.zero_quotes',201,'BX-AUTO-CANCEL-CLEAN',3)"
    );
    $db->execute(
        "INSERT INTO test_runs(instance_id,action_key,actor_role,operation_mode,result)
         VALUES(21,'system_auto_cancel','system','real_service','passed')"
    );
    $paymentId=$db->insert(
        "INSERT INTO payment_orders(
            order_id,business_type,status,amount_cents,app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id
         ) VALUES(
            201,'order_booking','paid',5000,'TEST_INTERNAL','TEST_INTERNAL','TEST_INTERNAL:21:701',NULL,NULL
         )"
    );
    $db->execute(
        "INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider)
         VALUES(21,:payment,'internal_test')",
        ['payment'=>$paymentId]
    );
    $requestId=$db->insert(
        "INSERT INTO order_cancel_requests(
            order_id,applicant_type,applicant_id,request_type,reason_code,reason,status,requested_refund_cents,closed_at
         ) VALUES(
            201,'system',NULL,'auto_timeout_cancel','seven_days_no_valid_quote',
            '订单公开满 7 天仍无有效报价，系统自动取消。','approved',5000,UTC_TIMESTAMP()
         )"
    );
    $refundId=$db->insert(
        "INSERT INTO refunds(
            payment_order_id,order_id,cancel_request_id,amount_cents,status,requested_by_admin_id,wechat_refund_id
         ) VALUES(
            :payment,201,:request,5000,'pending_review',NULL,NULL
         )",
        ['payment'=>$paymentId,'request'=>$requestId]
    );
    $db->execute(
        "INSERT INTO order_cancel_decisions(
            cancel_request_id,order_id,decision,responsibility,refund_amount_cents,
            run_empty_fee_cents,refund_id,decided_by
         ) VALUES(
            :request,201,'approved','timeout_unaccepted',5000,0,:refund,NULL
         )",
        ['request'=>$requestId,'refund'=>$refundId]
    );

    $inner=new AutoCancelCleanupInner($db,21);
    $cleanup=new DatabaseTestCenterAutoCancelCleanupRepository($db,$inner);
    $preview=$cleanup->cleanupPreview('INSTANCE-AUTO-CANCEL-21');
    autoCancelCleanupAssert((bool)$preview['allowed'],true,'owned scoped auto cancel facts are adapted out of base blockers');
    autoCancelCleanupAssert((int)$preview['auto_cancel_cleanup']['request_id'],$requestId,'cleanup preview binds exact owned cancel request');
    autoCancelCleanupAssert((int)$preview['auto_cancel_cleanup']['refund_id'],$refundId,'cleanup preview binds exact owned internal refund');

    $result=$cleanup->cleanup('INSTANCE-AUTO-CANCEL-21',1,(string)$preview['plan_digest']);
    autoCancelCleanupAssert(!empty($result['inner_cleanup']),true,'owned auto cancel deletion continues into inner cleanup');
    autoCancelCleanupAssert($inner->cleanupCalled,true,'inner cleanup receives clean post-cancel plan');
    autoCancelCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancel_decisions WHERE order_id=201'),0,'owned scoped auto cancel facts deleted');
    autoCancelCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=201'),0,'owned internal refund deleted before payment cleanup');
    autoCancelCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancel_requests WHERE order_id=201'),0,'owned auto cancel request deleted');
    autoCancelCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE id=:id',['id'=>$paymentId]),1,'internal payment preserved for downstream cleanup');
    autoCancelCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM test_payment_facts WHERE payment_order_id=:id',['id'=>$paymentId]),1,'test payment ownership fact preserved for downstream cleanup');

    // 外部退款标识出现后必须 fail closed。
    $db->execute(
        "INSERT INTO orders(id,public_no,user_id,order_source_type,workflow_profile,is_demo)
         VALUES(202,'BX-AUTO-CANCEL-WECHAT',702,'consumer','construction',1)"
    );
    $db->execute(
        "INSERT INTO test_instances(id,public_id,scenario_key,order_id,order_no_snapshot,version)
         VALUES(22,'INSTANCE-AUTO-CANCEL-22','ordinary.construction.multi_quote.waiting.zero_quotes',202,'BX-AUTO-CANCEL-WECHAT',1)"
    );
    $db->execute(
        "INSERT INTO test_runs(instance_id,action_key,actor_role,operation_mode,result)
         VALUES(22,'system_auto_cancel','system','real_service','passed')"
    );
    $payment2=$db->insert(
        "INSERT INTO payment_orders(
            order_id,business_type,status,amount_cents,app_id,mch_id,payer_openid,wechat_prepay_id,wechat_transaction_id
         ) VALUES(
            202,'order_booking','paid',3000,'TEST_INTERNAL','TEST_INTERNAL','TEST_INTERNAL:22:702',NULL,NULL
         )"
    );
    $db->execute(
        "INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider)
         VALUES(22,:payment,'internal_test')",
        ['payment'=>$payment2]
    );
    $request2=$db->insert(
        "INSERT INTO order_cancel_requests(
            order_id,applicant_type,applicant_id,request_type,reason_code,reason,status,requested_refund_cents,closed_at
         ) VALUES(
            202,'system',NULL,'auto_timeout_cancel','seven_days_no_valid_quote',
            '订单公开满 7 天仍无有效报价，系统自动取消。','approved',3000,UTC_TIMESTAMP()
         )"
    );
    $refund2=$db->insert(
        "INSERT INTO refunds(
            payment_order_id,order_id,cancel_request_id,amount_cents,status,requested_by_admin_id,wechat_refund_id
         ) VALUES(
            :payment,202,:request,3000,'pending_review',NULL,'WX-REFUND-SHOULD-BLOCK'
         )",
        ['payment'=>$payment2,'request'=>$request2]
    );
    $db->execute(
        "INSERT INTO order_cancel_decisions(
            cancel_request_id,order_id,decision,responsibility,refund_amount_cents,
            run_empty_fee_cents,refund_id,decided_by
         ) VALUES(
            :request,202,'approved','timeout_unaccepted',3000,0,:refund,NULL
         )",
        ['request'=>$request2,'refund'=>$refund2]
    );

    $blockedInner=new AutoCancelCleanupInner($db,22);
    $blockedCleanup=new DatabaseTestCenterAutoCancelCleanupRepository($db,$blockedInner);
    $blockedPreview=$blockedCleanup->cleanupPreview('INSTANCE-AUTO-CANCEL-22');
    autoCancelCleanupAssert((bool)$blockedPreview['allowed'],false,'wechat refund marker blocks scoped auto cancel cleanup');
    autoCancelCleanupAssert(isset($blockedPreview['blocking_relations']['auto_cancel_refund_unowned']),true,'cleanup reports unowned external refund marker');
    autoCancelCleanupAssert((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=202'),1,'blocked cleanup preserves suspicious refund');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 auto cancel cleanup MySQL 5.7 integration: OK\n";
}

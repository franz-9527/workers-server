<?php

declare(strict_types=1);

namespace WorkerAdmin\Services {
    use WorkerAdmin\Core\Database;
    final class OrderNotificationService
    {
        public static array $queued=[];
        public static function queue(Database $db,int $orderId,string $eventKey,string $eventType,array $payload=[]): void{self::$queued[]=[$orderId,$eventKey,$eventType,$payload];}
        public static function queueAdminTask(Database $db,string $businessKey,string $eventType,string $title,array $payload=[]): void{}
    }
}

namespace WorkerAdmin\Infrastructure\Persistence {
    use WorkerAdmin\Core\Database;
    final class DatabaseOrderCancellationRiskRepository
    {
        public static array $calls=[];
        public function __construct(Database $db){}
        public function captureCancellation(array $order,string $responsibility='unclear',int $runEmptyCents=0): void{self::$calls[]=[(int)($order['id']??0),$responsibility,$runEmptyCents];}
    }
}

namespace {
    use WorkerAdmin\Contracts\TestCenterActionRunner;
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Testing\TestCenterException;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRiskRepository;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceActionRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceVerificationRepository;
    use WorkerAdmin\Services\OrderNotificationService;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($file))require_once$file;
    });

    if(!function_exists('app_config')){function app_config(string $key,$default=null){$map=['app.env'=>'testing','app.timezone'=>'Asia/Shanghai'];return array_key_exists($key,$map)?$map[$key]:$default;}}
    if(!function_exists('setting')){function setting(string $key,$default=null){return$default;}}
    if(!function_exists('business_no')){function business_no($prefix):string{static $i=0;$i++;return strtoupper((string)$prefix).'V260MYSQL'.str_pad((string)$i,12,'0',STR_PAD_LEFT);}}
    if(!function_exists('public_id')){function public_id():string{static $i=0;$i++;return'01V260MYSQL'.str_pad((string)$i,16,'0',STR_PAD_LEFT);}}
    if(!function_exists('request_id')){function request_id():string{return'v260-finance-mysql-'.bin2hex(random_bytes(6));}}

    final class V260FinanceNullActionRunner implements TestCenterActionRunner
    {
        public function available(string $instancePublicId):array{return['items'=>[],'message'=>''];}
        public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey):array{throw new RuntimeException('unexpected inner action '.$actionKey);}
    }
    final class V260FinanceNullRepository implements TestCenterRepository
    {
        public function environmentDiagnostics():array{return[];}
        public function listInstances(array $filters=[]):array{return[];}
        public function instanceDetail(string $publicId):array{return[];}
        public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{return[];}
        public function verify(string $publicId,int $adminId):array{throw new RuntimeException('unexpected inner verification');}
        public function cleanupPreview(string $publicId):array{return[];}
        public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array{return[];}
        public function rebuildSource(string $publicId):array{return[];}
    }

    function v260FinanceSame($actual,$expected,string $label):void
    {
        if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");exit(1);}fwrite(STDOUT,'[PASS] '.$label."\n");
    }
    function v260FinanceTrue(bool $actual,string $label):void{v260FinanceSame($actual,true,$label);}

    if(getenv('V260_DISPUTE_FINANCE_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V260_DB_NAME')!=='worker_test'){
        fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);
    }
    $config=['host'=>(string)(getenv('V260_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V260_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V260_DB_USER')?:'root'),'password'=>(string)(getenv('V260_DB_PASSWORD')?:'root')];
    $rootDb=new Database($config);v260FinanceTrue(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,'MySQL 5.7 connected');
    $dbName='worker_v260_test_center_finance_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);

    $ddl=[
        "CREATE TABLE admin_users(id BIGINT UNSIGNED PRIMARY KEY,username VARCHAR(64) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE system_settings(setting_key VARCHAR(128) PRIMARY KEY,setting_value TEXT NULL) ENGINE=InnoDB",
        "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,order_source_type VARCHAR(24) NOT NULL,workflow_profile VARCHAR(24) NOT NULL,is_demo TINYINT(1) NOT NULL DEFAULT 0,lifecycle_status VARCHAR(48) NOT NULL,booking_fee_cents BIGINT UNSIGNED NOT NULL,assigned_worker_id BIGINT UNSIGNED NULL,selected_quote_id BIGINT UNSIGNED NULL,assignment_status VARCHAR(24) NOT NULL,current_assignment_id BIGINT UNSIGNED NULL,publish_status VARCHAR(24) NOT NULL,quote_status VARCHAR(24) NOT NULL,fulfillment_status VARCHAR(24) NOT NULL,payment_status VARCHAR(24) NOT NULL DEFAULT 'paid',refund_status VARCHAR(24) NOT NULL,dispute_status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,closed_at DATETIME NULL,version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,raised_by_type VARCHAR(24) NOT NULL,raised_by_id BIGINT UNSIGNED NOT NULL,dispute_type VARCHAR(32) NOT NULL,title VARCHAR(255) NULL,description VARCHAR(1000) NULL,status VARCHAR(24) NOT NULL,responsibility VARCHAR(24) NOT NULL DEFAULT 'undetermined',resolution VARCHAR(1000) NULL,refund_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,run_empty_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,handled_by BIGINT UNSIGNED NULL,opened_at DATETIME NULL,resolved_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,payer_openid VARCHAR(128) NULL,app_id VARCHAR(64) NULL,mch_id VARCHAR(64) NULL,wechat_prepay_id VARCHAR(128) NULL,wechat_transaction_id VARCHAR(128) NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,payment_order_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents BIGINT UNSIGNED NOT NULL,reason VARCHAR(1000) NOT NULL,status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,requested_at DATETIME NULL,wechat_refund_id VARCHAR(128) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,worker_id BIGINT UNSIGNED NOT NULL,entry_type VARCHAR(32) NOT NULL,direction VARCHAR(16) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,available_delta_cents BIGINT NOT NULL,frozen_delta_cents BIGINT NOT NULL,business_type VARCHAR(32) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,idempotency_key VARCHAR(128) NOT NULL UNIQUE,description VARCHAR(1000) NULL,status VARCHAR(24) NOT NULL,created_by_admin_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,cancelled_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NULL,status VARCHAR(24) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL,event_key VARCHAR(128) NOT NULL,actor_type VARCHAR(24) NOT NULL,actor_id BIGINT UNSIGNED NULL,payload_json LONGTEXT NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_instances(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,scenario_key VARCHAR(160) NOT NULL,order_id BIGINT UNSIGNED NULL,verification_status VARCHAR(24) NOT NULL DEFAULT 'pending',version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,simulation_request_key VARCHAR(128) NOT NULL,business_type VARCHAR(32) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,simulated_provider VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,created_by_admin_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,instance_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(80) NOT NULL,actor_role VARCHAR(24) NOT NULL,operation_mode VARCHAR(24) NOT NULL,idempotency_key VARCHAR(128) NOT NULL,request_digest CHAR(64) NOT NULL,request_id VARCHAR(128) NULL,before_summary_json LONGTEXT NULL,after_summary_json LONGTEXT NULL,result VARCHAR(24) NOT NULL,error_code VARCHAR(128) NULL,error_message VARCHAR(1000) NULL,created_by_admin_id BIGINT UNSIGNED NULL,started_at DATETIME NULL,finished_at DATETIME NULL,duration_ms INT UNSIGNED NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_test_run_instance_idem(instance_id,idempotency_key)) ENGINE=InnoDB",
        "CREATE TABLE test_assertion_results(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,assertion_group VARCHAR(64) NOT NULL,assertion_key VARCHAR(128) NOT NULL,status VARCHAR(24) NOT NULL,expected_text VARCHAR(1000) NULL,actual_text VARCHAR(1000) NULL,details_json LONGTEXT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_cancellation_risk_scores(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB"
    ];foreach($ddl as$sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO admin_users(id,username) VALUES(77,'web-admin')");
    $db->execute("INSERT INTO system_settings(setting_key,setting_value) VALUES('demo.data_enabled','1')");
    $db->execute("INSERT INTO orders(id,public_no,user_id,order_source_type,workflow_profile,is_demo,lifecycle_status,booking_fee_cents,assigned_worker_id,assignment_status,current_assignment_id,publish_status,quote_status,fulfillment_status,payment_status,refund_status,dispute_status,version,updated_at) VALUES(9,'BXTESTFINMYSQL09',101,'consumer','construction',1,'active',5000,66,'accepted',909,'hidden','selected','in_progress','paid','none','open',1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO change_requests(public_id,order_id,status) VALUES('CHGFINMYSQL09',9,'rejected')");
    $db->execute("INSERT INTO disputes(public_no,order_id,raised_by_type,raised_by_id,dispute_type,title,description,status,responsibility,refund_amount_cents,run_empty_fee_cents,handled_by,opened_at,resolved_at,updated_at) VALUES('DSPFINMYSQL09',9,'user',101,'fee_negotiation','费用协商平台介入','来自已拒绝加价 CHGFINMYSQL09','open','undetermined',0,0,NULL,UTC_TIMESTAMP(),NULL,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO payment_orders(id,public_no,order_id,business_type,status,amount_cents,payer_openid,app_id,mch_id,wechat_prepay_id,wechat_transaction_id) VALUES(1,'PTESTFINMYSQL09',9,'order_booking','paid',5000,'TEST_INTERNAL:501:101','TEST_INTERNAL','TEST_INTERNAL',NULL,NULL)");
    $db->execute("INSERT INTO test_instances(id,public_id,scenario_key,order_id,verification_status,version,updated_at) VALUES(501,'TINSTFINMYSQL01','ordinary.construction.multi_quote.waiting.zero_quotes',9,'pending',1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulation_request_key,business_type,amount_cents,simulated_provider,status,created_by_admin_id,created_at) VALUES(501,1,'test-booking:501','order_booking',5000,'internal_test','paid',77,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO assignments(id,order_id,worker_id,status,cancelled_at,updated_at) VALUES(909,9,66,'accepted',NULL,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO quotes(order_id,worker_id,status,updated_at) VALUES(9,66,'selected',UTC_TIMESTAMP())");
    $db->execute("INSERT INTO invite_tokens(order_id,status) VALUES(9,'active')");
    $db->execute("INSERT INTO order_role_grants(order_id,status,revoked_at) VALUES(9,'active',NULL)");
    $db->execute("INSERT INTO order_deadlines(order_id,status,processed_at,updated_at) VALUES(9,'pending',NULL,UTC_TIMESTAMP())");

    $runner=new DatabaseTestCenterDisputeFinanceActionRunner($db,new V260FinanceNullActionRunner());
    $available=$runner->available('TINSTFINMYSQL01');
    v260FinanceTrue(isset($available['items']['admin_dispute_financial_resolve']),'finance action becomes available only after owned dispute/payment shape is valid');
    v260FinanceSame((int)$available['items']['admin_dispute_financial_resolve']['available_cents'],5000,'finance action exposes owned booking cap');

    $input=['run_empty_fee_yuan'=>'8.00','note'=>'测试中心 MySQL E2E：客户承担 8 元跑空费，其余预约费登记退款'];
    $result=$runner->run('TINSTFINMYSQL01','admin_dispute_financial_resolve',$input,77,'mysql-e2e:finance:1');
    v260FinanceSame((string)$result['operation_mode'],'real_service','test center action records real_service mode');
    v260FinanceSame((int)$result['business']['data']['refund_cents'],4200,'formal dispute service auto-refunds remaining 42 yuan');
    v260FinanceSame((int)$result['business']['data']['run_empty_fee_cents'],800,'formal dispute service credits 8 yuan run-empty fee');
    v260FinanceSame((int)$result['business']['data']['auto_refund_cents'],4200,'formal service reports auto refund amount');

    v260FinanceSame((string)$db->fetchValue('SELECT status FROM disputes WHERE order_id=9'),'resolved','fee dispute is resolved');
    v260FinanceSame((int)$db->fetchValue('SELECT refund_amount_cents FROM disputes WHERE order_id=9'),4200,'dispute stores exact refund allocation');
    v260FinanceSame((int)$db->fetchValue('SELECT run_empty_fee_cents FROM disputes WHERE order_id=9'),800,'dispute stores exact run-empty allocation');
    v260FinanceSame((int)$db->fetchValue('SELECT handled_by FROM disputes WHERE order_id=9'),77,'dispute records real web admin id');
    v260FinanceSame((int)$db->fetchValue('SELECT amount_cents FROM refunds WHERE order_id=9'),4200,'one internal refund is registered');
    v260FinanceSame((string)$db->fetchValue('SELECT status FROM refunds WHERE order_id=9'),'pending_review','refund remains pending review');
    v260FinanceSame((int)$db->fetchValue('SELECT requested_by_admin_id FROM refunds WHERE order_id=9'),77,'refund records same admin id');
    v260FinanceSame((int)$db->fetchValue("SELECT amount_cents FROM worker_balance_ledger WHERE business_type='dispute' AND business_id=(SELECT id FROM disputes WHERE order_id=9)"),800,'run-empty fee enters worker ledger');
    v260FinanceSame((int)$db->fetchValue("SELECT worker_id FROM worker_balance_ledger WHERE business_type='dispute' AND business_id=(SELECT id FROM disputes WHERE order_id=9)"),66,'run-empty ledger belongs to assigned worker');
    v260FinanceSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=9'),'cancel_approved_pending_refund','financial adjudication stops order in pending-refund terminal state');
    v260FinanceSame((string)$db->fetchValue('SELECT refund_status FROM orders WHERE id=9'),'pending_review','order refund status is pending review');
    v260FinanceSame((string)$db->fetchValue('SELECT assignment_status FROM orders WHERE id=9'),'cancelled','order assignment is cancelled');
    v260FinanceSame((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=9'),'cancelled','order fulfillment is cancelled');
    v260FinanceSame((string)$db->fetchValue('SELECT status FROM payment_orders WHERE id=1'),'paid','TEST_INTERNAL payment remains paid until refund executor runs');
    v260FinanceSame((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE id=1 AND wechat_prepay_id IS NULL AND wechat_transaction_id IS NULL'),1,'no WeChat payment identifiers are fabricated');
    v260FinanceSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=9 AND wechat_refund_id IS NULL'),1,'no WeChat refund identifier is fabricated');
    v260FinanceSame(DatabaseOrderCancellationRiskRepository::$calls[0]??null,[9,'user',800],'formal service captures cancellation facts before invalidating deal state');
    v260FinanceSame(count(OrderNotificationService::$queued),1,'formal dispute service emits one domain notification request');
    v260FinanceSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_cancellation_risk_scores WHERE order_id=9'),0,'demo finance adjudication does not persist formal risk scores');

    $verifier=new DatabaseTestCenterDisputeFinanceVerificationRepository($db,new V260FinanceNullRepository());
    $verification=$verifier->verify('TINSTFINMYSQL01',77);
    v260FinanceSame((string)$verification['status'],'passed','test-center finance verifier passes on real MySQL facts');
    v260FinanceSame((int)$verification['failed'],0,'finance verifier has zero failed assertions');
    v260FinanceSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_assertion_results WHERE instance_id=501 AND status='failed'"),0,'persisted assertion set has no failures');
    v260FinanceSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_assertion_results WHERE instance_id=501 AND assertion_key IN ('allocation_exact','wechat_refund_not_executed','single_run_empty_ledger','demo_does_not_score')"),4,'critical finance assertions are persisted');

    $replay=$runner->run('TINSTFINMYSQL01','admin_dispute_financial_resolve',$input,77,'mysql-e2e:finance:1');
    v260FinanceSame(!empty($replay['replayed']),true,'same idempotency key replays success after dispute state changed');
    v260FinanceSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=9'),1,'idempotent replay does not duplicate refund');
    v260FinanceSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_type='dispute' AND business_id=(SELECT id FROM disputes WHERE order_id=9)"),1,'idempotent replay does not duplicate run-empty ledger');
    $conflict=false;try{$runner->run('TINSTFINMYSQL01','admin_dispute_financial_resolve',['run_empty_fee_yuan'=>'9.00','note'=>$input['note']],77,'mysql-e2e:finance:1');}catch(TestCenterException $e){$conflict=$e->businessCode()==='TEST_ACTION_IDEMPOTENCY_CONFLICT';}
    v260FinanceSame($conflict,true,'same idempotency key with different amount is rejected');

    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');
    echo "V2.6.0 test center dispute finance MySQL 5.7 E2E: OK\n";
}

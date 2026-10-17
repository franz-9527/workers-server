<?php

declare(strict_types=1);

namespace {
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Testing\TestCenterException;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceCleanupRepository;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($file))require_once$file;
    });

    if(!function_exists('app_config')){function app_config(string $key,$default=null){$map=['app.env'=>'testing'];return array_key_exists($key,$map)?$map[$key]:$default;}}
    if(!function_exists('audit')){function audit(string $action,string $targetType,int $targetId,string $message):void{}}

    function v260CleanupSame($actual,$expected,string $label):void
    {
        if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");exit(1);}
        fwrite(STDOUT,'[PASS] '.$label."\n");
    }
    function v260CleanupTrue(bool $actual,string $label):void{v260CleanupSame($actual,true,$label);}

    final class V260FinanceBaseCleanupRepository implements TestCenterRepository
    {
        private Database $db;
        public function __construct(Database $db){$this->db=$db;}
        public function environmentDiagnostics():array{return['writable'=>true,'environment'=>'testing'];}
        public function listInstances(array $filters=[]):array{return[];}
        public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{throw new RuntimeException('not used');}
        public function verify(string $publicId,int $adminId):array{return[];}
        public function rebuildSource(string $publicId):array{return[];}
        public function instanceDetail(string $publicId):array
        {
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
            if(!$instance)throw new TestCenterException('测试实例不存在。','TEST_INSTANCE_NOT_FOUND',404);
            $order=!empty($instance['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;
            return['instance'=>$instance,'order'=>$order,'cleanup'=>$this->cleanupPreview($publicId)];
        }
        public function cleanupPreview(string $publicId):array
        {
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);
            if(!$instance||empty($instance['order_id']))return['allowed'=>false,'reason'=>'实例业务数据已清理。','counts'=>[],'blocking_relations'=>[],'plan_digest'=>''];
            $orderId=(int)$instance['order_id'];$order=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$orderId]);
            if(!$order||(int)$order['is_demo']!==1||strpos((string)$order['public_no'],'BXDEMOHALL')===0)return['allowed'=>false,'reason'=>'非测试订单。','counts'=>[],'blocking_relations'=>[],'plan_digest'=>''];
            $blocking=[];$counts=['orders'=>1];
            foreach(['refunds','disputes','change_requests','order_role_grants','invite_tokens'] as$table){$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);$counts[$table]=$count;if($count>0)$blocking[$table]=$count;}
            foreach(['assignments','quotes','order_events','order_deadlines'] as$table){$counts[$table]=(int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=:id",['id'=>$orderId]);}
            $paymentCount=(int)$this->db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE order_id=:id',['id'=>$orderId]);
            $owned=(int)$this->db->fetchValue('SELECT COUNT(*) FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND p.order_id=:order_id AND p.wechat_prepay_id IS NULL AND p.wechat_transaction_id IS NULL',['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
            $counts['payment_orders']=$paymentCount;$counts['test_payment_facts']=$owned;if($paymentCount!==$owned)$blocking['payment_orders_unowned']=$paymentCount-$owned;
            $plan=['instance_id'=>(int)$instance['id'],'instance_version'=>(int)$instance['version'],'order_id'=>$orderId,'counts'=>$counts,'blocking'=>$blocking];$json=json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$digest=hash('sha256',is_string($json)?$json:'{}');
            return['allowed'=>empty($blocking),'reason'=>empty($blocking)?'基础订单图可清理。':'存在深层业务关系。','counts'=>$counts,'blocking_relations'=>$blocking,'plan_digest'=>$digest];
        }
        public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array
        {
            $preview=$this->cleanupPreview($publicId);if(empty($preview['allowed']))throw new TestCenterException('基础清理被阻断。','TEST_CLEANUP_BLOCKED',409,$preview);if(!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new TestCenterException('基础清理预览已变化。','TEST_CLEANUP_PREVIEW_STALE',409,$preview);
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id LIMIT 1',['id'=>$publicId]);$orderId=(int)$instance['order_id'];$orderNo=(string)$this->db->fetchValue('SELECT public_no FROM orders WHERE id=:id',['id'=>$orderId]);
            return$this->db->transaction(function(Database $db)use($instance,$orderId,$orderNo,$adminId,$preview):array{
                $db->execute('DELETE FROM assignments WHERE order_id=:id',['id'=>$orderId]);$db->execute('DELETE FROM quotes WHERE order_id=:id',['id'=>$orderId]);$db->execute('DELETE FROM order_events WHERE order_id=:id',['id'=>$orderId]);
                $db->execute('DELETE p FROM payment_orders p JOIN test_payment_facts tf ON tf.payment_order_id=p.id WHERE tf.instance_id=:instance AND p.order_id=:order',['instance'=>(int)$instance['id'],'order'=>$orderId]);$db->execute('DELETE FROM test_payment_facts WHERE instance_id=:instance',['instance'=>(int)$instance['id']]);$db->execute('DELETE FROM orders WHERE id=:id',['id'=>$orderId]);
                $db->execute("UPDATE test_instances SET order_id=NULL,build_status='deleted',verification_status='pending',deleted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>(int)$instance['id']]);return['ok'=>true,'public_id'=>(string)$instance['public_id'],'order_no'=>$orderNo,'deleted_counts'=>$preview['counts'],'operator_admin_id'=>$adminId];
            });
        }
    }

    if(getenv('V260_DISPUTE_FINANCE_CLEANUP_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V260_DB_NAME')!=='worker_test'){fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);}
    $config=['host'=>(string)(getenv('V260_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V260_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V260_DB_USER')?:'root'),'password'=>(string)(getenv('V260_DB_PASSWORD')?:'root')];
    $rootDb=new Database($config);v260CleanupTrue(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,'MySQL 5.7 connected');$dbName='worker_v260_finance_cleanup_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);

    $ddl=[
        "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,order_source_type VARCHAR(24) NOT NULL,workflow_profile VARCHAR(24) NOT NULL,is_demo TINYINT(1) NOT NULL,booking_fee_cents BIGINT UNSIGNED NOT NULL,assigned_worker_id BIGINT UNSIGNED NULL,lifecycle_status VARCHAR(48) NOT NULL,refund_status VARCHAR(24) NOT NULL,dispute_status VARCHAR(24) NOT NULL,assignment_status VARCHAR(24) NOT NULL,fulfillment_status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE disputes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,raised_by_type VARCHAR(24) NOT NULL,raised_by_id BIGINT UNSIGNED NOT NULL,dispute_type VARCHAR(32) NOT NULL,description VARCHAR(1000) NULL,status VARCHAR(24) NOT NULL,responsibility VARCHAR(24) NOT NULL,resolution VARCHAR(1000) NULL,refund_amount_cents BIGINT UNSIGNED NOT NULL,run_empty_fee_cents BIGINT UNSIGNED NOT NULL,handled_by BIGINT UNSIGNED NULL,resolved_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_requests(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE change_confirmations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,change_request_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,decision VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,payer_openid VARCHAR(128) NULL,app_id VARCHAR(64) NULL,mch_id VARCHAR(64) NULL,wechat_prepay_id VARCHAR(128) NULL,wechat_transaction_id VARCHAR(128) NULL) ENGINE=InnoDB",
        "CREATE TABLE refunds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,cancel_request_id BIGINT UNSIGNED NULL,amount_cents BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,requested_by_admin_id BIGINT UNSIGNED NULL,wechat_refund_id VARCHAR(128) NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,worker_id BIGINT UNSIGNED NOT NULL,entry_type VARCHAR(32) NOT NULL,direction VARCHAR(16) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,available_delta_cents BIGINT NOT NULL,frozen_delta_cents BIGINT NOT NULL,business_type VARCHAR(32) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,idempotency_key VARCHAR(128) NOT NULL UNIQUE,status VARCHAR(24) NOT NULL,created_by_admin_id BIGINT UNSIGNED NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE invite_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_role_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,revoked_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE order_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(64) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_cancellation_risk_scores(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_instances(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,scenario_key VARCHAR(160) NOT NULL,order_id BIGINT UNSIGNED NULL,build_status VARCHAR(24) NOT NULL,verification_status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL,deleted_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,simulated_provider VARCHAR(32) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,display_name VARCHAR(100) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_participants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,slot_key VARCHAR(64) NOT NULL) ENGINE=InnoDB"
    ];foreach($ddl as$sql)$db->pdo()->exec($sql);
    $db->execute("INSERT INTO worker_profiles(id,display_name) VALUES(66,'共享测试师傅')");
    $db->execute("INSERT INTO orders(id,public_no,user_id,order_source_type,workflow_profile,is_demo,booking_fee_cents,assigned_worker_id,lifecycle_status,refund_status,dispute_status,assignment_status,fulfillment_status,version,updated_at) VALUES(9,'BXTESTFINCLEAN09',101,'consumer','construction',1,5000,66,'cancel_approved_pending_refund','pending_review','resolved','cancelled','cancelled',2,UTC_TIMESTAMP()),(99,'BXTESTOTHER99',202,'consumer','construction',1,3000,66,'active','none','none','unassigned','not_started',1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO test_instances(id,public_id,scenario_key,order_id,build_status,verification_status,version,deleted_at,updated_at) VALUES(501,'TINSTFINCLEAN01','ordinary.construction.multi_quote.waiting.zero_quotes',9,'built','passed',5,NULL,UTC_TIMESTAMP()),(502,'TINSTOTHER0002','ordinary.construction.multi_quote.waiting.one_quote',99,'built','passed',2,NULL,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO test_participants(instance_id,worker_id,slot_key) VALUES(501,66,'worker_a'),(502,66,'worker_shared')");
    $db->execute("INSERT INTO payment_orders(id,public_no,order_id,business_type,status,amount_cents,payer_openid,app_id,mch_id,wechat_prepay_id,wechat_transaction_id) VALUES(1,'PFINCLEAN01',9,'order_booking','paid',5000,'TEST_INTERNAL:501:101','TEST_INTERNAL','TEST_INTERNAL',NULL,NULL),(2,'POTHER00002',99,'order_booking','paid',3000,'TEST_INTERNAL:502:202','TEST_INTERNAL','TEST_INTERNAL',NULL,NULL)");
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,simulated_provider) VALUES(501,1,'internal_test'),(502,2,'internal_test')");
    $db->execute("INSERT INTO change_requests(id,public_id,order_id,worker_id,status) VALUES(31,'CHGFINCLEAN09',9,66,'rejected')");$db->execute("INSERT INTO change_confirmations(change_request_id,user_id,decision) VALUES(31,101,'rejected')");
    $db->execute("INSERT INTO disputes(id,public_no,order_id,raised_by_type,raised_by_id,dispute_type,description,status,responsibility,resolution,refund_amount_cents,run_empty_fee_cents,handled_by,resolved_at,updated_at) VALUES(41,'DSPFINCLEAN09',9,'user',101,'fee_negotiation','费用争议来源 CHGFINCLEAN09','resolved','user','客户承担跑空费，其余退款',4200,800,77,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->execute("INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,status,requested_by_admin_id,wechat_refund_id) VALUES('RFCLEAN0001',1,9,NULL,4200,'pending_review',77,NULL)");
    $db->execute("INSERT INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,status,created_by_admin_id) VALUES(66,'run_empty_fee','credit',800,800,0,'dispute',41,'dispute:41:run_empty_fee','effective',77)");
    $db->execute("INSERT INTO assignments(order_id,worker_id,status) VALUES(9,66,'cancelled'),(99,66,'accepted')");$db->execute("INSERT INTO quotes(order_id,worker_id,status) VALUES(9,66,'cancelled'),(99,66,'active')");$db->execute("INSERT INTO invite_tokens(order_id,status) VALUES(9,'revoked')");$db->execute("INSERT INTO order_role_grants(order_id,status,revoked_at) VALUES(9,'revoked',UTC_TIMESTAMP())");$db->execute("INSERT INTO order_deadlines(order_id,status,processed_at) VALUES(9,'cancelled',UTC_TIMESTAMP())");$db->execute("INSERT INTO order_events(order_id,event_type) VALUES(9,'dispute_resolved'),(99,'other_event')");

    $cleanup=new DatabaseTestCenterDisputeFinanceCleanupRepository($db,new V260FinanceBaseCleanupRepository($db));$preview=$cleanup->cleanupPreview('TINSTFINCLEAN01');
    v260CleanupSame((bool)$preview['allowed'],true,'owned finance terminal graph is cleanable');v260CleanupSame((int)$preview['stage7_counts']['financial_refunds'],1,'preview counts one finance refund');v260CleanupSame((int)$preview['stage7_counts']['run_empty_ledger'],1,'preview counts one run-empty ledger');v260CleanupSame((int)$preview['stage7_counts']['financial_change_confirmations'],1,'preview owns rejected change confirmation');v260CleanupSame((int)$preview['stage7_counts']['revoked_role_grants'],1,'preview owns revoked role grant');v260CleanupSame((int)$preview['stage7_counts']['revoked_invite_tokens'],1,'preview owns revoked invite token');v260CleanupSame((int)$preview['stage7_counts']['cancelled_deadlines'],1,'preview owns cancelled deadline');
    $db->execute("INSERT INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,status,created_by_admin_id) VALUES(66,'manual_adjustment','credit',1,1,0,'dispute',41,'foreign:41:1','effective',77)");$blocked=$cleanup->cleanupPreview('TINSTFINCLEAN01');v260CleanupSame((bool)$blocked['allowed'],false,'foreign dispute ledger blocks cleanup preview');v260CleanupSame((int)($blocked['blocking_relations']['run_empty_ledger_count']??0),2,'foreign ledger changes owned ledger shape');$blockedExecution=false;try{$cleanup->cleanup('TINSTFINCLEAN01',77,(string)$blocked['plan_digest']);}catch(TestCenterException $e){$blockedExecution=$e->businessCode()==='TEST_DISPUTE_FINANCE_CLEANUP_BLOCKED';}v260CleanupSame($blockedExecution,true,'blocked cleanup refuses execution');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=9'),1,'blocked cleanup leaves target order intact');v260CleanupSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE idempotency_key='foreign:41:1'"),1,'blocked cleanup does not delete foreign ledger');
    $db->execute("DELETE FROM worker_balance_ledger WHERE idempotency_key='foreign:41:1'");$safe=$cleanup->cleanupPreview('TINSTFINCLEAN01');v260CleanupSame((bool)$safe['allowed'],true,'preview becomes cleanable after foreign fact is removed');$result=$cleanup->cleanup('TINSTFINCLEAN01',77,(string)$safe['plan_digest']);v260CleanupSame((bool)$result['ok'],true,'finance cleanup delegates to base order cleanup successfully');
    v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=9'),0,'target order is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM disputes WHERE order_id=9'),0,'finance dispute is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM refunds WHERE order_id=9'),0,'finance refund is deleted');v260CleanupSame((int)$db->fetchValue("SELECT COUNT(*) FROM worker_balance_ledger WHERE business_id=41 AND business_type='dispute'"),0,'run-empty ledger is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM change_requests WHERE order_id=9'),0,'change request is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM change_confirmations WHERE change_request_id=31'),0,'change confirmation is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM invite_tokens WHERE order_id=9'),0,'revoked invite token is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_role_grants WHERE order_id=9'),0,'revoked role grant is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_deadlines WHERE order_id=9'),0,'cancelled deadline is deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE id=1'),0,'owned TEST_INTERNAL payment is deleted by base cleanup');v260CleanupSame((string)$db->fetchValue('SELECT build_status FROM test_instances WHERE id=501'),'deleted','instance remains as deleted audit shell');v260CleanupSame((int)$db->fetchValue('SELECT order_id IS NULL FROM test_instances WHERE id=501'),1,'deleted instance no longer points to order');
    v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=99'),1,'other test instance order remains');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE id=2'),1,'other test instance payment remains');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM test_instances WHERE id=502 AND order_id=99'),1,'other test instance remains attached');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM worker_profiles WHERE id=66'),1,'shared test worker profile is never deleted');v260CleanupSame((int)$db->fetchValue('SELECT COUNT(*) FROM test_participants WHERE worker_id=66'),2,'shared participant mappings are preserved');
    $rootDb->pdo()->exec('DROP DATABASE `'.$dbName.'`');echo "V2.6.0 test center dispute finance cleanup MySQL 5.7 E2E: OK\n";
}

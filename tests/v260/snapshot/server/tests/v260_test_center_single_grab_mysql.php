<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Safety {
    final class TestingEnvironmentPolicy { public static function active(): bool { return true; } }
}

namespace WorkerAdmin\Infrastructure\Agreements {
    final class PlatformWorkerAgreementVersionProvider
    {
        public function __construct($db) {}
        public function currentVersions(): array { return ['worker_service'=>'ci-v1']; }
    }
}

namespace WorkerAdmin\Foundation {
    use RuntimeException;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;

    final class OrderApplicationContainer
    {
        private Database $db;
        public function __construct(Database $db){$this->db=$db;}
        public function deal(): V260SingleGrabDeal{return new V260SingleGrabDeal($this->db);}
    }

    final class V260SingleGrabDeal
    {
        private Database $db;
        public function __construct(Database $db){$this->db=$db;}
        public function execute(string $route,array $actor,array $body): array
        {
            if($route!=='orders.grab')throw new RuntimeException('unexpected route '.$route);
            $this->barrier();
            return $this->db->transaction(function(Database $db)use($actor,$body):array{
                $order=$db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no LIMIT 1 FOR UPDATE',['no'=>(string)$body['order_id']]);
                if(!$order||(string)$order['dispatch_mode']!=='single_grab'||(string)$order['publish_status']!=='published'||(string)$order['assignment_status']!=='unassigned'||!empty($order['current_assignment_id'])){
                    throw new OrderOperationException('该订单已被其他师傅抢走，请刷新查看','ORDER_ALREADY_GRABBED',409);
                }
                $workerId=(int)($actor['worker_id']??0);$amount=(int)$order['expected_fee_cents'];
                $ruleId=$db->insert("INSERT INTO commission_rule_versions(order_id,locked_at,created_at) VALUES(:order_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$order['id']]);
                $quoteId=$db->insert("INSERT INTO quotes(public_id,order_id,worker_id,quote_type,amount_cents,current_version,status,selected_at,created_at,updated_at) VALUES(:public,:order_id,:worker,'worker',:amount,1,'selected',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>'QSG'.bin2hex(random_bytes(8)),'order_id'=>(int)$order['id'],'worker'=>$workerId,'amount'=>$amount]);
                $db->insert("INSERT INTO quote_versions(quote_id,version_no,amount_cents,created_at) VALUES(:quote,1,:amount,UTC_TIMESTAMP())",['quote'=>$quoteId,'amount'=>$amount]);
                $assignmentId=$db->insert("INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,status,commission_rule_version_id,final_amount_cents_snapshot,accepted_at,created_at,updated_at) VALUES(:public,:order_id,:worker,:quote,'single_grab','accepted',:rule,:amount,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>'ASG'.bin2hex(random_bytes(8)),'order_id'=>(int)$order['id'],'worker'=>$workerId,'quote'=>$quoteId,'rule'=>$ruleId,'amount'=>$amount]);
                $db->insert("INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,created_at) VALUES(:assignment,:order_id,:worker,'accepted',UTC_TIMESTAMP())",['assignment'=>$assignmentId,'order_id'=>(int)$order['id'],'worker'=>$workerId]);
                $db->execute("UPDATE orders SET selected_quote_id=:quote,assigned_worker_id=:worker,current_assignment_id=:assignment,commission_rule_version_id=:rule,final_amount_cents=:amount,quote_status='selected',assignment_status='accepted',publish_status='hidden',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['quote'=>$quoteId,'worker'=>$workerId,'assignment'=>$assignmentId,'rule'=>$ruleId,'amount'=>$amount,'id'=>(int)$order['id']]);
                return['message'=>'抢单成功','data'=>['assignment_id'=>$assignmentId,'quote_id'=>$quoteId,'fixed_price'=>true,'amount_cents'=>$amount]];
            });
        }
        private function barrier(): void
        {
            $dir=(string)getenv('V260_SINGLE_GRAB_BARRIER_DIR');if($dir==='')return;
            if(!is_dir($dir)&&!mkdir($dir,0777,true)&&!is_dir($dir))throw new RuntimeException('cannot create barrier');
            file_put_contents($dir.'/ready-'.getmypid(),'1');$until=microtime(true)+5;
            while(microtime(true)<$until){if(count(glob($dir.'/ready-*')?:[])>=2)return;usleep(10000);}throw new RuntimeException('single-grab barrier timeout');
        }
    }
}

namespace {
    use WorkerAdmin\Contracts\TestCenterActionRunner;
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Testing\TestCenterException;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSingleGrabActionRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSingleGrabVerificationRepository;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($file))require_once$file;
    });
    if(!function_exists('app_config')){function app_config(string $key,$default=null){return$key==='app.env'?'testing':($key==='app.timezone'?'Asia/Shanghai':$default);}}
    if(!function_exists('request_id')){function request_id():string{return'v260-single-grab-'.bin2hex(random_bytes(5));}}
    if(!function_exists('public_id')){function public_id():string{return'01SG'.strtoupper(bin2hex(random_bytes(12)));}}

    final class V260SingleGrabNullRunner implements TestCenterActionRunner
    {
        public function available(string $instancePublicId):array{return['items'=>[],'message'=>''];}
        public function run(string $instancePublicId,string $actionKey,array $input,int $adminId,string $requestKey):array{throw new \RuntimeException('unexpected inner action');}
    }
    final class V260SingleGrabNullRepository implements TestCenterRepository
    {
        public function environmentDiagnostics():array{return[];}
        public function listInstances(array $filters=[]):array{return[];}
        public function instanceDetail(string $publicId):array{return[];}
        public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{return[];}
        public function verify(string $publicId,int $adminId):array{return['status'=>'passed','mode'=>'inner'];}
        public function cleanupPreview(string $publicId):array{return[];}
        public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array{return[];}
        public function rebuildSource(string $publicId):array{return[];}
    }

    function sgSame($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");exit(1);}fwrite(STDOUT,'[PASS] '.$label."\n");}
    function sgTrue(bool $value,string $label):void{sgSame($value,true,$label);}

    if(getenv('V260_SINGLE_GRAB_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V260_DB_NAME')!=='worker_test'){
        fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);
    }
    if(!function_exists('pcntl_fork')){fwrite(STDERR,"pcntl is required for the single-grab race E2E.\n");exit(2);}

    $config=['host'=>(string)(getenv('V260_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V260_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V260_DB_USER')?:'root'),'password'=>(string)(getenv('V260_DB_PASSWORD')?:'root')];
    $rootDb=new Database($config);sgTrue(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,'MySQL 5.7 connected');
    $dbName='worker_v260_single_grab_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);

    $ddl=[
        "CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,public_id VARCHAR(80) NOT NULL,display_name VARCHAR(100) NOT NULL,verification_status VARCHAR(24) NOT NULL,cloud_record_mode VARCHAR(24) NOT NULL,lat DECIMAL(10,6) NULL,lng DECIMAL(10,6) NULL) ENGINE=InnoDB",
        "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,order_source_type VARCHAR(24) NOT NULL,workflow_profile VARCHAR(24) NOT NULL,dispatch_mode VARCHAR(24) NOT NULL,is_demo TINYINT(1) NOT NULL,audit_status VARCHAR(24) NOT NULL,publish_status VARCHAR(24) NOT NULL,quote_status VARCHAR(24) NOT NULL,assignment_status VARCHAR(24) NOT NULL,fulfillment_status VARCHAR(24) NOT NULL,payment_status VARCHAR(24) NOT NULL,refund_status VARCHAR(24) NOT NULL,dispute_status VARCHAR(24) NOT NULL,lifecycle_status VARCHAR(24) NOT NULL,expected_fee_type VARCHAR(24) NULL,expected_fee_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,booking_fee_cents BIGINT UNSIGNED NOT NULL,commission_rule_version_id BIGINT UNSIGNED NULL,selected_quote_id BIGINT UNSIGNED NULL,assigned_worker_id BIGINT UNSIGNED NULL,current_assignment_id BIGINT UNSIGNED NULL,final_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,quote_type VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,current_version INT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,selected_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE quote_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id BIGINT UNSIGNED NOT NULL,version_no INT UNSIGNED NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE commission_rule_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,locked_at DATETIME NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,quote_id BIGINT UNSIGNED NULL,assignment_type VARCHAR(24) NOT NULL,status VARCHAR(24) NOT NULL,commission_rule_version_id BIGINT UNSIGNED NULL,final_amount_cents_snapshot BIGINT UNSIGNED NOT NULL DEFAULT 0,accepted_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE assignment_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,assignment_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,action VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,wechat_prepay_id VARCHAR(128) NULL,wechat_transaction_id VARCHAR(128) NULL) ENGINE=InnoDB",
        "CREATE TABLE test_instances(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,scenario_key VARCHAR(160) NOT NULL,order_id BIGINT UNSIGNED NULL,verification_status VARCHAR(24) NOT NULL DEFAULT 'pending',version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_participants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,slot_key VARCHAR(64) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,expected_status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,simulated_provider VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,instance_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(80) NOT NULL,actor_role VARCHAR(24) NOT NULL,operation_mode VARCHAR(24) NOT NULL,idempotency_key VARCHAR(128) NOT NULL,request_digest CHAR(64) NOT NULL,request_id VARCHAR(128) NULL,before_summary_json LONGTEXT NULL,after_summary_json LONGTEXT NULL,result VARCHAR(24) NOT NULL,error_code VARCHAR(128) NULL,error_message VARCHAR(1000) NULL,created_by_admin_id BIGINT UNSIGNED NULL,started_at DATETIME NULL,finished_at DATETIME NULL,duration_ms INT UNSIGNED NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_instance_idem(instance_id,idempotency_key)) ENGINE=InnoDB",
        "CREATE TABLE test_assertion_results(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,assertion_group VARCHAR(64) NOT NULL,assertion_key VARCHAR(128) NOT NULL,status VARCHAR(24) NOT NULL,expected_text VARCHAR(1000) NULL,actual_text VARCHAR(1000) NULL,details_json LONGTEXT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB"
    ];foreach($ddl as$sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO users(id,public_id,status) VALUES(101,'U-CUSTOMER','active'),(201,'U-W1','active'),(202,'U-W2','active'),(203,'U-W3','active')");
    $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,cloud_record_mode,lat,lng) VALUES(11,201,'W1','测试师傅一','approved','demo_local',30.31,120.15),(12,202,'W2','测试师傅二','approved','demo_local',30.32,120.16),(13,203,'W3','测试师傅三','approved','demo_local',30.33,120.17)");
    $db->execute("INSERT INTO orders(id,public_no,user_id,order_source_type,workflow_profile,dispatch_mode,is_demo,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,dispute_status,lifecycle_status,expected_fee_type,expected_fee_cents,booking_fee_cents,version,updated_at) VALUES(9,'BXTESTSGMYSQL09',101,'consumer','construction','single_grab',1,'approved','published','waiting','unassigned','not_started','paid','none','none','active','total',52000,5000,1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO test_instances(id,public_id,scenario_key,order_id,verification_status,version,updated_at) VALUES(501,'TINSTSGMYSQL01','ordinary.construction.single_grab.waiting.open',9,'pending',1,UTC_TIMESTAMP())");
    foreach([['worker_1',201,11],['worker_2',202,12],['worker_3',203,13]]as$row)$db->execute("INSERT INTO test_participants(instance_id,slot_key,user_id,worker_id,expected_status) VALUES(501,:slot,:user,:worker,'approved')",['slot'=>$row[0],'user'=>$row[1],'worker'=>$row[2]]);
    $db->execute("INSERT INTO payment_orders(id,public_no,order_id,business_type,status,amount_cents) VALUES(1,'PSG09',9,'order_booking','paid',5000)");
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,business_type,amount_cents,simulated_provider,status,created_at) VALUES(501,1,'order_booking',5000,'internal_test','paid',UTC_TIMESTAMP())");

    $barrier=sys_get_temp_dir().'/v260-single-grab-'.bin2hex(random_bytes(6));mkdir($barrier,0777,true);putenv('V260_SINGLE_GRAB_BARRIER_DIR='.$barrier);
    $files=[];$pids=[];
    foreach([['worker_1','race:1'],['worker_2','race:2']]as$idx=>$spec){
        $file=$barrier.'/result-'.$idx.'.json';$files[]=$file;$pid=pcntl_fork();
        if($pid===-1){fwrite(STDERR,"fork failed\n");exit(1);}if($pid===0){
            try{$childDb=new Database($config);$runner=new DatabaseTestCenterSingleGrabActionRunner($childDb,new V260SingleGrabNullRunner());$result=$runner->run('TINSTSGMYSQL01','worker_grab',['worker_slot'=>$spec[0]],77,$spec[1]);$payload=['slot'=>$spec[0],'success'=>true,'result'=>$result];}
            catch(TestCenterException $exception){$payload=['slot'=>$spec[0],'success'=>false,'code'=>$exception->businessCode(),'message'=>$exception->getMessage()];}
            file_put_contents($file,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));exit(0);
        }$pids[]=$pid;
    }
    foreach($pids as$pid)pcntl_waitpid($pid,$status);
    putenv('V260_SINGLE_GRAB_BARRIER_DIR');
    $results=[];foreach($files as$file){$decoded=json_decode((string)file_get_contents($file),true);if(!is_array($decoded)){fwrite(STDERR,"missing child result\n");exit(1);}$results[]=$decoded;}
    $successes=array_values(array_filter($results,static function(array $row):bool{return!empty($row['success']);}));
    $losers=array_values(array_filter($results,static function(array $row):bool{return empty($row['success']);}));
    sgSame(count($successes),1,'exactly one concurrent worker wins');
    sgSame(count($losers),1,'exactly one concurrent worker loses');
    sgSame((string)($losers[0]['code']??''),'ORDER_ALREADY_GRABBED','losing worker receives stable formal race code');
    sgSame((int)$db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=9'),1,'race creates only one assignment');
    sgSame((int)$db->fetchValue("SELECT COUNT(*) FROM quotes WHERE order_id=9 AND status='selected'"),1,'race creates only one selected quote');
    sgSame((string)$db->fetchValue('SELECT assignment_status FROM orders WHERE id=9'),'accepted','winner immediately completes fixed-price deal');
    sgSame((int)$db->fetchValue('SELECT final_amount_cents FROM orders WHERE id=9'),52000,'winner preserves expected fixed total');

    $loserSlot=(string)$losers[0]['slot'];$runner=new DatabaseTestCenterSingleGrabActionRunner($db,new V260SingleGrabNullRunner());
    $probe=$runner->run('TINSTSGMYSQL01','worker_grab_competitor',['worker_slot'=>$loserSlot],77,'probe:competitor');
    sgSame((string)($probe['error_code']??''),'ORDER_ALREADY_GRABBED','explicit competitor probe records expected business rejection as pass');
    sgSame((int)$db->fetchValue('SELECT COUNT(*) FROM assignments WHERE order_id=9'),1,'competitor probe does not duplicate assignment');
    $replay=$runner->run('TINSTSGMYSQL01','worker_grab_competitor',['worker_slot'=>$loserSlot],77,'probe:competitor');
    sgTrue(!empty($replay['replayed']),'competitor probe idempotently replays');

    $verifier=new DatabaseTestCenterSingleGrabVerificationRepository($db,new V260SingleGrabNullRepository());$verify=$verifier->verify('TINSTSGMYSQL01',77);
    sgSame((string)$verify['status'],'passed','single-grab verifier passes after race and explicit loser probe');
    sgSame((int)$verify['failed'],0,'single-grab verifier has zero failed assertions');
    sgSame((int)$db->fetchValue("SELECT COUNT(*) FROM test_assertion_results WHERE instance_id=501 AND status='failed'"),0,'persisted single-grab assertions have no failures');

    foreach(glob($barrier.'/*')?:[]as$path)@unlink($path);@rmdir($barrier);$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    echo "V2.6.0 single-grab MySQL 5.7 race E2E: OK\n";
}

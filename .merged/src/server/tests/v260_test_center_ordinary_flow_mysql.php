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

namespace WorkerAdmin\Infrastructure\Persistence {
    final class OrderFormMediaRules
    {
        public static function readiness($db,int $orderId,int $schemaId,string $workflow): array
        { return ['ready'=>true,'reason'=>'ci fixture']; }
    }
}

namespace WorkerAdmin\Infrastructure\Testing {
    use WorkerAdmin\Core\Database;
    final class TestingMediaFixtureFactory
    {
        private Database $db;
        public function __construct(Database $db){$this->db=$db;}
        public function ensureEvidence(int $instanceId,int $orderId,string $purpose,int $adminId): array
        {
            $key='test/test-center/'.$instanceId.'/'.$purpose.'-'.bin2hex(random_bytes(4)).'.jpg';
            $mediaId=$this->db->insert("INSERT INTO media_files(storage_key,status,created_at) VALUES(:key,'active',UTC_TIMESTAMP())",['key'=>$key]);
            $this->db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose,created_at) VALUES(:media,'order',:order_id,:purpose,UTC_TIMESTAMP())",['media'=>$mediaId,'order_id'=>$orderId,'purpose'=>$purpose]);
            $this->db->insert("INSERT INTO test_media_facts(instance_id,media_file_id,purpose,storage_key_snapshot,created_by_admin_id,created_at) VALUES(:instance,:media,:purpose,:key,:admin,UTC_TIMESTAMP())",['instance'=>$instanceId,'media'=>$mediaId,'purpose'=>$purpose,'key'=>$key,'admin'=>$adminId]);
            return ['media_file_id'=>$mediaId,'storage_key'=>$key,'evidence_valid'=>false];
        }
    }
}

namespace WorkerAdmin\Foundation {
    use WorkerAdmin\Core\Database;

    final class OrderApplicationContainer
    {
        private Database $db;
        public function __construct(Database $db){$this->db=$db;}
        public function deal(): V260OrdinaryDeal{return new V260OrdinaryDeal($this->db);}
        public function quoteSelection(): V260OrdinaryQuoteSelection{return new V260OrdinaryQuoteSelection($this->db);}
        public function appointment(): V260OrdinaryAppointment{return new V260OrdinaryAppointment($this->db);}
        public function fulfillment(): V260OrdinaryFulfillment{return new V260OrdinaryFulfillment($this->db);}
    }

    final class V260OrdinaryDeal
    {
        private Database $db; public function __construct(Database $db){$this->db=$db;}
        public function execute(string $route,array $actor,array $body): array
        {
            if($route!=='quotes.create')throw new \RuntimeException('unexpected deal route '.$route);
            return $this->db->transaction(function(Database $db)use($actor,$body):array{
                $order=$db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE',['no'=>(string)$body['order_id']]);
                if(!$order)throw new \RuntimeException('order missing');
                $workerId=(int)($actor['worker_id']??0);$amount=(int)($body['amount_cents']??0);
                $quote=$db->fetch("SELECT * FROM quotes WHERE order_id=:order_id AND worker_id=:worker AND quote_type='worker' ORDER BY id DESC LIMIT 1",['order_id'=>(int)$order['id'],'worker'=>$workerId]);
                if($quote){
                    $version=(int)$quote['current_version']+1;
                    $db->execute("UPDATE quotes SET amount_cents=:amount,current_version=:version,status='active',updated_at=UTC_TIMESTAMP() WHERE id=:id",['amount'=>$amount,'version'=>$version,'id'=>(int)$quote['id']]);
                    $quoteId=(int)$quote['id'];$public=(string)$quote['public_id'];
                }else{
                    $version=1;$public='QCI'.bin2hex(random_bytes(10));
                    $quoteId=$db->insert("INSERT INTO quotes(public_id,order_id,worker_id,quote_type,amount_cents,current_version,status,created_at,updated_at) VALUES(:public,:order_id,:worker,'worker',:amount,1,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>$public,'order_id'=>(int)$order['id'],'worker'=>$workerId,'amount'=>$amount]);
                }
                $db->insert("INSERT INTO quote_versions(quote_id,version_no,amount_cents,created_at) VALUES(:quote,:version,:amount,UTC_TIMESTAMP())",['quote'=>$quoteId,'version'=>$version,'amount'=>$amount]);
                $db->execute("UPDATE orders SET quote_status='quoting',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$order['id']]);
                return ['message'=>'报价成功','data'=>['quote_id'=>$public,'version'=>$version]];
            });
        }
    }

    final class V260OrdinaryQuoteSelection
    {
        private Database $db; public function __construct(Database $db){$this->db=$db;}
        public function execute(array $actor,array $body): array
        {
            return $this->db->transaction(function(Database $db)use($actor,$body):array{
                $quote=$db->fetch("SELECT q.*,o.user_id,o.id order_pk FROM quotes q JOIN orders o ON o.id=q.order_id WHERE BINARY q.public_id=BINARY :quote AND q.status='active' FOR UPDATE",['quote'=>(string)$body['quote_id']]);
                if(!$quote||(int)$quote['user_id']!==(int)($actor['user_id']??0))throw new \RuntimeException('quote unavailable');
                $ruleId=$db->insert("INSERT INTO commission_rule_versions(order_id,locked_at,created_at) VALUES(:order_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$quote['order_pk']]);
                $selectionId=$db->insert("INSERT INTO quote_selections(order_id,quote_id,status,created_at) VALUES(:order_id,:quote,'confirmed',UTC_TIMESTAMP())",['order_id'=>(int)$quote['order_pk'],'quote'=>(int)$quote['id']]);
                $assignmentId=$db->insert("INSERT INTO assignments(public_id,order_id,worker_id,status,commission_rule_version_id,accepted_at,created_at,updated_at) VALUES(:public,:order_id,:worker,'accepted',:rule,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public'=>'ACI'.bin2hex(random_bytes(10)),'order_id'=>(int)$quote['order_pk'],'worker'=>(int)$quote['worker_id'],'rule'=>$ruleId]);
                $db->insert("INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,created_at) VALUES(:assignment,:order_id,:worker,'accepted',UTC_TIMESTAMP())",['assignment'=>$assignmentId,'order_id'=>(int)$quote['order_pk'],'worker'=>(int)$quote['worker_id']]);
                $db->execute("UPDATE quotes SET status=IF(id=:quote,'selected','closed'),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND quote_type='worker'",['quote'=>(int)$quote['id'],'order_id'=>(int)$quote['order_pk']]);
                $db->execute("UPDATE orders SET quote_status='selected',assignment_status='accepted',selected_quote_id=:quote,assigned_worker_id=:worker,current_assignment_id=:assignment,commission_rule_version_id=:rule,final_amount_cents=:amount,publish_status='hidden',fulfillment_status='not_started',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['quote'=>(int)$quote['id'],'worker'=>(int)$quote['worker_id'],'assignment'=>$assignmentId,'rule'=>$ruleId,'amount'=>(int)$quote['amount_cents'],'id'=>(int)$quote['order_pk']]);
                return ['message'=>'已选择报价','data'=>['selection_id'=>$selectionId,'assignment_id'=>$assignmentId]];
            });
        }
    }

    final class V260OrdinaryAppointment
    {
        private Database $db; public function __construct(Database $db){$this->db=$db;}
        public function save(array $actor,array $command): array
        {
            return $this->db->transaction(function(Database $db)use($actor,$command):array{
                $order=$db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE',['no'=>(string)$command['order_id']]);
                if(!$order||(int)$order['assigned_worker_id']!==(int)($actor['worker_id']??0))throw new \RuntimeException('assignment mismatch');
                $id=$db->insert("INSERT INTO appointment_records(order_id,worker_id,appointment_at,note,status,context_type,after_sale_id,wait_customer_notice,created_at,updated_at) VALUES(:order_id,:worker,:at,:note,'recorded','service',NULL,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$order['id'],'worker'=>(int)$actor['worker_id'],'at'=>str_replace('T',' ',(string)$command['appointment_at']).':00','note'=>(string)$command['note']]);
                $db->execute("UPDATE orders SET fulfillment_status='scheduled',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$order['id']]);
                return ['message'=>'预约成功','data'=>['appointment_id'=>$id]];
            });
        }
    }

    final class V260OrdinaryFulfillment
    {
        private Database $db; public function __construct(Database $db){$this->db=$db;}
        public function execute(string $route,array $actor,array $body): array
        {
            if($route==='fulfillment.complete')return $this->complete($actor,$body);
            if($route==='fulfillment.accept')return $this->accept($actor,$body);
            throw new \RuntimeException('unexpected fulfillment route '.$route);
        }
        private function complete(array $actor,array $body): array
        {
            return $this->db->transaction(function(Database $db)use($actor,$body):array{
                $order=$db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE',['no'=>(string)$body['order_id']]);
                if(!$order||(int)$order['assigned_worker_id']!==(int)($actor['worker_id']??0)||(string)$order['fulfillment_status']!=='scheduled')throw new \RuntimeException('completion precondition failed');
                $completionId=$db->insert("INSERT INTO completion_submissions(order_id,worker_id,status,description,created_at,updated_at) VALUES(:order_id,:worker,'submitted',:description,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$order['id'],'worker'=>(int)$actor['worker_id'],'description'=>(string)$body['description']]);
                $db->insert("INSERT INTO order_deadlines(order_id,deadline_type,status,processed_at,created_at,updated_at) VALUES(:order_id,'auto_accept_completion','pending',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$order['id']]);
                $receivableId=$db->insert("INSERT INTO commission_receivables(order_id,worker_id,assignment_id,is_provisional,finalized_at,created_at,updated_at) VALUES(:order_id,:worker,:assignment,1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$order['id'],'worker'=>(int)$actor['worker_id'],'assignment'=>(int)$order['current_assignment_id']]);
                $db->execute("UPDATE orders SET fulfillment_status='submitted',completion_submitted_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$order['id']]);
                return ['message'=>'完工已提交','data'=>['completion_id'=>$completionId,'receivable_id'=>$receivableId]];
            });
        }
        private function accept(array $actor,array $body): array
        {
            return $this->db->transaction(function(Database $db)use($actor,$body):array{
                $order=$db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no FOR UPDATE',['no'=>(string)$body['order_id']]);
                if(!$order||(int)$order['user_id']!==(int)($actor['user_id']??0)||(string)$order['fulfillment_status']!=='submitted')throw new \RuntimeException('accept precondition failed');
                $db->execute("UPDATE completion_submissions SET status='accepted',updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND status='submitted'",['order_id'=>(int)$order['id']]);
                $db->execute("UPDATE commission_receivables SET is_provisional=0,finalized_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",['order_id'=>(int)$order['id']]);
                $db->execute("UPDATE order_deadlines SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND deadline_type='auto_accept_completion' AND status='pending'",['order_id'=>(int)$order['id']]);
                $db->insert("INSERT INTO order_deadlines(order_id,deadline_type,status,processed_at,created_at,updated_at) VALUES(:order_id,'auto_review','pending',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['order_id'=>(int)$order['id']]);
                $db->execute("UPDATE orders SET fulfillment_status='accepted',lifecycle_status='completed',accepted_at=UTC_TIMESTAMP(),version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$order['id']]);
                $db->execute("UPDATE worker_profiles SET completed_orders=(SELECT COUNT(*) FROM orders WHERE assigned_worker_id=:worker_count AND accepted_at IS NOT NULL),updated_at=UTC_TIMESTAMP() WHERE id=:worker",['worker_count'=>(int)$order['assigned_worker_id'],'worker'=>(int)$order['assigned_worker_id']]);
                return ['message'=>'验收完成','data'=>['accepted'=>true]];
            });
        }
    }
}

namespace {
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionSafetyRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentActionRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentCleanupRepository;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentVerificationRepository;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterProgressVerificationRepository;

    $root=dirname(__DIR__,2);
    spl_autoload_register(static function(string $class)use($root):void{
        if(strpos($class,'WorkerAdmin\\')!==0)return;
        $file=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
        if(is_file($file))require_once$file;
    });
    if(!function_exists('app_config')){function app_config(string $key,$default=null){return$key==='app.timezone'?'Asia/Shanghai':($key==='app.env'?'testing':$default);}}
    if(!function_exists('request_id')){function request_id():string{return'v260-flow-'.bin2hex(random_bytes(6));}}
    if(!function_exists('public_id')){function public_id():string{return'01FLOW'.strtoupper(bin2hex(random_bytes(10)));}}
    if(!function_exists('audit')){function audit(...$args):void{}}

    final class V260OrdinaryBaseRepository implements TestCenterRepository
    {
        private Database $db; public function __construct(Database $db){$this->db=$db;}
        public function environmentDiagnostics():array{return[];}
        public function listInstances(array $filters=[]):array{return[];}
        public function instanceDetail(string $publicId):array{
            $instance=$this->db->fetch('SELECT * FROM test_instances WHERE BINARY public_id=BINARY :id',['id'=>$publicId]);
            $order=$instance&&!empty($instance['order_id'])?$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)$instance['order_id']]):null;
            return['instance'=>$instance?:[],'order'=>$order?:null];
        }
        public function build(array $scenario,array $params,int $adminId,string $requestKey,?int $sourceInstanceId=null,?int $rebuiltFromInstanceId=null):array{return[];}
        public function verify(string $publicId,int $adminId):array{return['status'=>'passed','mode'=>'base'];}
        public function rebuildSource(string $publicId):array{return[];}
        public function cleanupPreview(string $publicId):array{
            $detail=$this->instanceDetail($publicId);$instance=(array)($detail['instance']??[]);$order=$detail['order'];
            if(!$instance)return['allowed'=>false,'plan_digest'=>'','reason'=>'missing instance'];
            if(!$order)return['allowed'=>false,'plan_digest'=>'','reason'=>'already cleaned'];
            $orderId=(int)$order['id'];$blocking=[];
            foreach(['appointment_records','commission_receivables'] as$table){$count=(int)$this->db->fetchValue("SELECT COUNT(*) FROM {$table} WHERE order_id=:id",['id'=>$orderId]);if($count>0)$blocking[$table]=$count;}
            $plan=['instance_id'=>(int)$instance['id'],'version'=>(int)$instance['version'],'order_id'=>$orderId,'blocking'=>$blocking,'quotes'=>(int)$this->db->fetchValue('SELECT COUNT(*) FROM quotes WHERE order_id=:id',['id'=>$orderId])];
            return['allowed'=>empty($blocking),'plan_digest'=>hash('sha256',json_encode($plan)),'blocking_relations'=>$blocking,'counts'=>$plan,'reason'=>empty($blocking)?'base facts owned':'fulfillment facts delegated'];
        }
        public function cleanup(string $publicId,int $adminId,string $expectedPlanDigest):array{
            $preview=$this->cleanupPreview($publicId);if(empty($preview['allowed'])||!hash_equals((string)$preview['plan_digest'],$expectedPlanDigest))throw new \RuntimeException('base cleanup stale/blocked');
            $detail=$this->instanceDetail($publicId);$instance=(array)$detail['instance'];$order=(array)$detail['order'];$orderId=(int)$order['id'];
            $mediaIds=$this->db->fetchAll('SELECT media_file_id FROM test_media_facts WHERE instance_id=:instance',['instance'=>(int)$instance['id']]);
            $this->db->execute('DELETE FROM media_links WHERE entity_type=\'order\' AND entity_id=:order_id',['order_id'=>$orderId]);
            $this->db->execute('DELETE FROM test_media_facts WHERE instance_id=:instance',['instance'=>(int)$instance['id']]);
            foreach($mediaIds as$row)$this->db->execute('DELETE FROM media_files WHERE id=:id',['id'=>(int)$row['media_file_id']]);
            foreach(['assignment_history','assignments','quote_selections'] as$table)$this->db->execute("DELETE FROM {$table} WHERE order_id=:id",['id'=>$orderId]);
            $this->db->execute('DELETE qv FROM quote_versions qv JOIN quotes q ON q.id=qv.quote_id WHERE q.order_id=:id',['id'=>$orderId]);
            $this->db->execute('DELETE FROM quotes WHERE order_id=:id',['id'=>$orderId]);
            $this->db->execute('DELETE FROM commission_rule_versions WHERE order_id=:id',['id'=>$orderId]);
            $this->db->execute('DELETE tf FROM test_payment_facts tf JOIN payment_orders p ON p.id=tf.payment_order_id WHERE tf.instance_id=:instance AND p.order_id=:order_id',['instance'=>(int)$instance['id'],'order_id'=>$orderId]);
            $this->db->execute('DELETE FROM payment_orders WHERE order_id=:id',['id'=>$orderId]);
            $this->db->execute('DELETE FROM orders WHERE id=:id',['id'=>$orderId]);
            $this->db->execute("UPDATE test_instances SET order_id=NULL,build_status='deleted',deleted_at=UTC_TIMESTAMP(),verification_status='pending',version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>(int)$instance['id']]);
            return['deleted'=>true,'instance_public_id'=>$publicId,'order_id'=>$orderId];
        }
    }

    function flowSame($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,'[FAIL] '.$label.' expected='.var_export($expected,true).' actual='.var_export($actual,true)."\n");exit(1);}fwrite(STDOUT,'[PASS] '.$label."\n");}
    function flowTrue(bool $actual,string $label):void{flowSame($actual,true,$label);}

    if(getenv('V260_ORDINARY_FLOW_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V260_DB_NAME')!=='worker_test'){fwrite(STDERR,"This test is restricted to GitHub Actions worker_test.\n");exit(2);}
    $config=['host'=>(string)(getenv('V260_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V260_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V260_DB_USER')?:'root'),'password'=>(string)(getenv('V260_DB_PASSWORD')?:'root')];
    $rootDb=new Database($config);flowTrue(strpos((string)$rootDb->fetchValue('SELECT VERSION()'),'5.7.')===0,'MySQL 5.7 connected');
    $dbName='worker_v260_test_center_ordinary_flow_ci';$rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');$rootDb->pdo()->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$config['database']=$dbName;$db=new Database($config);

    $ddl=[
        "CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) NOT NULL,nickname VARCHAR(80) NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,display_name VARCHAR(80) NOT NULL,verification_status VARCHAR(24) NOT NULL,cloud_record_mode VARCHAR(24) NOT NULL,lat DECIMAL(10,6) NULL,lng DECIMAL(10,6) NULL,city VARCHAR(80) NULL,district VARCHAR(80) NULL,completed_orders INT NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY,public_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,is_demo TINYINT(1) NOT NULL,order_source_type VARCHAR(24) NOT NULL,workflow_profile VARCHAR(24) NOT NULL,dispatch_mode VARCHAR(24) NOT NULL,audit_status VARCHAR(24) NOT NULL,publish_status VARCHAR(24) NOT NULL,payment_status VARCHAR(24) NOT NULL,quote_status VARCHAR(24) NOT NULL,assignment_status VARCHAR(24) NOT NULL,fulfillment_status VARCHAR(24) NOT NULL,lifecycle_status VARCHAR(24) NOT NULL,booking_fee_cents BIGINT UNSIGNED NOT NULL,final_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,selected_quote_id BIGINT UNSIGNED NULL,assigned_worker_id BIGINT UNSIGNED NULL,current_assignment_id BIGINT UNSIGNED NULL,commission_rule_version_id BIGINT UNSIGNED NULL,form_schema_version_id BIGINT UNSIGNED NOT NULL DEFAULT 0,completion_submitted_at DATETIME NULL,accepted_at DATETIME NULL,version INT UNSIGNED NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE payment_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) NOT NULL,order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,wechat_prepay_id VARCHAR(128) NULL,wechat_transaction_id VARCHAR(128) NULL) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,quote_type VARCHAR(24) NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,current_version INT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE quote_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,quote_id BIGINT UNSIGNED NOT NULL,version_no INT UNSIGNED NOT NULL,amount_cents BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE quote_selections(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,quote_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE commission_rule_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,locked_at DATETIME NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) NOT NULL UNIQUE,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,commission_rule_version_id BIGINT UNSIGNED NULL,accepted_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE assignment_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,assignment_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,action VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE appointment_records(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,appointment_at DATETIME NULL,note VARCHAR(500) NULL,status VARCHAR(24) NOT NULL,context_type VARCHAR(24) NOT NULL,after_sale_id BIGINT UNSIGNED NULL,wait_customer_notice TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE completion_submissions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,description VARCHAR(1000) NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE order_deadlines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,deadline_type VARCHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,processed_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE commission_receivables(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,assignment_id BIGINT UNSIGNED NOT NULL,is_provisional TINYINT(1) NOT NULL,finalized_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE worker_balance_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,worker_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,business_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE media_files(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,storage_key VARCHAR(255) NOT NULL,status VARCHAR(24) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE media_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,media_file_id BIGINT UNSIGNED NOT NULL,entity_type VARCHAR(32) NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(64) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_instances(id BIGINT UNSIGNED PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,scenario_key VARCHAR(160) NOT NULL,order_id BIGINT UNSIGNED NULL,build_status VARCHAR(24) NOT NULL,verification_status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL DEFAULT 1,archived_at DATETIME NULL,deleted_at DATETIME NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_participants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,slot_key VARCHAR(64) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,worker_id BIGINT UNSIGNED NOT NULL,expected_status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_payment_facts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,payment_order_id BIGINT UNSIGNED NOT NULL,business_type VARCHAR(32) NOT NULL,simulated_provider VARCHAR(32) NOT NULL,status VARCHAR(24) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_media_facts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,media_file_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(64) NOT NULL,storage_key_snapshot VARCHAR(255) NOT NULL,created_by_admin_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE test_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,instance_id BIGINT UNSIGNED NOT NULL,action_key VARCHAR(80) NOT NULL,actor_role VARCHAR(24) NOT NULL,operation_mode VARCHAR(24) NOT NULL,idempotency_key VARCHAR(128) NOT NULL,request_digest CHAR(64) NOT NULL,request_id VARCHAR(128) NULL,before_summary_json LONGTEXT NULL,after_summary_json LONGTEXT NULL,result VARCHAR(24) NOT NULL,error_code VARCHAR(128) NULL,error_message VARCHAR(1000) NULL,created_by_admin_id BIGINT UNSIGNED NULL,started_at DATETIME NULL,finished_at DATETIME NULL,duration_ms INT UNSIGNED NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_test_run_instance_idem(instance_id,idempotency_key)) ENGINE=InnoDB",
        "CREATE TABLE test_assertion_results(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,instance_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,assertion_group VARCHAR(64) NOT NULL,assertion_key VARCHAR(128) NOT NULL,status VARCHAR(24) NOT NULL,expected_text VARCHAR(1000) NULL,actual_text VARCHAR(1000) NULL,details_json LONGTEXT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB"
    ];foreach($ddl as$sql)$db->pdo()->exec($sql);

    $db->execute("INSERT INTO users(id,public_id,nickname) VALUES(101,'U-CUSTOMER','测试客户'),(201,'U-W1','师傅A'),(202,'U-W2','师傅B'),(203,'U-W3','师傅C'),(204,'U-WP','待审核师傅')");
    $db->execute("INSERT INTO worker_profiles(id,public_id,user_id,display_name,verification_status,cloud_record_mode,lat,lng,city,district,completed_orders,updated_at) VALUES(301,'W-A',201,'测试师傅A','approved','demo_local',30.319200,120.150600,'杭州市','拱墅区',0,UTC_TIMESTAMP()),(302,'W-B',202,'测试师傅B','approved','demo_local',30.319300,120.150700,'杭州市','拱墅区',0,UTC_TIMESTAMP()),(303,'W-C',203,'测试师傅C','approved','demo_local',30.319400,120.150800,'杭州市','拱墅区',0,UTC_TIMESTAMP()),(304,'W-P',204,'待审核师傅','pending','demo_local',30.319500,120.150900,'杭州市','拱墅区',0,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO orders(id,public_no,user_id,is_demo,order_source_type,workflow_profile,dispatch_mode,audit_status,publish_status,payment_status,quote_status,assignment_status,fulfillment_status,lifecycle_status,booking_fee_cents,version,updated_at) VALUES(9,'BXTESTFLOWMYSQL09',101,1,'consumer','construction','multi_quote','approved','published','paid','waiting','unassigned','not_started','active',1000,1,UTC_TIMESTAMP()),(10,'BXTESTFLOWOTHER10',101,1,'consumer','construction','multi_quote','approved','published','paid','waiting','unassigned','not_started','active',1000,1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO payment_orders(id,public_no,order_id,business_type,status,amount_cents) VALUES(1,'PFLOW09',9,'order_booking','paid',1000),(2,'PFLOW10',10,'order_booking','paid',1000)");
    $db->execute("INSERT INTO test_instances(id,public_id,scenario_key,order_id,build_status,verification_status,version,updated_at) VALUES(501,'TINSTFLOWMYSQL01','ordinary.construction.multi_quote.waiting.zero_quotes',9,'built','pending',1,UTC_TIMESTAMP()),(502,'TINSTFLOWOTHER02','ordinary.construction.multi_quote.waiting.zero_quotes',10,'built','pending',1,UTC_TIMESTAMP())");
    $db->execute("INSERT INTO test_payment_facts(instance_id,payment_order_id,business_type,simulated_provider,status) VALUES(501,1,'order_booking','internal_test','paid'),(502,2,'order_booking','internal_test','paid')");
    foreach([[501,'worker_a',201,301,'approved'],[501,'worker_b',202,302,'approved'],[501,'worker_c',203,303,'approved'],[501,'pending_worker',204,304,'pending'],[502,'worker_a',201,301,'approved']] as$p)$db->execute("INSERT INTO test_participants(instance_id,slot_key,user_id,worker_id,expected_status) VALUES(:instance,:slot,:user,:worker,:status)",['instance'=>$p[0],'slot'=>$p[1],'user'=>$p[2],'worker'=>$p[3],'status'=>$p[4]]);

    $runner=new DatabaseTestCenterActionSafetyRunner($db,new DatabaseTestCenterFulfillmentActionRunner($db,new DatabaseTestCenterActionRunner($db)));
    $baseRepo=new V260OrdinaryBaseRepository($db);
    $verifier=new DatabaseTestCenterFulfillmentVerificationRepository($db,new DatabaseTestCenterProgressVerificationRepository($db,$baseRepo));
    $cleanup=new DatabaseTestCenterFulfillmentCleanupRepository($db,$baseRepo);

    $quote=$runner->run('TINSTFLOWMYSQL01','worker_quote',['worker_slot'=>'worker_a','amount_yuan'=>'520.00','description'=>'MySQL 正向链报价'],77,'flow:quote:1');
    flowSame($quote['result'],'passed','worker_quote executes through test-center runner');
    flowSame((string)$db->fetchValue("SELECT actor_role FROM test_runs WHERE idempotency_key='flow:quote:1'"),'worker','outer safety runner canonicalizes worker actor_role');
    $check=$verifier->verify('TINSTFLOWMYSQL01',77);flowSame($check['status'],'passed','post-quote verifier passes on MySQL facts');
    $quotePublic=(string)$db->fetchValue("SELECT public_id FROM quotes WHERE order_id=9 AND status='active' ORDER BY id LIMIT 1");

    $selected=$runner->run('TINSTFLOWMYSQL01','customer_select_quote',['quote_id'=>$quotePublic],77,'flow:select:1');
    flowSame($selected['result'],'passed','customer_select_quote executes through test-center runner');
    $check=$verifier->verify('TINSTFLOWMYSQL01',77);flowSame($check['status'],'passed','post-selection verifier passes on MySQL facts');
    flowSame((int)$db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=9 AND status='accepted'"),1,'selection creates one accepted assignment');

    $appointment=$runner->run('TINSTFLOWMYSQL01','worker_appointment',['appointment_at'=>'2030-01-02T10:00','note'=>'MySQL 正向链预约'],77,'flow:appointment:1');
    flowSame($appointment['result'],'passed','worker appointment executes');
    $check=$verifier->verify('TINSTFLOWMYSQL01',77);flowSame($check['status'],'passed','post-appointment verifier passes');
    flowSame((string)$db->fetchValue('SELECT fulfillment_status FROM orders WHERE id=9'),'scheduled','appointment advances order to scheduled');

    $complete=$runner->run('TINSTFLOWMYSQL01','worker_complete',['description'=>'MySQL 正向链完工'],77,'flow:complete:1');
    flowSame($complete['result'],'passed','worker completion executes');
    $check=$verifier->verify('TINSTFLOWMYSQL01',77);flowSame($check['status'],'passed','post-completion verifier passes');
    flowSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=9 AND deadline_type='auto_accept_completion' AND status='pending'"),1,'completion creates pending auto-accept deadline');
    $replay=$runner->run('TINSTFLOWMYSQL01','worker_complete',['description'=>'MySQL 正向链完工'],77,'flow:complete:1');
    flowTrue(!empty($replay['replayed']),'completion same request key replays after state transition');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM completion_submissions WHERE order_id=9'),1,'completion replay does not duplicate submission');

    $accepted=$runner->run('TINSTFLOWMYSQL01','customer_accept_completion',[],77,'flow:accept:1');
    flowSame($accepted['result'],'passed','customer acceptance executes');
    $check=$verifier->verify('TINSTFLOWMYSQL01',77);flowSame($check['status'],'passed','final fulfillment verifier passes');
    flowSame((string)$db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=9'),'completed','acceptance completes lifecycle');
    flowSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=9 AND deadline_type='auto_accept_completion' AND status='pending'"),0,'manual acceptance closes auto-accept deadline');
    flowSame((int)$db->fetchValue("SELECT COUNT(*) FROM order_deadlines WHERE order_id=9 AND deadline_type='auto_review' AND status='pending'"),1,'acceptance creates auto-review deadline');
    flowSame((int)$db->fetchValue('SELECT completed_orders FROM worker_profiles WHERE id=301'),1,'worker completed_orders matches accepted order');

    $preview=$cleanup->cleanupPreview('TINSTFLOWMYSQL01');flowTrue(!empty($preview['allowed']),'fulfillment cleanup preview is allowed after full chain');
    $cleanupResult=$cleanup->cleanup('TINSTFLOWMYSQL01',77,(string)$preview['plan_digest']);flowTrue(!empty($cleanupResult['deleted']),'fulfillment cleanup deletes owned business graph');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=9'),0,'target order removed by cleanup');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM appointment_records WHERE order_id=9'),0,'appointments removed');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM completion_submissions WHERE order_id=9'),0,'completion submissions removed');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM commission_receivables WHERE order_id=9'),0,'commission receivable removed');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM test_media_facts WHERE instance_id=501'),0,'owned media facts removed');
    flowSame((string)$db->fetchValue('SELECT build_status FROM test_instances WHERE id=501'),'deleted','instance remains as deleted tombstone');
    flowSame((int)$db->fetchValue('SELECT order_id IS NULL FROM test_instances WHERE id=501'),1,'deleted tombstone releases order pointer');
    flowSame((int)$db->fetchValue('SELECT completed_orders FROM worker_profiles WHERE id=301'),0,'cleanup recalculates shared worker completed_orders');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=10'),1,'other test instance order is preserved');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM payment_orders WHERE id=2'),1,'other test payment is preserved');
    flowSame((int)$db->fetchValue('SELECT COUNT(*) FROM test_participants WHERE instance_id=501'),4,'shared participant mappings remain for audit/rebuild');

    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
    echo "V2.6.0 test center ordinary construction MySQL 5.7 E2E: OK\n";
}

<?php

declare(strict_types=1);

// 隔离适配器只替代路由装配、资格查询和通知投递；抢单事务、测量策略、核价、预约均加载生产实现。
namespace WorkerAdmin\Infrastructure\Agreements {
    final class PlatformWorkerAgreementVersionProvider {public function __construct($db){}public function currentVersions():array{return['worker_service'=>'ci-v1'];}}
}
namespace WorkerAdmin\Infrastructure\Settings {
    final class GlobalSettingsProvider {public function bool(string $key,bool $default=false):bool{return$default;}public function int(string $key,int $default=0):int{return$default;}}
}
namespace WorkerAdmin\Application\Order {
    final class WorkerQuoteEligibility {public function __construct(...$args){}public function workerCanQuote(array $worker,array $order,...$flags):bool{return$worker['verification_status']==='approved'&&$order['publish_status']==='published'&&$order['assignment_status']==='unassigned';}}
}
namespace WorkerAdmin\Infrastructure\Persistence {
    final class DatabaseOrderQuoteReadRepository {public function __construct($db){}}
    final class DatabaseOrderCancellationRiskRepository {public function __construct($db){}public function recordExistingSignal(...$args):void{}}
    final class PlatformMeasurementUnusedInner implements \WorkerAdmin\Contracts\OrderMeasurementCompletionRepository {
        public function complete(array $actor,array $order,int $hours):array{throw new \RuntimeException('unexpected consumer measurement');}
        public function accept(array $actor,array $order,int $days,int $freeze,int $max):array{throw new \RuntimeException('unexpected consumer accept');}
        public function requestRework(array $actor,array $order,string $reason):array{throw new \RuntimeException('unexpected consumer rework');}
    }
    final class PlatformMeasurementDealHarness {
        use OrderDealAssignmentOperations;
        private \WorkerAdmin\Core\Database $db;private $settings;private $eligibility;
        public function __construct(\WorkerAdmin\Core\Database $db){$this->db=$db;$this->settings=new \WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider();$this->eligibility=new \WorkerAdmin\Application\Order\WorkerQuoteEligibility();}
        public function execute(string $route,array $actor,array $body):array{if($route!=='orders.grab')throw new \RuntimeException('unexpected deal route');return$this->grabOrder($actor,$body);}
        private function resolveOrderFromBody(array $body):array{return$this->db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$body['order_id']]);}
        private function requireApprovedWorker(array $actor):array {if(!empty($actor['is_admin'])||!empty($actor['business_admin']))throw new \RuntimeException('actor inherited admin');$w=$this->workerRow($this->db,(int)$actor['worker_id'],false);if(!$w||$w['verification_status']!=='approved')throw new \RuntimeException('worker invalid');return$w;}
        private function workerRow($db,int $id,bool $lock):?array{return$db->fetch('SELECT * FROM worker_profiles WHERE id=:id'.($lock?' FOR UPDATE':''),['id'=>$id]);}
        private function assertOrderActorBoundary(array $actor,array $worker,array $order,string $action):void{if(empty($actor['is_demo_actor'])||(int)$order['is_demo']!==1)throw new \RuntimeException('domain boundary');}
        private function quoteDistance(...$args):array{return[0.1,'fixture',30];}
        private function platformOrderCanCollectQuotes(array $o):bool{return$o['publish_status']==='published'&&$o['assignment_status']==='unassigned';}
        private function event($db,int $id,string $type,string $actorType,int $actor,array $payload):void{$event=$db->insert('INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:id,:type,:actor_type,:actor,:payload,UTC_TIMESTAMP())',['id'=>$id,'type'=>$type,'actor_type'=>$actorType,'actor'=>$actor,'payload'=>json_encode($payload)]);\WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db,$id,$event,$type);}
    }
}
namespace WorkerAdmin\Services {
    final class OrderNotificationService {
        public static function queuePersistedEvent($db,int $order,int $event,string $type,array $payload=[]):void{$data=json_encode(['order_id'=>$order,'event_key'=>'order-event:'.$event]);$db->insert("INSERT INTO notifications(recipient_type,recipient_id,channel,data_json) VALUES('user',101,'in_app',:data)",['data'=>$data]);}
    }
}
namespace WorkerAdmin\Foundation {
    final class OrderApplicationContainer {
        private \WorkerAdmin\Core\Database $db;
        public function __construct(\WorkerAdmin\Core\Database $db){$this->db=$db;}
        public function deal(){return new \WorkerAdmin\Infrastructure\Persistence\PlatformMeasurementDealHarness($this->db);}
        public function appointment(){return new \WorkerAdmin\Application\Order\OrderAppointment(new \WorkerAdmin\Infrastructure\Persistence\DatabaseOrderAppointmentRepository($this->db));}
        public function fulfillment(){return new PlatformMeasurementFulfillmentHarness($this->db);}
    }
    final class PlatformMeasurementFulfillmentHarness {
        private \WorkerAdmin\Core\Database $db;public function __construct(\WorkerAdmin\Core\Database $db){$this->db=$db;}
        public function execute(string $route,array $actor,array $body):array {
            if(!empty($actor['is_admin'])||!empty($actor['business_admin']))throw new \RuntimeException('actor inherited admin');
            $o=$this->db->fetch('SELECT * FROM orders WHERE public_no=:no',['no'=>$body['order_id']]);
            $r=new \WorkerAdmin\Infrastructure\Persistence\PlatformPricingAwareOrderMeasurementCompletionRepository(new \WorkerAdmin\Infrastructure\Persistence\PlatformMeasurementUnusedInner(),$this->db);
            if($route==='orders.measurement.submit')return$r->complete($actor,$o,48);
            if($route==='orders.measurement.rework')return$r->requestRework($actor,$o,(string)$body['reason']);
            throw new \RuntimeException('unexpected fulfillment route');
        }
    }
}
namespace {
    use WorkerAdmin\Contracts\TestCenterActionRunner;
    use WorkerAdmin\Contracts\TestCenterRepository;
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Testing\TestCenterException;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterPlatformMeasurementActionRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterPlatformMeasurementRepository;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionSafetyRunner;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupGuardRepository;
    use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupSafetyRepository;

    function pmAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('[FAIL] '.$label);echo'[PASS] '.$label."\n";}
    function pmReject(callable $fn,string $code):void{try{$fn();}catch(TestCenterException $e){pmAssert($e->businessCode()===$code,'reject '.$code);return;}catch(OrderOperationException $e){pmAssert($e->apiCode()===$code,'reject '.$code);return;}throw new RuntimeException('expected rejection '.$code);}
    function app_config(string $key,$default=null){if($key==='app.env')return$GLOBALS['pm_environment']??'testing';if($key==='app.timezone')return'Asia/Shanghai';return$default;}
    function public_id():string{return'01PM'.strtoupper(bin2hex(random_bytes(11)));}
    function request_id():string{return'pm-'.bin2hex(random_bytes(8));}
    function base_path(string $path=''):string{return sys_get_temp_dir().'/workers-platform-measurement-ci/'.$path;}
    function audit(...$args):void{}
    if(getenv('V260_PLATFORM_MEASUREMENT_MYSQL_TEST')!=='1'||getenv('GITHUB_ACTIONS')!=='true'||getenv('V260_DB_NAME')!=='worker_test'){fwrite(STDERR,"Restricted to GitHub Actions worker_test.\n");exit(2);}
    $cfg=['host'=>(string)(getenv('V260_DB_HOST')?:'127.0.0.1'),'port'=>(int)(getenv('V260_DB_PORT')?:3306),'database'=>'worker_test','username'=>(string)(getenv('V260_DB_USER')?:'root'),'password'=>(string)(getenv('V260_DB_PASSWORD')?:'root')];
    $root=new Database($cfg);pmAssert(strpos((string)$root->fetchValue('SELECT VERSION()'),'5.7.')===0,'MySQL 5.7 connected');
    $name='worker_v260_platform_measure_ci';$root->pdo()->exec('DROP DATABASE IF EXISTS `'.$name.'`');$root->pdo()->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cfg['database']=$name;$db=new Database($cfg);
    set_exception_handler(static function(\Throwable $e)use($db):void{fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");try{fwrite(STDERR,json_encode($db->fetchAll("SELECT action_key,error_code,error_message FROM test_runs WHERE result='failed' ORDER BY id DESC LIMIT 3"),JSON_UNESCAPED_UNICODE)."\n");}catch(\Throwable $ignored){}exit(1);});
    $ddl=[
        'orders'=>"public_no VARCHAR(80) UNIQUE,user_id BIGINT,workflow_profile VARCHAR(32),dispatch_mode VARCHAR(32),order_source_type VARCHAR(32),is_demo TINYINT,audit_status VARCHAR(32),publish_status VARCHAR(32),quote_status VARCHAR(32),assignment_status VARCHAR(32),fulfillment_status VARCHAR(32),payment_status VARCHAR(32),refund_status VARCHAR(32),dispute_status VARCHAR(32),lifecycle_status VARCHAR(32),booking_fee_cents BIGINT DEFAULT 0,final_amount_cents BIGINT,expected_fee_type VARCHAR(32),expected_fee_cents BIGINT,selected_quote_id BIGINT NULL,assigned_worker_id BIGINT NULL,current_assignment_id BIGINT NULL,commission_rule_version_id BIGINT NULL,commission_rate_bp INT DEFAULT 0,commission_min_cents BIGINT DEFAULT 0,contact_revealed_at DATETIME NULL,completion_submitted_at DATETIME NULL,auto_accept_at DATETIME NULL,accepted_at DATETIME NULL,closed_at DATETIME NULL,pending_measurement_order_id BIGINT NULL,version INT DEFAULT 1,created_at DATETIME NULL,updated_at DATETIME NULL",
        'order_platform_terms'=>"order_id BIGINT,customer_flow_mode VARCHAR(32),customer_pricing_mode VARCHAR(32),customer_contract_total_cents BIGINT,booking_paid_cents BIGINT,worker_settlement_mode VARCHAR(32),worker_labor_cents BIGINT,worker_balance_credit_cents BIGINT DEFAULT 0,worker_balance_freeze_days_snapshot INT DEFAULT 0,settlement_status VARCHAR(32),updated_at DATETIME NULL",
        'order_measurement_states'=>"order_id BIGINT,service_payment_status VARCHAR(32),result_status VARCHAR(32),settlement_status VARCHAR(32),gross_amount_cents BIGINT DEFAULT 0,refunded_amount_cents BIGINT DEFAULT 0,service_rate_bp_snapshot INT DEFAULT 0,service_min_cents_snapshot BIGINT DEFAULT 0,service_fee_cents BIGINT DEFAULT 0,worker_net_cents BIGINT DEFAULT 0,hold_until DATETIME NULL,max_hold_until DATETIME NULL,settled_at DATETIME NULL,submitted_at DATETIME NULL,accepted_at DATETIME NULL,auto_accept_at DATETIME NULL,created_at DATETIME NULL,updated_at DATETIME NULL",
        'quotes'=>"details_json LONGTEXT NULL,public_id VARCHAR(80),order_id BIGINT,quote_type VARCHAR(32),worker_id BIGINT,amount_cents BIGINT,booking_fee_cents BIGINT,description VARCHAR(500),status VARCHAR(32),current_version INT,selected_at DATETIME NULL,created_at DATETIME NULL,updated_at DATETIME NULL",
        'quote_versions'=>"details_json LONGTEXT NULL,quote_id BIGINT,version_no INT,amount_cents BIGINT,booking_fee_cents BIGINT,commission_rate_bp_snapshot INT,commission_min_cents_snapshot BIGINT,rule_acknowledged_at DATETIME,description VARCHAR(500),change_reason VARCHAR(500),operator_type VARCHAR(32),operator_id BIGINT,created_at DATETIME",
        'assignments'=>"public_id VARCHAR(80),order_id BIGINT,worker_id BIGINT,quote_id BIGINT,assignment_type VARCHAR(32),commission_rule_version_id BIGINT,final_amount_cents_snapshot BIGINT,booking_fee_cents_snapshot BIGINT,commission_rate_bp_snapshot INT,commission_min_cents_snapshot BIGINT,estimated_commission_cents BIGINT,estimated_offline_collect_cents BIGINT,estimated_net_income_cents BIGINT,status VARCHAR(32),offered_at DATETIME,accepted_at DATETIME,created_at DATETIME,updated_at DATETIME",
        'assignment_history'=>"assignment_id BIGINT,order_id BIGINT,worker_id BIGINT,action VARCHAR(32),reason VARCHAR(500),actor_type VARCHAR(32),actor_id BIGINT,created_at DATETIME",
        'commission_rule_versions'=>"order_id BIGINT,version_no INT,rate_bp INT,min_fee_cents BIGINT,adjustment_type VARCHAR(32),adjustment_reason VARCHAR(500),status VARCHAR(32),locked_at DATETIME,created_at DATETIME",
        'appointment_records'=>"order_id BIGINT,worker_id BIGINT,appointment_at DATETIME,note VARCHAR(500),status VARCHAR(32),context_type VARCHAR(32),after_sale_id BIGINT NULL,wait_customer_notice TINYINT,created_at DATETIME,updated_at DATETIME",
        'order_events'=>"order_id BIGINT,event_type VARCHAR(80),actor_type VARCHAR(32),actor_id BIGINT NULL,payload_json LONGTEXT,created_at DATETIME",
        'order_measurement_spaces'=>"public_id VARCHAR(80),order_id BIGINT,name VARCHAR(100),sort_order INT,status VARCHAR(32),created_at DATETIME,updated_at DATETIME",
        'media_files'=>"public_id VARCHAR(80),storage_disk VARCHAR(32),storage_key VARCHAR(255),mime_type VARCHAR(80),extension VARCHAR(10),size_bytes BIGINT,width_px INT,height_px INT,sha256 VARCHAR(64),compression_quality INT,metadata_json LONGTEXT,status VARCHAR(32),reference_count INT,created_at DATETIME",
        'media_links'=>"media_file_id BIGINT,entity_type VARCHAR(64),entity_id BIGINT,purpose VARCHAR(64),sort_order INT,created_by_type VARCHAR(32),created_by_id BIGINT,created_at DATETIME",
        'test_media_facts'=>"instance_id BIGINT,media_file_id BIGINT,purpose VARCHAR(64),storage_key_snapshot VARCHAR(255),created_at DATETIME",
        'users'=>"public_id VARCHAR(80),status VARCHAR(32) DEFAULT 'active'",
        'worker_profiles'=>"invited_only TINYINT DEFAULT 0,user_id BIGINT,public_id VARCHAR(80),display_name VARCHAR(80),verification_status VARCHAR(32),cloud_record_mode VARCHAR(32),lat DECIMAL(10,6),lng DECIMAL(10,6),completed_orders INT DEFAULT 0,updated_at DATETIME NULL",
        'test_participants'=>"instance_id BIGINT,slot_key VARCHAR(64),user_id BIGINT,worker_id BIGINT,expected_status VARCHAR(32)",
        'test_batches'=>"status VARCHAR(32),cleanup_at DATETIME NULL,updated_at DATETIME NULL",
        'test_instances'=>"public_id VARCHAR(80),batch_id BIGINT,scenario_key VARCHAR(160),order_id BIGINT NULL,order_no_snapshot VARCHAR(80),build_status VARCHAR(32),verification_status VARCHAR(32),archived_at DATETIME NULL,deleted_at DATETIME NULL,updated_at DATETIME NULL,version INT DEFAULT 1",
        'test_runs'=>"public_id VARCHAR(80),instance_id BIGINT,action_key VARCHAR(100),actor_role VARCHAR(32),operation_mode VARCHAR(32),idempotency_key VARCHAR(128),request_digest VARCHAR(64),request_id VARCHAR(128),before_summary_json LONGTEXT NULL,after_summary_json LONGTEXT NULL,result VARCHAR(32),error_code VARCHAR(128) NULL,error_message VARCHAR(1000) NULL,created_by_admin_id BIGINT,started_at DATETIME,finished_at DATETIME NULL,created_at DATETIME,duration_ms INT NULL,UNIQUE KEY uq_request(instance_id,idempotency_key)",
        'test_assertion_results'=>"instance_id BIGINT,run_id BIGINT,assertion_group VARCHAR(64),assertion_key VARCHAR(128),status VARCHAR(32),expected_text VARCHAR(1000),actual_text VARCHAR(1000),details_json LONGTEXT,created_at DATETIME",
        'order_relations'=>"parent_order_id BIGINT,child_order_id BIGINT,relation_type VARCHAR(64)",
        'order_pricing_items'=>"order_id BIGINT,item_source VARCHAR(32),description VARCHAR(100),note VARCHAR(500),quantity DECIMAL(12,3),unit VARCHAR(20),unit_price_cents BIGINT,amount_cents BIGINT,sort_order INT,status VARCHAR(32),updated_at DATETIME NULL",
        'payment_orders'=>"order_id BIGINT,business_type VARCHAR(32),status VARCHAR(32),amount_cents BIGINT",
        'test_payment_facts'=>"instance_id BIGINT,payment_order_id BIGINT",
        'worker_balance_ledger'=>"business_type VARCHAR(64),business_id BIGINT,entry_type VARCHAR(64),amount_cents BIGINT,available_delta_cents BIGINT,frozen_delta_cents BIGINT,idempotency_key VARCHAR(160),status VARCHAR(24)",
        'message_outbox'=>"payload_json LONGTEXT,business_key VARCHAR(128)",
        'notifications'=>"recipient_type VARCHAR(32),recipient_id BIGINT,channel VARCHAR(32),data_json LONGTEXT",
        'order_worker_contact_reveal_requests'=>"order_id BIGINT,worker_id BIGINT,context_type VARCHAR(32),after_sale_id BIGINT NULL,status VARCHAR(32),review_note VARCHAR(500),reviewed_at DATETIME NULL,updated_at DATETIME NULL",
        'change_requests'=>"order_id BIGINT,status VARCHAR(32),payment_status VARCHAR(32)",
        'order_cancel_requests'=>"order_id BIGINT,status VARCHAR(32),closed_at DATETIME NULL",
        'refunds'=>"order_id BIGINT,status VARCHAR(32)",
        'disputes'=>"order_id BIGINT,status VARCHAR(32)",
        'after_sales'=>"order_id BIGINT,status VARCHAR(32)",
    ];
    foreach($ddl as$table=>$columns)$db->pdo()->exec("CREATE TABLE `{$table}`(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,{$columns}) ENGINE=InnoDB");
    $db->execute("INSERT INTO users(id,public_id) VALUES(101,'PMCUSTOMER'),(201,'PMUSER1'),(202,'PMUSER2')");
    $db->execute("INSERT INTO worker_profiles(id,user_id,public_id,display_name,verification_status,cloud_record_mode,lat,lng) VALUES(301,201,'PMWORKER1','测试师傅A','approved','demo_local',30,120),(302,202,'PMWORKER2','测试师傅B','approved','demo_local',30,120)");
    $db->execute("INSERT INTO orders(id,public_no,user_id,workflow_profile,dispatch_mode,order_source_type,is_demo,audit_status,publish_status,quote_status,assignment_status,fulfillment_status,payment_status,refund_status,dispute_status,lifecycle_status,final_amount_cents,expected_fee_type,expected_fee_cents) VALUES(1,'BXTESTPM1',101,'measurement','single_grab','platform',1,'approved','published','waiting','unassigned','not_started','not_required','none','none','active',50000,'total',20000),(10,'BXOTHER10',101,'construction','multi_quote','consumer',1,'approved','published','waiting','unassigned','not_started','paid','none','none','active',50000,'total',20000)");
    $db->execute("INSERT INTO order_platform_terms(order_id,customer_flow_mode,customer_pricing_mode,customer_contract_total_cents,booking_paid_cents,worker_settlement_mode,worker_labor_cents,settlement_status) VALUES(1,'platform_managed','fixed_total',50000,10000,'worker_collect',20000,'pending')");
    $db->execute("INSERT INTO order_measurement_states(order_id,service_payment_status,result_status,settlement_status) VALUES(1,'paid','editing','not_applicable')");
    $db->execute("INSERT INTO test_batches(id,status) VALUES(1,'active')");
    $db->execute("INSERT INTO test_instances(id,public_id,batch_id,scenario_key,order_id,order_no_snapshot,build_status,verification_status) VALUES(1,'PMINSTANCE',1,:scenario,1,'BXTESTPM1','built','pending')",['scenario'=>DatabaseTestCenterPlatformMeasurementActionRunner::SCENARIO]);
    $db->execute("INSERT INTO test_participants(instance_id,slot_key,user_id,worker_id,expected_status) VALUES(1,'worker_1',201,301,'approved'),(2,'foreign_worker',202,302,'approved')");
    $db->insert("INSERT INTO notifications(recipient_type,recipient_id,channel,data_json) VALUES('user',101,'in_app',:data)",['data'=>'{"order_id":10,"event_key":"unrelated"}']);
    $inner=new class implements TestCenterActionRunner {public function available(string $id):array{return['items'=>[]];}public function run(string $id,string $key,array $input,int $admin,string $request):array{throw new RuntimeException('unexpected delegated action');}};
    $actions=new DatabaseTestCenterActionSafetyRunner($db,new DatabaseTestCenterPlatformMeasurementActionRunner($db,$inner));
    $repoInner=new class($db) implements TestCenterRepository {
        private Database $db;public function __construct(Database $db){$this->db=$db;}public function environmentDiagnostics():array{return[];}public function listInstances(array $f=[]):array{return[];}
        public function instanceDetail(string $id):array{$i=$this->db->fetch('SELECT * FROM test_instances WHERE public_id=:id',['id'=>$id]);$o=$this->db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>(int)($i['order_id']??0)]);return['instance'=>$i,'order'=>$o];}
        public function build(array $s,array $p,int $a,string $k,?int $source=null,?int $rebuilt=null):array{throw new RuntimeException('builder not under this fixture');}
        public function verify(string $id,int $a):array{throw new RuntimeException('unexpected verify delegation');}public function cleanupPreview(string $id):array{return['allowed'=>false,'counts'=>[],'plan_digest'=>''];}public function cleanup(string $id,int $a,string $d):array{throw new RuntimeException('unexpected cleanup delegation');}public function rebuildSource(string $id):array{return[];}
    };
    $repo=new DatabaseTestCenterCleanupSafetyRepository($db,new DatabaseTestCenterCleanupGuardRepository($db,new DatabaseTestCenterPlatformMeasurementRepository($db,$repoInner)));
    pmAssert($repo->verify('PMINSTANCE',7)['failed']===0,'platform waiting verifier');
    $GLOBALS['pm_environment']='production';$writes=(int)$db->fetchValue('SELECT COUNT(*) FROM test_runs');
    pmReject(static function()use($actions){$actions->run('PMINSTANCE','worker_platform_grab',[],7,'production-reject');},'TEST_ACTION_PRODUCTION_BLOCKED');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM test_runs')===$writes,'production rejection writes nothing');$GLOBALS['pm_environment']='testing';
    pmReject(static function()use($actions){$actions->run('PMINSTANCE','worker_platform_grab',['worker_slot'=>'foreign_worker'],7,'foreign-reject');},'TEST_ACTION_WORKER_INVALID');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM assignments')===0,'cross-instance worker cannot assign');
    $actions->run('PMINSTANCE','worker_platform_grab',[],7,'grab');
    pmAssert((int)$db->fetchValue('SELECT final_amount_cents FROM orders WHERE id=1')===50000,'platform grab preserves customer total');
    pmAssert($repo->verify('PMINSTANCE',7)['failed']===0,'platform dealt verifier');
    $at=(new DateTimeImmutable('tomorrow 10:00'))->format('Y-m-d\TH:i');
    $actions->run('PMINSTANCE','worker_platform_measurement_appointment',['appointment_at'=>$at],7,'measure-appointment');
    $actions->run('PMINSTANCE','worker_platform_submit_measurement',[],7,'submit-1');
    pmAssert($repo->verify('PMINSTANCE',7)['failed']===0,'automatic measurement confirmation verifier');
    $actions->run('PMINSTANCE','customer_platform_measurement_rework',['reason'=>'测试退回一次补测'],7,'rework');
    $actions->run('PMINSTANCE','worker_platform_submit_measurement',[],7,'submit-2');
    pmReject(static function()use($actions){$actions->run('PMINSTANCE','customer_platform_measurement_rework',['reason'=>'再次补测应被拒绝'],7,'second-rework');},'TEST_ACTION_STATE_CHANGED');
    $bad=['customer_total'=>'50.00','worker_labor'=>'20.00'];
    pmReject(static function()use($actions,$bad){$actions->run('PMINSTANCE','admin_platform_pricing_review',$bad,7,'invalid-price');},'PLATFORM_CUSTOMER_TOTAL_INVALID');
    pmAssert((string)$db->fetchValue('SELECT workflow_profile FROM orders WHERE id=1')==='measurement','invalid pricing rolls back workflow');
    $price=['customer_total'=>'650.00','worker_labor'=>'300.00'];
    $beforeAssignment=(int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=1');
    $actions->run('PMINSTANCE','admin_platform_pricing_review',$price,7,'price');
    pmAssert((string)$db->fetchValue('SELECT workflow_profile FROM orders WHERE id=1')==='construction','same order advances to construction');
    pmAssert((int)$db->fetchValue('SELECT current_assignment_id FROM orders WHERE id=1')===$beforeAssignment,'original assignment preserved');
    pmAssert((int)$db->fetchValue('SELECT final_amount_cents_snapshot FROM assignments WHERE order_id=1')===30000,'assignment records new worker labor');
    pmAssert($repo->verify('PMINSTANCE',7)['failed']===0,'pricing and archived appointment verifier');
    pmAssert(!empty($actions->run('PMINSTANCE','admin_platform_pricing_review',$price,7,'price')['replayed']),'pricing replays after workflow changed');
    pmAssert((int)$db->fetchValue("SELECT COUNT(*) FROM order_events WHERE event_type='platform_measurement_pricing_reviewed'")===1,'pricing replay does not duplicate event');
    pmReject(static function()use($actions){$actions->run('PMINSTANCE','admin_platform_pricing_review',['customer_total'=>'700','worker_labor'=>'300'],7,'price');},'TEST_ACTION_IDEMPOTENCY_CONFLICT');
    $actions->run('PMINSTANCE','worker_platform_construction_appointment',['appointment_at'=>$at],7,'construction-appointment');
    pmAssert($repo->verify('PMINSTANCE',7)['failed']===0,'construction appointment final verifier');
    pmAssert((int)$db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=1 AND context_type='measurement'")===1,'measurement appointment archived');
    pmAssert((int)$db->fetchValue("SELECT COUNT(*) FROM appointment_records WHERE order_id=1 AND context_type='service'")===1,'construction appointment separate');
    $db->pdo()->exec('CREATE TABLE future_relation(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT) ENGINE=InnoDB');$db->execute('INSERT INTO future_relation(order_id) VALUES(1)');
    pmAssert(empty($repo->cleanupPreview('PMINSTANCE')['allowed']),'unknown dependency blocks cleanup');$db->execute('DELETE FROM future_relation');
    $db->execute('INSERT INTO test_payment_facts(instance_id,payment_order_id) VALUES(1,999)');
    pmAssert(empty($repo->cleanupPreview('PMINSTANCE')['allowed']),'foreign payment fact blocks platform cleanup');$db->execute('DELETE FROM test_payment_facts');
    $quoteId=(int)$db->fetchValue('SELECT selected_quote_id FROM orders WHERE id=1');$db->execute('UPDATE orders SET selected_quote_id=:quote WHERE id=10',['quote'=>$quoteId]);
    pmAssert(empty($repo->cleanupPreview('PMINSTANCE')['allowed']),'foreign order referencing owned quote blocks deletion');$db->execute('UPDATE orders SET selected_quote_id=NULL WHERE id=10');
    $mediaId=(int)$db->fetchValue('SELECT media_file_id FROM test_media_facts WHERE instance_id=1 LIMIT 1');
    $foreignLink=$db->insert("INSERT INTO media_links(media_file_id,entity_type,entity_id,purpose) VALUES(:media,'order',10,'wall')",['media'=>$mediaId]);
    pmAssert(empty($repo->cleanupPreview('PMINSTANCE')['allowed']),'media shared with another order blocks deletion');$db->execute('DELETE FROM media_links WHERE id=:id',['id'=>$foreignLink]);
    $p=$repo->cleanupPreview('PMINSTANCE');pmAssert(!empty($p['allowed']),'owned platform graph cleanup allowed');
    $db->execute("UPDATE appointment_records SET note='changed after preview' WHERE order_id=1 AND context_type='service'");
    pmReject(static function()use($repo,$p){$repo->cleanup('PMINSTANCE',7,$p['plan_digest']);},'TEST_CLEANUP_PREVIEW_STALE');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=1')===1,'stale cleanup writes nothing');
    $p=$repo->cleanupPreview('PMINSTANCE');$repo->cleanup('PMINSTANCE',7,$p['plan_digest']);
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=1')===0,'platform order deleted');
    foreach(['assignments','quotes','order_platform_terms','order_measurement_spaces','appointment_records']as$table)pmAssert((int)$db->fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE order_id=1")===0,'cleanup '.$table);
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM media_files')===0,'owned evidence media deleted');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM orders WHERE id=10')===1,'other order preserved');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM notifications')===1,'order_id 10 notification survives order_id 1 cleanup');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM worker_profiles')===2,'shared workers preserved');
    pmAssert((string)$db->fetchValue('SELECT build_status FROM test_instances WHERE id=1')==='deleted','tombstone retained');
    pmAssert((int)$db->fetchValue('SELECT COUNT(*) FROM test_runs WHERE instance_id=1')>0,'run evidence retained');
    $root->pdo()->exec('DROP DATABASE `'.$name.'`');echo"Platform same-order measurement E2E passed.\n";
}

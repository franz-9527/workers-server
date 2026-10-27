<?php
declare(strict_types=1);
require_once __DIR__.'/support/order_runtime.php';

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDiscoveryRepository;

if (getenv('GITHUB_ACTIONS')!=='true' || getenv('V250_PLATFORM_MYSQL_TEST')!=='1' || getenv('V250_DB_NAME')!=='worker_test') {
    fwrite(STDERR,"Only isolated Actions worker_test is allowed.\n"); exit(2);
}
function reuseSame($actual,$expected,string $message): void {
    if ($actual!==$expected) throw new RuntimeException($message.': '.var_export($actual,true));
    echo "[PASS] $message\n";
}
function app_url($path=''): string { return 'https://synthetic.invalid/'.$path; }
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'database'=>'worker_test','username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'root'];
$root=new Database($config);
reuseSame(strpos((string)$root->fetchValue('SELECT VERSION()'),'5.7.44')===0,true,'MySQL 5.7.44');
$name='worker_v260_reuse_discovery_ci';
$root->execute('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config['database']=$name; $db=new Database($config);
try {
    foreach([
        "CREATE TABLE orders(id BIGINT PRIMARY KEY,public_no VARCHAR(64),user_id BIGINT DEFAULT 1,is_demo TINYINT DEFAULT 0,order_source_type VARCHAR(32) DEFAULT 'consumer',workflow_profile VARCHAR(32) DEFAULT 'construction',dispatch_mode VARCHAR(32) DEFAULT 'multi_quote',audit_status VARCHAR(32) DEFAULT 'approved',payment_status VARCHAR(32) DEFAULT 'paid',lifecycle_status VARCHAR(40) DEFAULT 'active',publish_status VARCHAR(32) DEFAULT 'published',quote_status VARCHAR(32) DEFAULT 'quoting',assignment_status VARCHAR(32) DEFAULT 'unassigned',refund_status VARCHAR(32) DEFAULT 'none',selected_quote_id BIGINT NULL,current_assignment_id BIGINT NULL,assigned_worker_id BIGINT NULL,quote_deadline_at DATETIME NULL,expected_fee_type VARCHAR(32),expected_fee_cents INT DEFAULT 0,expected_fee_text TEXT,category_names_snapshot VARCHAR(255) DEFAULT '墙纸',category_name_snapshot VARCHAR(80),address_summary VARCHAR(255),district VARCHAR(80),quantity_text VARCHAR(80),wall_height_text VARCHAR(80),description TEXT,is_renovation TINYINT NULL,expected_visit_at DATETIME NULL,created_at DATETIME NULL,published_at DATETIME NULL,closed_at DATETIME NULL,updated_at DATETIME NULL,lat DECIMAL(10,6) NULL,lng DECIMAL(10,6) NULL) ENGINE=InnoDB",
        "CREATE TABLE quotes(id BIGINT PRIMARY KEY,order_id BIGINT,worker_id BIGINT,quote_type VARCHAR(32),status VARCHAR(32),amount_cents INT) ENGINE=InnoDB",
        "CREATE TABLE assignments(id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE order_relations(id BIGINT PRIMARY KEY,child_order_id BIGINT,relation_type VARCHAR(64),status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE order_categories(order_id BIGINT,category_id BIGINT,sort_order INT) ENGINE=InnoDB",
        "CREATE TABLE order_answers(id BIGINT AUTO_INCREMENT PRIMARY KEY,order_id BIGINT,field_key_snapshot VARCHAR(80),field_label_snapshot VARCHAR(80),value_json LONGTEXT,display_value TEXT,sort_order INT DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE order_addresses(id BIGINT PRIMARY KEY,order_id BIGINT,province VARCHAR(80),city VARCHAR(80),district VARCHAR(80),address_summary TEXT,address_ciphertext TEXT,contact_name_ciphertext TEXT,contact_mobile_ciphertext TEXT,lat DECIMAL(10,6) NULL,lng DECIMAL(10,6) NULL) ENGINE=InnoDB",
        "CREATE TABLE media_files(id BIGINT PRIMARY KEY,public_id VARCHAR(64),status VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE media_links(id BIGINT PRIMARY KEY,entity_type VARCHAR(64),entity_id BIGINT,media_file_id BIGINT,purpose VARCHAR(32),sort_order INT) ENGINE=InnoDB",
        "CREATE TABLE order_worker_contact_reveal_requests(id BIGINT PRIMARY KEY,order_id BIGINT,user_id BIGINT,worker_id BIGINT,context_type VARCHAR(32),after_sale_id BIGINT NULL,status VARCHAR(32),reviewed_at DATETIME NULL,review_note TEXT) ENGINE=InnoDB",
    ] as $sql) $db->execute($sql);
    $db->execute("INSERT INTO orders(id,public_no) VALUES(100,'SYNTHETIC-HALL')");
    $hall=new DatabaseOrderDiscoveryRepository($db);
    $hallIds=static function() use($hall):array { return array_map('intval',array_column($hall->hallPage(['user_id'=>1],0,'newest',null,null,1,20)['rows'],'id')); };
    reuseSame($hallIds(),[100],'normal open order is discoverable');
    $db->execute("UPDATE orders SET selected_quote_id=7 WHERE id=100");
    reuseSame($hallIds(),[],'selection pointer removes order immediately');
    $db->execute("UPDATE orders SET selected_quote_id=NULL WHERE id=100");
    $db->execute("INSERT INTO quotes VALUES(7,100,9,'worker','selected',52000)");
    reuseSame($hallIds(),[],'selected quote excludes inconsistent order pointers');
    $db->execute("UPDATE quotes SET status='closed'");
    $db->execute("INSERT INTO assignments VALUES(7,100,'accepted')");
    reuseSame($hallIds(),[],'live assignment excludes inconsistent order pointers');
    $db->execute("UPDATE assignments SET status='cancelled'");
    $db->execute("UPDATE orders SET assignment_status='accepted' WHERE id=100");
    reuseSame($hallIds(),[],'assignment state also closes public discovery');
    $db->execute("UPDATE orders SET assignment_status='unassigned',lifecycle_status='cancelled' WHERE id=100");
    reuseSame($hallIds(),[],'cancelled order stays out of hall');

    $db->execute("INSERT INTO orders(id,public_no,lifecycle_status,updated_at,quantity_text,wall_height_text,description,dispatch_mode) VALUES(10,'SYNTHETIC-OLDER','cancelled','2026-01-01','80㎡','3米','旧单施工要求','single_grab'),(11,'SYNTHETIC-LATEST','cancelled','2026-02-01','90㎡','4米','最近一单','multi_quote'),(12,'SYNTHETIC-OTHER','cancelled','2026-03-01','','','','multi_quote'),(13,'SYNTHETIC-PLATFORM','cancelled','2026-04-01','','','','multi_quote'),(14,'SYNTHETIC-DEMO','cancelled','2026-05-01','','','','multi_quote'),(15,'SYNTHETIC-ACTIVE','active','2026-06-01','','','','multi_quote')");
    $db->execute("UPDATE orders SET user_id=2 WHERE id=12");
    $db->execute("UPDATE orders SET order_source_type='platform' WHERE id=13");
    $db->execute("UPDATE orders SET is_demo=1 WHERE id=14");
    $db->execute("INSERT INTO order_addresses VALUES(1,10,'省','市','区','脱敏地址','完整街道88号501室','旧单客户','13900000001',30,120)");
    $db->execute("INSERT INTO order_categories VALUES(10,7,0)");
    $db->execute("INSERT INTO order_answers(order_id,field_key_snapshot,field_label_snapshot,value_json,display_value) VALUES(10,'expected_visit_date','预约日期','\"2020-01-01\"','2020-01-01'),(10,'expected_visit_period','时段','\"morning\"','上午'),(10,'product_model','型号','\"53厘米\"','53厘米'),(10,'service_address','施工地址','\"重复地址\"','重复地址'),(10,'category_ids','服务类目','[7]','墙纸'),(10,'expected_fee','期望总价','520','520'),(10,'expected_fee_type','计价方式','\"total\"','总价')");
    $db->execute("INSERT INTO media_files VALUES(1,'SYNTHETIC-PRODUCT','active'),(2,'SYNTHETIC-WALL','active'),(3,'SYNTHETIC-FOREIGN','active'),(4,'SYNTHETIC-DELETED','deleted')");
    $db->execute("INSERT INTO media_links VALUES(1,'order',10,1,'product',0),(2,'order',10,2,'wall',0),(3,'order',12,3,'product',0),(4,'order',10,4,'product',1)");
    $cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher {
        public function hmac(string $value,string $context):string { return hash('sha256',$value); }
        public function encrypt(string $value,string $context):?string { return $value; }
        public function decrypt(string $value,string $context):string { return $value; }
    };
    $reuse=new class($db,$cipher) {
        use \WorkerAdmin\Infrastructure\Persistence\OrderSubmissionFormSupport;
        private Database $db; private $cipher;
        public function __construct(Database $db,$cipher) { $this->db=$db;$this->cipher=$cipher; }
        public function read(array $actor,string $source=''):?array { return $this->cancelledOrderDraft($actor,$source); }
        public function media(array $actor,array $body):array { return $this->db->transaction(fn()=> $this->resolveReusableMedia($actor,$body)); }
    };
    $actor=['user_id'=>1];
    reuseSame($reuse->read($actor)['id'],'SYNTHETIC-LATEST','default source stays in ordinary owner and data realm');
    $draft=$reuse->read($actor,'SYNTHETIC-OLDER');
    reuseSame($draft['id'],'SYNTHETIC-OLDER','explicit source imports older cancelled order');
    reuseSame($draft['address']['detail'],'完整街道88号501室','reuse decrypts full address instead of masked summary');
    reuseSame($draft['address']['contact_mobile'],'13900000001','reuse retains owner contact');
    reuseSame($draft['answers']['quantity'],'80㎡','canonical order data wins over old answers');
    reuseSame(isset($draft['answers']['expected_visit_date']),false,'expired appointment date is not imported');
    reuseSame(isset($draft['answers']['expected_visit_period']),false,'expired appointment period is not imported');
    reuseSame($draft['dispatch_mode'],'single_grab','reuse retains selected dispatch intent');
    reuseSame(array_column($draft['media']['product'],'id'),['SYNTHETIC-PRODUCT'],'product images grouped and inactive files skipped');
    reuseSame(array_column($draft['media']['wall'],'id'),['SYNTHETIC-WALL'],'wall images grouped');
    reuseSame(strpos($draft['media']['product'][0]['url'],'access_token=')!==false,true,'reused media receives a fresh access signature');
    foreach(['SYNTHETIC-OTHER','SYNTHETIC-PLATFORM','SYNTHETIC-DEMO','SYNTHETIC-ACTIVE','synthetic-older','MISSING'] as $source) {
        try { $reuse->read($actor,$source); throw new RuntimeException('invalid source accepted: '.$source); }
        catch(OrderOperationException $error) { reuseSame($error->apiCode(),'REUSE_ORDER_INVALID','ineligible source rejected: '.$source); }
    }
    reuseSame($reuse->read(['user_id'=>1,'is_demo_actor'=>true],'SYNTHETIC-DEMO')['id'],'SYNTHETIC-DEMO','testing actors reuse their own testing orders');
    $db->execute("UPDATE orders SET lifecycle_status='expired',workflow_profile='measurement' WHERE id=10");
    reuseSame($reuse->read($actor,'SYNTHETIC-OLDER')['answers']['workflow_profile'],'measurement','expired measurement order can be filled again');
    reuseSame(count($reuse->media($actor,['reuse_order_id'=>'SYNTHETIC-OLDER','reuse_media_ids'=>['SYNTHETIC-PRODUCT','SYNTHETIC-WALL']])['media']),2,'actual media resolver permits valid references');
    try { $reuse->media($actor,['reuse_order_id'=>'SYNTHETIC-OLDER','reuse_media_ids'=>['SYNTHETIC-FOREIGN']]); throw new RuntimeException('foreign media accepted'); }
    catch(OrderOperationException $error) { reuseSame($error->apiCode(),'REUSE_MEDIA_INVALID','foreign media cannot be attached to reordered order'); }

    $answers=new class($db) {
        use \WorkerAdmin\Infrastructure\Persistence\OrderDetailFullView;
        private Database $db;
        public function __construct(Database $db) { $this->db=$db; }
        public function read():array { return $this->orderAnswers(10); }
    };
    reuseSame(array_column($answers->read(),'label'),['型号'],'system address, category, expected fee and appointment do not repeat in product details');
    reuseSame((int)$db->fetchValue('SELECT COUNT(*) FROM order_answers'),7,'display deduplication preserves source answers');

    $GLOBALS['order_test_settings']['order.worker_contact_reveal_wait_hours']=4;
    $contact=new class($db) {
        use \WorkerAdmin\Infrastructure\Persistence\OrderDetailSettlementViews;
        private Database $db;
        public function __construct(Database $db) { $this->db=$db; }
        private function localTime($value) { return $value; }
        public function read(int $hours):array { return $this->workerContactRequest(['id'=>10,'user_id'=>1,'lifecycle_status'=>'active','fulfillment_status'=>'not_started'],['worker_db_id'=>9,'status'=>'accepted','accepted_at'=>gmdate('Y-m-d H:i:s',time()-$hours*3600)],true,false,false,false,[],null,null,false,false); }
    };
    reuseSame($contact->read(3)['wait_hours'],4,'detail exposes configured waiting hours');
    reuseSame($contact->read(3)['can_request'],false,'configured wait blocks early request in detail');
    reuseSame($contact->read(5)['can_request'],true,'configured wait allows overdue contact request');
    $db->execute("INSERT INTO order_worker_contact_reveal_requests VALUES(1,10,1,9,'service',NULL,'rejected',UTC_TIMESTAMP(),'需继续等待')");
    reuseSame($contact->read(5)['can_request'],false,'rejected request restarts configured waiting period');
    echo "V2.6.0 hall, cancelled-order reuse and contact integration OK\n";
} finally { $root->execute('DROP DATABASE '.$name); }

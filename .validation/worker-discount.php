<?php
declare(strict_types=1);

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\OrderSubmissionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWorkerOrderDiscountRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderPaidPaymentSettlement;
use WorkerAdmin\Infrastructure\Persistence\PolicyAwareOrderSubmissionRepository;
use WorkerAdmin\Support\SqlScriptRunner;

$root=dirname(__DIR__);
spl_autoload_register(static function($class)use($root){
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen('WorkerAdmin\\'))).'.php';
    if(is_file($file))require $file;
});
function public_id(): string {return bin2hex(random_bytes(13));}
function check(bool $value,string $message):void {
    if(!$value)throw new RuntimeException($message);
    echo "PASS $message\n";
}
function denied(callable $call, string $code):void{
    try{$call();}catch(OrderOperationException $e){check($e->apiCode()===$code,$code);return;}
    throw new RuntimeException('Expected '.$code);
}
$db=new Database(['host'=>'127.0.0.1','port'=>(int)getenv('DB_PORT'),'database'=>'discount_fixture','username'=>'root','password'=>'discount-fixture-only']);
$pdo=$db->pdo();
$pdo->exec('ALTER DATABASE discount_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec("CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,mobile_ciphertext VARCHAR(32)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE worker_profiles(id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED UNIQUE,verification_status VARCHAR(24),cloud_record_mode VARCHAR(24),display_name VARCHAR(64),public_id CHAR(26)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE merchant_profiles(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id CHAR(26),user_id BIGINT UNSIGNED UNIQUE,verification_status VARCHAR(24),recognition_level VARCHAR(24),order_count_7d INT DEFAULT 0,same_phone_warning_count INT DEFAULT 0,risk_score INT DEFAULT 0,reviewed_at DATETIME,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(26),user_id BIGINT UNSIGNED,merchant_profile_id BIGINT UNSIGNED,workflow_profile VARCHAR(24) DEFAULT 'construction',order_source_type VARCHAR(24) DEFAULT 'consumer',is_demo TINYINT DEFAULT 0,payment_status VARCHAR(24) DEFAULT 'awaiting_payment',lifecycle_status VARCHAR(24) DEFAULT 'active',refund_status VARCHAR(24) DEFAULT 'none',audit_status VARCHAR(24) DEFAULT 'draft',commission_rate_bp INT DEFAULT 1000,commission_min_cents INT DEFAULT 5000,created_at DATETIME NOT NULL,updated_at DATETIME,owner_mobile_base_hmac CHAR(64),merchant_risk_level VARCHAR(24)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE merchant_risk_events(merchant_profile_id BIGINT,user_id BIGINT,order_id BIGINT,event_type VARCHAR(64),risk_level VARCHAR(24),signal_value INT,details_json TEXT,status VARCHAR(24),created_at DATETIME) ENGINE=InnoDB");
$runner=new SqlScriptRunner();
for($i=0;$i<2;$i++)$runner->runFile($pdo,$root.'/server/database/upgrades/2.7.5.sql');
check($db->columnExists('orders','worker_order_discount'),'migration installs and replays');
$pdo->exec("INSERT INTO users VALUES(1,'13800138000'),(2,''),(3,''),(4,'')");
$pdo->exec("INSERT INTO worker_profiles VALUES(10,1,'approved','cloud_authoritative','示例师傅','W1'),(20,2,'pending','cloud_authoritative','待审','W2'),(30,3,'approved','demo_local','演示','W3')");
$repo=new DatabaseWorkerOrderDiscountRepository($db);
$actor=['user_id'=>1];
check($repo->status($actor)['status']==='none'&&$repo->status(['user_id'=>2])===null&&$repo->status(['user_id'=>3])===null&&$repo->status(['user_id'=>4])===null,'only approved non-demo worker sees application');
$body=['wechat_nickname'=>'施工卖货示例','conditions_confirmed'=>true];
denied(fn()=>$repo->submit(['user_id'=>2],$body),'WORKER_ORDER_DISCOUNT_INELIGIBLE');
denied(fn()=>$repo->submit($actor,array_replace($body,['conditions_confirmed'=>false])),'WORKER_ORDER_DISCOUNT_INVALID');
check($repo->submit($actor,$body)['status']==='pending'&&$repo->submit($actor,$body)['status']==='pending','repeated submission stays pending');
check($repo->page('pending',1)['total']===1,'review queue contains the application');
try{$repo->review(10,'approved','',[],7);throw new LogicException('approval without checks');}catch(RuntimeException $e){check($repo->status($actor)['status']==='pending','all three review checks required');}
$checks=['wechat_added'=>'1','nickname_valid'=>'1','moments_verified'=>'1'];
$repo->review(10,'rejected','请完善朋友圈',[],7);
check($repo->status($actor)['review_reason']==='请完善朋友圈','rejection visible to applicant');
$repo->submit($actor,$body);
$repo->review(10,'approved','',$checks,7);
check($repo->approvedAt($actor)!==null,'worker qualification approved');
$settings=new class implements SettingsProvider {
    public function get(string $key,$default=null){return $this->int($key,(int)$default);}
    public function bool(string $key,bool $default=false):bool{return $default;}
    public function int(string $key,int $default=0):int{return ['commission.default_rate_bp'=>1100,'commission.minimum_cents'=>5500,'commission.merchant_verified_rate_bp'=>750,'commission.merchant_minimum_cents'=>4000][$key]??$default;}
};
$cipher=new class implements PrivateValueCipher {
    public function encrypt(string $value,string $context):?string{return $value;}
    public function decrypt(string $value,string $context):string{return $value;}
    public function hmac(string $value,string $context):string{return hash_hmac('sha256',$value,$context);}
};
$inner=new class($db) implements OrderSubmissionRepository {
    public function __construct(private Database $db){}
    public function formContext(array $actor,string $reuseOrderNo=''):array{return ['merchant'=>['verification_status'=>'none']];}
    public function createDraft(array $actor,array $body):array{
        return ['internal_id'=>$this->db->insert("INSERT INTO orders(user_id,workflow_profile,created_at) VALUES(:user,:workflow,UTC_TIMESTAMP())",['user'=>$actor['user_id'],'workflow'=>$body['answers']['workflow_profile']??'construction'])];
    }
    public function findOwnedOrder(array $actor,string $publicNo):?array{return null;}
    public function mediaCounts(int $orderId):array{return [];}
    public function imageRule(int $schemaVersionId,string $purpose):array{return [];}
    public function markReadyForPayment(array $actor,array $order,array $versions,array $counts):void{}
    public function supplement(array $actor,array $body):void{}
    public function promotePendingMedia(int $orderId):void{}
};
$orders=new PolicyAwareOrderSubmissionRepository($inner,$db,$settings,$cipher);
$payment=new DatabaseOrderPaidPaymentSettlement($db,$settings);
$price=new ReflectionMethod($payment,'applyCertifiedMerchantRateOnBookingPayment');
$read=fn($id)=>$db->fetch('SELECT * FROM orders WHERE id=:id',['id'=>$id]);
$draft=fn($mobile='13700137000',$workflow='construction')=>(int)$orders->createDraft($actor,['address'=>['contact_mobile'=>$mobile],'answers'=>['workflow_profile'=>$workflow]])['internal_id'];
$settle=function($id)use($db,$read,$payment,$price){
    $db->transaction(function()use($db,$read,$payment,$price,$id){
        $db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE',['id'=>$id]);
        $price->invoke($payment,$read($id));
        $db->execute("UPDATE orders SET payment_status='paid',audit_status='pending' WHERE id=:id",['id'=>$id]);
    });
    return $read($id);
};
check(!empty($orders->formContext($actor)['merchant']['worker_order_discount']),'approved account gets merchant order form');
denied(fn()=>$draft('13800138000'),'MERCHANT_OWNER_PHONE_SAME');
$measure=$draft('13700137000','measurement');
check($read($measure)['order_source_type']==='consumer'&&(int)$read($measure)['worker_order_discount']===0,'measurement retains original flow');
$first=$draft();
$second=$draft();
check($read($first)['order_source_type']==='merchant'&&(int)$read($first)['worker_order_discount']===1&&(int)$read($first)['commission_rate_bp']===1100,'ordinary draft automatically uses merchant flow with original rate');
check($db->fetchValue('SELECT verification_status FROM merchant_profiles WHERE user_id=1')==='none','business license status preserved');
check((int)$settle($first)['commission_rate_bp']===1100,'first valid order has original rate');
$paid=$settle($second);
check((int)$paid['commission_rate_bp']===750&&(int)$paid['commission_min_cents']===4000,'second valid order uses configured merchant rate and minimum');
$pdo->exec("UPDATE orders SET refund_status='succeeded' WHERE id=$first");
$pdo->exec("UPDATE orders SET audit_status='rejected' WHERE id=$second");
$third=$draft();
check((int)$settle($third)['commission_rate_bp']===1100,'refunded and rejected orders do not count');
$fourth=$draft();
$repo->review(10,'suspended','暂停',[],7);
check((int)$settle($fourth)['commission_rate_bp']===1100,'suspended approval cannot discount an existing unpaid draft');
$repo->review(10,'approved','',$checks,7);
check((int)$settle($draft())['commission_rate_bp']===750,'restoring approval preserves the valid-order history');
$pdo->exec("UPDATE worker_profiles SET verification_status='suspended' WHERE id=10");
check($repo->status($actor)===null&&$repo->approvedAt($actor)===null,'loss of worker approval hides application and stops new discounts');

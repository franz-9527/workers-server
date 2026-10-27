<?php
declare(strict_types=1);
namespace WorkerAdmin\Services {
 final class OrderNotificationService {
  public static function queue($db,int $orderId,string $key,string $type,array $payload=[]):void{if(!empty($GLOBALS['paymentNotificationFault']))throw new \RuntimeException('injected notification fault');}
 }
}
namespace {
if(getenv('PAYMENT_RETIREMENT_MYSQL_TEST')!=='1'){fwrite(STDERR,"Set PAYMENT_RETIREMENT_MYSQL_TEST=1 for an isolated MySQL 5.7 server.\n");exit(2);}
$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$p=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($p))require_once $p;});
$checks=0;
function paymentAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);++$GLOBALS['checks'];}
function paymentReject(callable $call,string $message):void{try{$call();throw new LogicException('expected rejection');}catch(RuntimeException $e){paymentAssert(strpos($e->getMessage(),$message)!==false,'reject '.$message.'; actual: '.$e->getMessage());}}
function public_id():string{return bin2hex(random_bytes(12));}
function setting($key,$default=null){return $default;}
function app_config($key,$default=null){return $default;}
function write_log(...$values){}
final class PersistedPaymentSettings implements \WorkerAdmin\Contracts\SettingsProvider {
 public function get(string $key,$default=null){return ['wechat.miniapp_appid'=>'wx-test','wechat.pay.mchid'=>'m-test'][$key]??$default;}
 public function bool(string $key,bool $default=false):bool{return $default;}
 public function int(string $key,int $default=0):int{return $default;}
}
final class PersistedPaymentGateway implements \WorkerAdmin\Contracts\PaymentGateway {
 public \WorkerAdmin\Core\Database $db;public int $created=0;public int $reused=0;public array $flags=[];
 public function __construct($db){$this->db=$db;}
 public function apiVersion():string{return 'v3';}
 public function enabled():bool{return true;}
 public function createJsapiPayment(string $no,string $description,int $amount,string $openid):array{paymentAssert(!$this->db->pdo()->inTransaction(),'gateway outside creation transaction');$this->created++;return ['prepay_id'=>'prepay-'.$this->created,'payment'=>['server_amount'=>$amount]];}
 public function clientPaymentParameters(string $prepay):array{$this->reused++;return ['prepay'=>$prepay];}
 public function queryPayment(string $no):array{return [];}
 public function createRefund(string $p,string $r,int $a,int $total,string $reason):array{throw new RuntimeException('unexpected external refund');}
 public function queryRefund(string $no):array{return [];}
 public function decodeNotification(string $raw,array $headers,bool $refund=false):array{$this->flags[]=$refund;$value=json_decode($raw,true);$value['headers']=$headers;return $value;}
}
final class PersistedPaymentGatewayFactory implements \WorkerAdmin\Contracts\PaymentGatewayFactory {
 private PersistedPaymentGateway $gateway;
 public function __construct($gateway){$this->gateway=$gateway;}
 public function defaultGateway():\WorkerAdmin\Contracts\PaymentGateway{return $this->gateway;}
 public function forVersion(string $version):\WorkerAdmin\Contracts\PaymentGateway{return $this->gateway;}
}
function paymentRaw(string $id,array $resource):string{return json_encode(['notice'=>['id'=>$id,'event_type'=>'TEST'],'resource'=>$resource]);}
$config=['host'=>getenv('V250_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('V250_DB_PORT')?:3306),'username'=>getenv('V250_DB_USER')?:'root','password'=>getenv('V250_DB_PASSWORD')?:'','database'=>'worker_payment_retirement_test'];
$server=new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
paymentAssert(strpos((string)$server->query('SELECT VERSION()')->fetchColumn(),'5.7.')===0,'MySQL 5.7 required');$created=false;
try{
 $server->exec('CREATE DATABASE worker_payment_retirement_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 $db=new \WorkerAdmin\Core\Database($config);$other=new \WorkerAdmin\Core\Database($config);
 $db->execute("CREATE TABLE orders(id INT PRIMARY KEY,public_no VARCHAR(80),user_id INT,lifecycle_status VARCHAR(30) DEFAULT 'active',refund_status VARCHAR(30) DEFAULT 'none',updated_at DATETIME,closed_at DATETIME NULL,version INT DEFAULT 1) ENGINE=InnoDB");
 $db->execute("CREATE TABLE payment_orders(id INT AUTO_INCREMENT PRIMARY KEY,public_no VARCHAR(80) UNIQUE,business_type VARCHAR(40),business_id INT,order_id INT NULL,payer_user_id INT,payer_openid VARCHAR(80),app_id VARCHAR(80),mch_id VARCHAR(80),api_version VARCHAR(10),amount_cents INT,status VARCHAR(30),client_ip VARCHAR(80),description TEXT,expires_at DATETIME NULL,wechat_prepay_id VARCHAR(100) NULL,wechat_transaction_id VARCHAR(100) NULL,paid_at DATETIME NULL,created_at DATETIME,updated_at DATETIME) ENGINE=InnoDB");
 $db->execute("CREATE TABLE run_empty_fee_claims(id INT PRIMARY KEY,public_id VARCHAR(80),order_id INT,worker_id INT,order_source_type VARCHAR(30),amount_cents INT,status VARCHAR(30),paid_at DATETIME NULL,updated_at DATETIME) ENGINE=InnoDB");
 $db->execute("CREATE TABLE worker_balance_ledger(id INT AUTO_INCREMENT PRIMARY KEY,worker_id INT,entry_type VARCHAR(40),direction VARCHAR(20),amount_cents INT,available_delta_cents INT,frozen_delta_cents INT,business_type VARCHAR(40),business_id INT,idempotency_key VARCHAR(120) UNIQUE,description TEXT,status VARCHAR(30),created_at DATETIME) ENGINE=InnoDB");
 $db->execute("CREATE TABLE order_events(id INT AUTO_INCREMENT PRIMARY KEY,order_id INT,event_type VARCHAR(80),event_key VARCHAR(120) UNIQUE,actor_type VARCHAR(30),actor_id INT NULL,payload_json TEXT,created_at DATETIME) ENGINE=InnoDB");
 $db->execute("CREATE TABLE payment_notifications(id INT AUTO_INCREMENT PRIMARY KEY,notification_id VARCHAR(128) UNIQUE,event_type VARCHAR(64),resource_type VARCHAR(64),headers_json TEXT,payload_ciphertext TEXT,payload_digest CHAR(64),signature_verified TINYINT,process_status VARCHAR(30),payment_order_id INT NULL,error_message TEXT,received_at DATETIME,processed_at DATETIME NULL) ENGINE=InnoDB");
 $db->execute("CREATE TABLE refunds(id INT PRIMARY KEY,public_no VARCHAR(80) UNIQUE,payment_order_id INT,order_id INT NULL,cancel_request_id INT NULL,amount_cents INT,status VARCHAR(30),reason TEXT,wechat_refund_id VARCHAR(80) NULL,failure_code VARCHAR(80) NULL,failure_message TEXT,succeeded_at DATETIME NULL,updated_at DATETIME) ENGINE=InnoDB");
 $db->execute("CREATE TABLE change_requests(id INT PRIMARY KEY,payment_status VARCHAR(30),updated_at DATETIME) ENGINE=InnoDB");
 $db->execute("INSERT INTO orders(id,public_no,user_id) VALUES(1,'ORDER-1',7),(2,'ORDER-2',7),(4,'ORDER-4',7)");
 $db->execute("INSERT INTO run_empty_fee_claims VALUES(1,'CLAIM-1',1,77,'merchant',100,'awaiting_payment',NULL,NULL),(2,'CLAIM-2',2,77,'merchant',150,'awaiting_payment',NULL,NULL)");
 $settings=new PersistedPaymentSettings();$gateway=new PersistedPaymentGateway($db);$tx=new \WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager($db);
 $creation=new \WorkerAdmin\Application\Money\PaymentCreation(new \WorkerAdmin\Infrastructure\Persistence\DatabasePaymentCreationRepository($db,$settings),$gateway,$tx);
 $actor=['user_id'=>7,'payer_openid'=>'payer-7'];$result=$creation->create($actor,['run_empty_claim_id'=>'CLAIM-1','amount_cents'=>999999],'127.0.0.1');$no=$result['data']['payment_no'];
 paymentAssert($result['data']['payment']['server_amount']===100 && $db->fetchValue('SELECT status FROM payment_orders WHERE public_no=:no',['no'=>$no])==='pending','server amount and committed prepay write');
 $creation->create($actor,['run_empty_claim_id'=>'CLAIM-1'],'127.0.0.1');paymentAssert($gateway->created===1 && $gateway->reused===1,'active payment and prepay reused');
 paymentReject(static function()use($creation):void{$creation->create(['user_id'=>8,'payer_openid'=>'payer-8'],['run_empty_claim_id'=>'CLAIM-1'],'127.0.0.1');},'不属于');
 $db->execute('UPDATE payment_orders SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE public_no=:no',['no'=>$no]);$new=$creation->create($actor,['run_empty_claim_id'=>'CLAIM-1'],'127.0.0.1');
 paymentAssert($new['data']['payment_no']!==$no && $db->fetchValue('SELECT status FROM payment_orders WHERE public_no=:no',['no'=>$no])==='closed','expired payment closed and replaced');$no=$new['data']['payment_no'];
 $notice=new \WorkerAdmin\Infrastructure\Persistence\DatabasePaymentNotificationRepository($db);$settlement=new \WorkerAdmin\Infrastructure\Persistence\DatabasePaidPaymentSettlement($db,$settings);
 $processor=new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($notice,$settlement,new PersistedPaymentGatewayFactory($gateway),$tx);
 $resource=['trade_state'=>'SUCCESS','out_trade_no'=>$no,'appid'=>'wx-test','mchid'=>'m-test','amount'=>['total'=>100],'payer'=>['openid'=>'payer-7'],'transaction_id'=>'WX-1'];
 $GLOBALS['paymentNotificationFault']=true;paymentReject(static function()use($processor,$resource):void{$processor->process(paymentRaw('failed-business',$resource),[]);},'notification fault');$GLOBALS['paymentNotificationFault']=false;
 paymentAssert($db->fetchValue('SELECT status FROM payment_orders WHERE public_no=:no',['no'=>$no])==='pending' && (int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger')===0 && (int)$db->fetchValue('SELECT COUNT(*) FROM order_events')===0,'payment, ledger and business event roll back together');
 paymentAssert($db->fetchValue("SELECT process_status FROM payment_notifications WHERE notification_id='failed-business'")==='failed','failed business notification remains retryable');
 foreach([['amount'=>['total'=>101]],['mchid'=>'wrong'],['appid'=>'wrong'],['payer'=>['openid'=>'other']]]as$i=>$bad)paymentReject(static function()use($processor,$resource,$i,$bad):void{$processor->process(paymentRaw('invalid-'.$i,array_replace($resource,$bad)),[]);},$i===3?'付款人':'不一致');
 $processor->process(paymentRaw('failed-business',$resource),[]);
 paymentAssert($db->fetchValue('SELECT status FROM payment_orders WHERE public_no=:no',['no'=>$no])==='paid' && $db->fetchValue('SELECT status FROM run_empty_fee_claims WHERE id=1')==='paid' && (int)$db->fetchValue('SELECT SUM(amount_cents) FROM worker_balance_ledger')===100,'retry settles payment and worker ledger exactly once');
 $dup=$processor->process(paymentRaw('failed-business',$resource),[]);$processor->process(paymentRaw('different-id',$resource),[]);paymentAssert($dup['duplicate'] && (int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger')===1,'both same-notice and paid-payment replay idempotent');
 paymentReject(static function()use($processor,$resource):void{$processor->process(paymentRaw('failed-business',$resource+['extra'=>'changed']),[]);},'摘要不一致');paymentAssert($db->fetchValue("SELECT process_status FROM payment_notifications WHERE notification_id='failed-business'")==='processed','digest mismatch cannot corrupt processed receipt');
 $paidResult=$creation->create($actor,['run_empty_claim_id'=>'CLAIM-1'],'127.0.0.1');paymentAssert($paidResult['data']['status']==='paid' && $gateway->created===2,'paid creation returns status without another gateway request');
 // Two real database connections: payment row lock allows one settlement, then replay.
 $second=$creation->create($actor,['run_empty_claim_id'=>'CLAIM-2'],'127.0.0.1');$secondResource=array_replace($resource,['out_trade_no'=>$second['data']['payment_no'],'amount'=>['total'=>150]]);
 $otherGateway=new PersistedPaymentGateway($other);$otherNotice=new \WorkerAdmin\Infrastructure\Persistence\DatabasePaymentNotificationRepository($other);$otherSettlement=new \WorkerAdmin\Infrastructure\Persistence\DatabasePaidPaymentSettlement($other,$settings);
 $otherProcessor=new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($otherNotice,$otherSettlement,new PersistedPaymentGatewayFactory($otherGateway),new \WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager($other));$other->execute('SET SESSION innodb_lock_wait_timeout=1');
 $db->pdo()->beginTransaction();try{$settlement->settle($secondResource);paymentReject(static function()use($otherProcessor,$secondResource):void{$otherProcessor->process(paymentRaw('competing',$secondResource),[]);},'Lock wait timeout');$db->pdo()->commit();}catch(Throwable $e){if($db->pdo()->inTransaction())$db->pdo()->rollBack();throw $e;}
 $otherProcessor->process(paymentRaw('competing',$secondResource),[]);paymentAssert((int)$db->fetchValue('SELECT COUNT(*) FROM worker_balance_ledger')===2 && (int)$db->fetchValue('SELECT SUM(amount_cents) FROM worker_balance_ledger')===250,'competing retry cannot credit twice');
 // Actual refund result repositories, including rollback of order/event side effects.
 $db->execute("INSERT INTO payment_orders(id,public_no,business_type,business_id,order_id,amount_cents,status,app_id,mch_id) VALUES(200,'REF-PAY','measurement_change',33,NULL,200,'paid','wx-test','m-test'),(201,'ORDER-REF-PAY','order_booking',1,1,100,'paid','wx-test','m-test'),(204,'FAIL-REF-PAY','measurement_balance',4,4,100,'paid','wx-test','m-test')");
 $db->execute("INSERT INTO change_requests VALUES(33,'paid',UTC_TIMESTAMP())");$db->execute("INSERT INTO refunds(id,public_no,payment_order_id,order_id,amount_cents,status) VALUES(200,'REF-1',200,NULL,200,'processing'),(201,'REF-2',201,1,100,'processing'),(204,'REF-4',204,4,100,'processing')");
 $refundProcessor=new \WorkerAdmin\Application\Money\RefundNotificationProcessor($notice,new \WorkerAdmin\Infrastructure\Persistence\DatabaseRefundResultSettlement($db,$settlement),$gateway,$tx);
 $refundResource=['refund_status'=>'SUCCESS','out_refund_no'=>'REF-1','out_trade_no'=>'REF-PAY','mchid'=>'m-test','amount'=>['refund'=>200,'total'=>200],'refund_id'=>'WX-R'];
 paymentReject(static function()use($refundProcessor,$refundResource):void{$bad=$refundResource;$bad['amount']['refund']=199;$refundProcessor->process(paymentRaw('wrong-refund',$bad),[]);},'不一致');
 paymentAssert($db->fetchValue('SELECT status FROM refunds WHERE id=200')==='processing' && $db->fetchValue('SELECT status FROM payment_orders WHERE id=200')==='paid','invalid refund leaves funds unchanged');
 $refundProcessor->process(paymentRaw('refund-ok',$refundResource),[]);paymentAssert($db->fetchValue('SELECT status FROM refunds WHERE id=200')==='succeeded' && $db->fetchValue('SELECT status FROM payment_orders WHERE id=200')==='refunded' && $db->fetchValue('SELECT payment_status FROM change_requests WHERE id=33')==='refunded','refund success closes refund/payment/change consistently');
 $duplicate=$refundProcessor->process(paymentRaw('refund-ok',$refundResource),[]);$refundProcessor->process(paymentRaw('refund-replay',$refundResource),[]);paymentAssert($duplicate['duplicate'] && $db->fetchValue('SELECT status FROM refunds WHERE id=200')==='succeeded','refund notification and business-result replay idempotent');
 paymentAssert($db->fetchValue("SELECT payment_order_id FROM payment_notifications WHERE notification_id='refund-ok'")===null,'refund notification retains null payment association');
 $orderRefund=array_replace($refundResource,['out_refund_no'=>'REF-2','out_trade_no'=>'ORDER-REF-PAY','amount'=>['refund'=>100,'total'=>100]]);$events=(int)$db->fetchValue('SELECT COUNT(*) FROM order_events');
 $GLOBALS['paymentNotificationFault']=true;paymentReject(static function()use($refundProcessor,$orderRefund):void{$refundProcessor->process(paymentRaw('refund-fault',$orderRefund),[]);},'notification fault');$GLOBALS['paymentNotificationFault']=false;
 paymentAssert($db->fetchValue('SELECT status FROM refunds WHERE id=201')==='processing' && $db->fetchValue('SELECT status FROM payment_orders WHERE id=201')==='paid' && $db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1')==='active' && (int)$db->fetchValue('SELECT COUNT(*) FROM order_events')===$events,'refund, payment, order and event all roll back');
 $refundProcessor->process(paymentRaw('refund-fault',$orderRefund),[]);paymentAssert($db->fetchValue('SELECT lifecycle_status FROM orders WHERE id=1')==='cancelled' && $db->fetchValue('SELECT status FROM refunds WHERE id=201')==='succeeded','failed refund notification retry completes closure');
 $failedRefund=array_replace($refundResource,['refund_status'=>'ABNORMAL','out_refund_no'=>'REF-4','out_trade_no'=>'FAIL-REF-PAY','amount'=>['refund'=>100,'total'=>100]]);$refundProcessor->process(paymentRaw('refund-abnormal',$failedRefund),[]);
 paymentAssert($db->fetchValue('SELECT status FROM refunds WHERE id=204')==='failed' && $db->fetchValue('SELECT refund_status FROM orders WHERE id=4')==='none','measurement refund failure does not corrupt ordinary refund state');
 paymentAssert(in_array(true,$gateway->flags,true) && in_array(false,$gateway->flags,true),'payment and refund decode flags preserved');
 echo 'Payment retirement MySQL: '.$checks.' checks passed.'.PHP_EOL;
}finally{if($created)$server->exec('DROP DATABASE worker_payment_retirement_test');}
}

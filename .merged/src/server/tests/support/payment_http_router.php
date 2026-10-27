<?php
declare(strict_types=1);
// Local test router. All payment, identity and persistence ports are recording fakes.
$root=dirname(__DIR__,3);
spl_autoload_register(static function(string $class)use($root):void{
 if(strpos($class,'WorkerAdmin\\')!==0)return;
 $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($path))require_once $path;
});
$GLOBALS['case']=(string)($_GET['case']??'ok');$GLOBALS['transaction']=false;
function setting($key,$default=null){return $default;}
function app_config($key,$default=null){return $default;}
function request_id(){return 'payment-retirement-test';}
function request_ip(){return '127.0.0.1';}
function write_log(...$values){}
function json_response(array $payload,int $status=200):void{http_response_code($status);header('Content-Type: application/json');echo json_encode($payload,JSON_UNESCAPED_UNICODE);exit;}
final class HttpPaymentSettings implements \WorkerAdmin\Contracts\SettingsProvider{
 public function get(string $key,$default=null){if($key==='wechat.miniapp_appid')return $GLOBALS['case']==='missing-appid'?'':'wx123';if($key==='wechat.admin_openids')return ['admin-openid'];return $default;}
 public function bool(string $key,bool $default=false):bool{return $key==='demo.data_enabled';}
 public function int(string $key,int $default=0):int{return $default;}
}
final class HttpPaymentActorRepository implements \WorkerAdmin\Contracts\ApiActorRepository{
 public function findByTokenHash(string $hash,string $appid):?array{return ['session_id'=>1,'user_id'=>7,'payer_openid'=>$GLOBALS['case']==='missing-openid'?'':'admin-openid','demo_actor_user_id'=>$GLOBALS['case']==='demo'?9:null];}
 public function touchSession(int $id):void{}
 public function findDemoActor(int $id):?array{return ['user_id'=>9,'demo_role'=>'owner'];}
 public function findTestActor(int $userId,int $instanceId):?array{return null;}
 public function clearDemoActor(int $id):void{}
 public function clearTestActor(int $id):void{}
}
final class HttpPaymentTransaction implements \WorkerAdmin\Contracts\TransactionManager{
 public function run(callable $call){$GLOBALS['transaction']=true;try{return $call();}finally{$GLOBALS['transaction']=false;}}
}
final class HttpPaymentCreationRepository implements \WorkerAdmin\Contracts\PaymentCreationRepository{
 public function prepare(array $actor,array $input,string $version,string $ip):array{
  if(!$GLOBALS['transaction'])throw new RuntimeException('prepare outside transaction');
  if($GLOBALS['case']==='restricted')throw new \WorkerAdmin\Domain\Order\OrderOperationException('预约费准入限制','ORDER_SUBMISSION_RESTRICTED',422,['expires_at_utc'=>'2099-01-01 00:00:00']);
  return ['id'=>11,'amount_cents'=>100,'business_type'=>'order_booking','public_no'=>'PAY-1','status'=>'created','description'=>'server amount','payer_openid'=>'admin-openid'];
 }
 public function markPending(int $id,string $prepay):void{if($GLOBALS['transaction'])throw new RuntimeException('prepay update inside creation transaction');}
}
final class HttpPaymentGateway implements \WorkerAdmin\Contracts\PaymentGateway{
 public function apiVersion():string{return 'v3';}
 public function enabled():bool{return true;}
 public function createJsapiPayment(string $no,string $description,int $amount,string $openid):array{
  if($GLOBALS['transaction']||$amount!==100)throw new RuntimeException('external call boundary or server amount wrong');
  return ['prepay_id'=>'test-prepay','payment'=>['server_amount'=>$amount]];
 }
 public function clientPaymentParameters(string $prepay):array{return [];}
 public function queryPayment(string $no):array{return [];}
 public function createRefund(string $p,string $r,int $a,int $total,string $reason):array{throw new RuntimeException('unexpected external refund');}
 public function queryRefund(string $no):array{return [];}
 public function decodeNotification(string $raw,array $headers,bool $refund=false):array{
  if($GLOBALS['case']==='bad-sign')throw new RuntimeException('fake signature invalid');
  if(($headers['X-Test-Signature']??$headers['x-test-signature']??'')!=='valid')throw new RuntimeException('headers lost');
  if($refund!==((string)($_GET['route']??'')==='wechatpay.refund_notify'))throw new RuntimeException('wrong refund decode flag');
  return ['notice'=>['id'=>'notice-1'],'resource'=>['trade_state'=>'SUCCESS','out_trade_no'=>'PAY-1'],'headers'=>$headers];
 }
}
final class HttpPaymentGatewayFactory implements \WorkerAdmin\Contracts\PaymentGatewayFactory{
 public function defaultGateway():\WorkerAdmin\Contracts\PaymentGateway{return new HttpPaymentGateway();}
 public function forVersion(string $version):\WorkerAdmin\Contracts\PaymentGateway{return new HttpPaymentGateway();}
}
final class HttpPaymentNoticeRepository implements \WorkerAdmin\Contracts\PaymentNotificationRepository{
 public function register(string $id,string $event,string $type,array $headers,string $cipher,string $digest):bool{return $GLOBALS['case']!=='duplicate';}
 public function markProcessing(string $id):void{}
 public function markProcessed(string $id,?int $paymentId):void{}
 public function markFailed(string $id,string $error):void{}
}
final class HttpPaidSettlement implements \WorkerAdmin\Contracts\PaidPaymentSettlement{
 public function settle(array $resource):?int{if(!$GLOBALS['transaction'])throw new RuntimeException('settlement outside transaction');if($GLOBALS['case']==='settle-fail')throw new RuntimeException('injected settlement failure');return 11;}
 public function pendingAutomaticRefund(string $no):?array{if($GLOBALS['transaction'])throw new RuntimeException('postcommit lookup inside transaction');return null;}
 public function markAutomaticRefundProcessing(int $id,?string $providerId):void{}
 public function applyImmediateRefundSuccess(array $resource,string $no):void{}
}
final class HttpRefundSettlement implements \WorkerAdmin\Contracts\RefundResultSettlement{
 public function apply(array $resource):void{if(!$GLOBALS['transaction'])throw new RuntimeException('refund outside transaction');if($GLOBALS['case']==='settle-fail')throw new RuntimeException('injected refund failure');}
}
final class HttpPaymentContentGate implements \WorkerAdmin\Contracts\RequestContentSecurityGate{
 public function inspect(string $route):void{
  if($route!=='payments.create')throw new RuntimeException('callback entered UGC gate');
  if($GLOBALS['case']==='security-denied')throw new RuntimeException('security denied');
 }
}
if(defined('PAYMENT_HTTP_FIXTURE_LIBRARY'))return;
$db=new \WorkerAdmin\Core\Database(['database'=>'offline']);$tx=new HttpPaymentTransaction();$gateway=new HttpPaymentGateway();$notice=new HttpPaymentNoticeRepository();
$actors=new \WorkerAdmin\Application\Identity\ApiActorResolver(new HttpPaymentActorRepository(),new HttpPaymentSettings());
$creation=new \WorkerAdmin\Application\Money\PaymentCreation(new HttpPaymentCreationRepository(),$gateway,$tx);
$payments=new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($notice,new HttpPaidSettlement(),new HttpPaymentGatewayFactory(),$tx);
$refunds=new \WorkerAdmin\Application\Money\RefundNotificationProcessor($notice,new HttpRefundSettlement(),$gateway,$tx);
$modules=[new \WorkerAdmin\Http\Api\Modules\PaymentCreationApiModule($db,$creation,$actors),new \WorkerAdmin\Http\Api\Modules\PaymentNotificationApiModule($db,$payments),new \WorkerAdmin\Http\Api\Modules\RefundNotificationApiModule($db,$refunds)];
try{(new \WorkerAdmin\Http\Api\ApiKernel($db,$modules,new HttpPaymentContentGate()))->dispatch((string)($_GET['route']??''));}
catch(Throwable $e){json_response(['code'=>$GLOBALS['case']==='security-denied'?'SECURITY_TEST':'TEST_FAILURE','message'=>$e->getMessage()],500);}

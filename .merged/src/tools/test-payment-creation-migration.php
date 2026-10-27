<?php

declare(strict_types=1);

(static function (): void {
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix='WorkerAdmin\\';
    if (strpos($class,$prefix)!==0) return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('Payment creation migration: '.$message);
};

final class PaymentCreationTrace
{
    public static array $events=[];
}

final class PaymentCreationRepositoryFake implements \WorkerAdmin\Contracts\PaymentCreationRepository
{
    public array $payment=[];
    public array $marked=[];
    public function prepare(array $actor,array $input,string $apiVersion,string $clientIp):array
    {
        PaymentCreationTrace::$events[]='prepare';
        return $this->payment;
    }
    public function markPending(int $paymentId,string $prepayId):void
    {
        PaymentCreationTrace::$events[]='mark';
        $this->marked=[$paymentId,$prepayId];
    }
}

final class PaymentGatewayFake implements \WorkerAdmin\Contracts\PaymentGateway
{
    public int $createCalls=0;
    public int $clientCalls=0;
    public function apiVersion():string{return 'v3';}
    public function enabled():bool{return true;}
    public function createJsapiPayment(string $outTradeNo,string $description,int $amountCents,string $openid):array
    {
        PaymentCreationTrace::$events[]='create';$this->createCalls++;
        return ['prepay_id'=>'wx-prepay-1','payment'=>['timeStamp'=>'1','nonceStr'=>'n']];
    }
    public function queryPayment(string $outTradeNo):array{return [];}
    public function clientPaymentParameters(string $prepayId):array
    {
        PaymentCreationTrace::$events[]='client';$this->clientCalls++;
        return ['prepay_id'=>$prepayId];
    }
    public function createRefund(string $outTradeNo,string $outRefundNo,int $refundCents,int $totalCents,string $reason):array{return [];}
    public function queryRefund(string $outRefundNo):array{return [];}
    public function decodeNotification(string $rawBody,array $headers,bool $refund=false):array{return [];}
}

final class PaymentTransactionFake implements \WorkerAdmin\Contracts\TransactionManager
{
    public function run(callable $callback)
    {
        PaymentCreationTrace::$events[]='begin';
        $value=$callback();
        PaymentCreationTrace::$events[]='commit';
        return $value;
    }
}

$actor=['user_id'=>7,'payer_openid'=>'openid-7'];
$repo=new PaymentCreationRepositoryFake();
$gateway=new PaymentGatewayFake();
$tx=new PaymentTransactionFake();
$repo->payment=[
    'id'=>11,'public_no'=>'P1','business_type'=>'order_booking','amount_cents'=>100,
    'status'=>'created','description'=>'预约费','payer_openid'=>'openid-7','wechat_prepay_id'=>null,
];
PaymentCreationTrace::$events=[];
$useCase=new \WorkerAdmin\Application\Money\PaymentCreation($repo,$gateway,$tx);
$result=$useCase->create($actor,['order_id'=>'O1'],'127.0.0.1');
$assert($result['data']['status']==='pending','新支付单返回 pending');
$assert($repo->marked===[11,'wx-prepay-1'],'微信预支付号回写支付单');
$assert(PaymentCreationTrace::$events===['begin','prepare','commit','create','mark'],'数据库事务提交后才调用微信并回写 prepay');
$assert($gateway->createCalls===1,'新支付单只创建一次微信预支付');

$repo2=new PaymentCreationRepositoryFake();$gateway2=new PaymentGatewayFake();$tx2=new PaymentTransactionFake();
$repo2->payment=[
    'id'=>12,'public_no'=>'P2','business_type'=>'measurement_balance','amount_cents'=>200,
    'status'=>'pending','description'=>'测量补款','payer_openid'=>'openid-7','wechat_prepay_id'=>'wx-existing',
];
PaymentCreationTrace::$events=[];
$result=(new \WorkerAdmin\Application\Money\PaymentCreation($repo2,$gateway2,$tx2))->create($actor,['selection_id'=>3],'127.0.0.1');
$assert($gateway2->createCalls===0 && $gateway2->clientCalls===1,'已有 prepay 只重建客户端参数，不重复下单');
$assert($repo2->marked===[],'已有 prepay 不重复回写');
$assert(PaymentCreationTrace::$events===['begin','prepare','commit','client'],'已有 prepay 也必须先结束数据库事务再组装客户端参数');

$repo3=new PaymentCreationRepositoryFake();$gateway3=new PaymentGatewayFake();$tx3=new PaymentTransactionFake();
$repo3->payment=['id'=>0,'public_no'=>'','business_type'=>'measurement_balance','amount_cents'=>0,'status'=>'paid','description'=>'','payer_openid'=>'openid-7','wechat_prepay_id'=>null];
PaymentCreationTrace::$events=[];
$result=(new \WorkerAdmin\Application\Money\PaymentCreation($repo3,$gateway3,$tx3))->create($actor,['selection_id'=>4],'127.0.0.1');
$assert($result['message']==='无需补款，师傅已自动接单','零余额测量保留自动成交语义');
$assert($gateway3->createCalls===0 && $gateway3->clientCalls===0,'零余额不调用微信');
$assert(PaymentCreationTrace::$events===['begin','prepare','commit'],'零余额只执行数据库成交事务');

$missing=false;
try{(new \WorkerAdmin\Application\Money\PaymentCreation($repo3,$gateway3,$tx3))->create(['user_id'=>7],[],'127.0.0.1');}
catch(RuntimeException $e){$missing=$e->getCode()===409;}
$assert($missing,'缺失 OpenID 在 Application 边界拒绝');

$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$controller=(is_file($root.'/server/app/Controllers/ApiController.php') ? (string)file_get_contents($root.'/server/app/Controllers/ApiController.php') : '');
$module=(string)file_get_contents($root.'/server/app/Http/Api/Modules/PaymentCreationApiModule.php');
$repository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabasePaymentCreationRepository.php');
$class = new ReflectionClass(\WorkerAdmin\Http\Api\Modules\PaymentCreationApiModule::class);
$owner = $class->newInstanceWithoutConstructor();
$assert($owner->supports('payments.create') && !$owner->supports('payments.status'),'支付创建模块只接管创建入口');
$assert(strpos($legacy,'$this->paymentCreation')===false,'不再依赖 Legacy 内部过渡分发');
$assert(strpos($controller,"'payments.create' =>")===false && strpos($controller,'private function createPayment(')===false,'旧创建 route/handler 已退休');
$assert(strpos($module,'payments.status')===false && strpos($module,'wechatpay.notify')===false,'未捎带迁移状态查询或回调');
$assert(strpos($module,"'DEMO_EXTERNAL_ACTION_BLOCKED'")!==false,'保留旧演示真实副作用错误码');
$assert(strpos($module,"'MINIAPP_APPID_MISSING'")!==false,'保留旧小程序 AppID 缺失错误码');
$assert(strpos($repository,"payment_status='unpaid'")!==false,'施工付款预占过期继续恢复未支付状态');
$assert(strpos($repository,'OrderNotificationService::queue($this->db')!==false,'迁移期保留原订单事件通知入队语义');

echo "Payment creation retirement tests passed.\n";
})();

require __DIR__ . '/test-payment-notification-migration.php';

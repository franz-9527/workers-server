<?php

declare(strict_types=1);

(static function (): void {
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Payment status migration: '.$message);};

final class PaymentStatusRepoFake implements \WorkerAdmin\Contracts\PaymentStatusRepository
{
    public ?array $owned=null;public ?array $fresh=null;public array $closed=[];
    public function findOwned(string $paymentPublicNo,int $payerUserId):?array{return $this->owned;}
    public function findById(int $paymentId):?array{return $this->fresh?:$this->owned;}
    public function closeExternallyFailed(int $paymentId):void{$this->closed[]=$paymentId;}
}
final class PaymentStatusSettlementFake implements \WorkerAdmin\Contracts\PaidPaymentSettlement
{
    public array $settled=[];public ?array $refund=null;public array $processing=[];public array $success=[];
    public function settle(array $resource):?int{$this->settled[]=$resource;return 11;}
    public function pendingAutomaticRefund(string $paymentPublicNo):?array{return $this->refund;}
    public function markAutomaticRefundProcessing(int $refundId,?string $wechatRefundId):void{$this->processing[]=[$refundId,$wechatRefundId];}
    public function applyImmediateRefundSuccess(array $resource,string $outRefundNo):void{$this->success[]=[$resource,$outRefundNo];}
}
final class PaymentStatusGatewayFake implements \WorkerAdmin\Contracts\PaymentGateway
{
    public string $version='v3';public bool $isEnabled=true;public bool $throwQuery=false;public array $query=[];public array $refund=[];public array $calls=[];
    public function apiVersion():string{return $this->version;}
    public function enabled():bool{return $this->isEnabled;}
    public function createJsapiPayment(string $outTradeNo,string $description,int $amountCents,string $openid):array{return [];}
    public function queryPayment(string $outTradeNo):array{$this->calls[]='query:'.$outTradeNo;if($this->throwQuery)throw new RuntimeException('query network');return $this->query;}
    public function clientPaymentParameters(string $prepayId):array{return [];}
    public function createRefund(string $outTradeNo,string $outRefundNo,int $refundCents,int $totalCents,string $reason):array{$this->calls[]='refund:'.$outRefundNo;return $this->refund;}
    public function queryRefund(string $outRefundNo):array{return [];}
    public function decodeNotification(string $rawBody,array $headers,bool $refund=false):array{return [];}
}
final class PaymentStatusGatewayFactoryFake implements \WorkerAdmin\Contracts\PaymentGatewayFactory
{
    /** @var array<string,PaymentStatusGatewayFake> */ public array $gateways=[];public array $versions=[];
    public function defaultGateway():\WorkerAdmin\Contracts\PaymentGateway{return $this->forVersion('v3');}
    public function forVersion(string $apiVersion):\WorkerAdmin\Contracts\PaymentGateway{$this->versions[]=$apiVersion;if(!isset($this->gateways[$apiVersion])){$g=new PaymentStatusGatewayFake();$g->version=$apiVersion;$this->gateways[$apiVersion]=$g;}return $this->gateways[$apiVersion];}
}
final class PaymentStatusTxFake implements \WorkerAdmin\Contracts\TransactionManager
{
    public array $events=[];
    public function run(callable $callback){$this->events[]='begin';$value=$callback();$this->events[]='commit';return $value;}
}

$actor=['user_id'=>7];
$repo=new PaymentStatusRepoFake();$settlement=new PaymentStatusSettlementFake();$factory=new PaymentStatusGatewayFactoryFake();$tx=new PaymentStatusTxFake();
$repo->owned=['id'=>11,'public_no'=>'P1','payer_user_id'=>7,'api_version'=>'v2','status'=>'pending','paid_at'=>null];
$repo->fresh=['id'=>11,'public_no'=>'P1','payer_user_id'=>7,'api_version'=>'v2','status'=>'paid','paid_at'=>'2026-10-09 09:00:00'];
$factory->gateways['v2']=new PaymentStatusGatewayFake();$factory->gateways['v2']->version='v2';$factory->gateways['v2']->query=['trade_state'=>'SUCCESS','out_trade_no'=>'P1'];
$result=(new \WorkerAdmin\Application\Money\PaymentStatusLookup($repo,$settlement,$factory,$tx))->lookup($actor,'P1');
$assert($factory->versions===['v2'],'主动查单必须使用支付单保存的 API 版本');
$assert(count($settlement->settled)===1,'SUCCESS 主动查单复用 PaidPaymentSettlement 补确认到账');
$assert($tx->events===['begin','commit'],'到账补确认在数据库事务中完成');
$assert($result['status']==='paid','补确认后重新读取并返回本地最终状态');

$repo2=new PaymentStatusRepoFake();$settlement2=new PaymentStatusSettlementFake();$factory2=new PaymentStatusGatewayFactoryFake();$tx2=new PaymentStatusTxFake();
$repo2->owned=['id'=>12,'public_no'=>'P2','payer_user_id'=>7,'api_version'=>'v3','status'=>'pending','paid_at'=>null];
$repo2->fresh=['id'=>12,'public_no'=>'P2','payer_user_id'=>7,'api_version'=>'v3','status'=>'closed','paid_at'=>null];
$factory2->gateways['v3']=new PaymentStatusGatewayFake();$factory2->gateways['v3']->query=['trade_state'=>'CLOSED'];
$result=(new \WorkerAdmin\Application\Money\PaymentStatusLookup($repo2,$settlement2,$factory2,$tx2))->lookup($actor,'P2');
$assert($repo2->closed===[12],'微信明确关闭时执行本地关闭/释放壳层');
$assert($tx2->events===['begin','commit'],'明确失败关闭在事务中完成');
$assert($result['status']==='closed','关闭后返回刷新后的本地状态');

$repo3=new PaymentStatusRepoFake();$settlement3=new PaymentStatusSettlementFake();$factory3=new PaymentStatusGatewayFactoryFake();$tx3=new PaymentStatusTxFake();
$repo3->owned=['id'=>13,'public_no'=>'P3','payer_user_id'=>7,'api_version'=>'v3','status'=>'pending','paid_at'=>null];
$factory3->gateways['v3']=new PaymentStatusGatewayFake();$factory3->gateways['v3']->throwQuery=true;
$result=(new \WorkerAdmin\Application\Money\PaymentStatusLookup($repo3,$settlement3,$factory3,$tx3))->lookup($actor,'P3');
$assert($result['status']==='pending'&&$result['query_error']==='query network','微信查单异常继续返回本地状态供客户端轮询');
$assert($tx3->events===[],'查询网络错误不启动资金事务');

$repo4=new PaymentStatusRepoFake();$settlement4=new PaymentStatusSettlementFake();$factory4=new PaymentStatusGatewayFactoryFake();$tx4=new PaymentStatusTxFake();
$repo4->owned=['id'=>14,'public_no'=>'P4','payer_user_id'=>7,'api_version'=>'v2','status'=>'pending','paid_at'=>null];
$repo4->fresh=['id'=>14,'public_no'=>'P4','payer_user_id'=>7,'api_version'=>'v2','status'=>'paid','paid_at'=>'2026-10-09 09:00:00'];
$settlement4->refund=['id'=>31,'payment_no'=>'P4','public_no'=>'RF4','amount_cents'=>100,'total_cents'=>100,'reason'=>'late','api_version'=>'v3'];
$factory4->gateways['v2']=new PaymentStatusGatewayFake();$factory4->gateways['v2']->version='v2';$factory4->gateways['v2']->query=['trade_state'=>'SUCCESS','out_trade_no'=>'P4'];
$factory4->gateways['v3']=new PaymentStatusGatewayFake();$factory4->gateways['v3']->version='v3';$factory4->gateways['v3']->refund=['status'=>'SUCCESS','refund_id'=>'wx-r4','out_trade_no'=>'P4','mchid'=>'m1','amount'=>['refund'=>100,'total'=>100]];
$result=(new \WorkerAdmin\Application\Money\PaymentStatusLookup($repo4,$settlement4,$factory4,$tx4))->lookup($actor,'P4');
$assert($factory4->versions===['v2','v3'],'主动查支付与自动退款分别使用各自保存的 API 版本');
$assert($settlement4->processing===[[31,'wx-r4']]&&count($settlement4->success)===1,'迟到支付同步退款成功继续完成退款收口');
$assert($tx4->events===['begin','commit','begin','commit'],'到账和同步退款成功分别使用独立事务');

$missingRepo=new PaymentStatusRepoFake();$missing=false;
try{(new \WorkerAdmin\Application\Money\PaymentStatusLookup($missingRepo,new PaymentStatusSettlementFake(),new PaymentStatusGatewayFactoryFake(),new PaymentStatusTxFake()))->lookup($actor,'P404');}
catch(RuntimeException $e){$missing=$e->getCode()===404&&$e->getMessage()==='支付单不存在';}
$assert($missing,'只能查询当前用户自己的支付单，不存在统一返回 PAYMENT_NOT_FOUND');

$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$controller=(string)file_get_contents($root.'/server/app/Controllers/ApiController.php');
$kernel=(string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$money=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MoneyApiModule.php');
$assert(strpos($money,"'payments.status'=>'GET'")!==false,'MoneyApiModule 正式接管 payments.status');
$assert(strpos($kernel,'new MoneyApiModule($db)')!==false,'MoneyApiModule 已注册 ApiKernel');
$assert(strpos($legacy,"'payments.status'")!==false&&strpos($legacy,'$this->paymentStatus')===false,'Legacy 仅保留 anti-fallback，不再内部转发');
$assert(strpos($controller,"'payments.status' => ['GET', 'paymentStatus']")===false&&strpos($controller,'private function paymentStatus(')===false,'物理退休前保留旧 route/handler');
$assert(strpos($money,"'DEMO_EXTERNAL_ACTION_BLOCKED'")!==false,'保留演示真实资金查询限制');
$assert(strpos($money,"'wallet.summary'=>'GET'")!==false&&strpos($money,"'finance.withdraw'=>'POST'")!==false,'HTTP 层已按 Money 板块整合，而非单路由模块');

echo "Payment status Money-module migration tests passed.\n";
})();

<?php

declare(strict_types=1);

(static function (): void {
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Payment notification migration: '.$message);};

final class NoticeRepoFake implements \WorkerAdmin\Contracts\PaymentNotificationRepository
{
    public bool $should=true;public array $sequence=[];public array $failed=[];public array $processed=[];
    public function register(string $notificationId,string $eventType,string $resourceType,array $headers,string $payloadCiphertext,string $payloadDigest):bool{$this->sequence[]='register';return $this->should;}
    public function markProcessing(string $notificationId):void{$this->sequence[]='processing';}
    public function markProcessed(string $notificationId,?int $paymentOrderId):void{$this->sequence[]='processed';$this->processed=[$notificationId,$paymentOrderId];}
    public function markFailed(string $notificationId,string $error):void{$this->sequence[]='failed';$this->failed=[$notificationId,$error];}
}
final class SettlementFake implements \WorkerAdmin\Contracts\PaidPaymentSettlement
{
    public array $sequence=[];public ?array $refund=null;public bool $throwSettle=false;public array $immediate=[];
    public function settle(array $resource):?int{$this->sequence[]='settle';if($this->throwSettle)throw new RuntimeException('settle failed');return 99;}
    public function pendingAutomaticRefund(string $paymentPublicNo):?array{$this->sequence[]='refund_lookup';return $this->refund;}
    public function markAutomaticRefundProcessing(int $refundId,?string $wechatRefundId):void{$this->sequence[]='refund_processing';}
    public function applyImmediateRefundSuccess(array $resource,string $outRefundNo):void{$this->sequence[]='refund_success';$this->immediate=[$resource,$outRefundNo];}
}
final class RefundSettlementFake implements \WorkerAdmin\Contracts\RefundResultSettlement
{
    public array $sequence=[];public array $resource=[];public bool $throwApply=false;
    public function apply(array $resource):void{$this->sequence[]='apply';$this->resource=$resource;if($this->throwApply)throw new RuntimeException('refund settle failed');}
}
final class GatewayFake implements \WorkerAdmin\Contracts\PaymentGateway
{
    public array $sequence=[];public bool $throwRefund=false;public string $version='v3';public array $decodeRefundFlags=[];
    public function apiVersion():string{return $this->version;}
    public function enabled():bool{return true;}
    public function createJsapiPayment(string $outTradeNo,string $description,int $amountCents,string $openid):array{return [];}
    public function queryPayment(string $outTradeNo):array{return [];}
    public function clientPaymentParameters(string $prepayId):array{return [];}
    public function createRefund(string $outTradeNo,string $outRefundNo,int $refundCents,int $totalCents,string $reason):array{$this->sequence[]='refund_api';if($this->throwRefund)throw new RuntimeException('refund network');return ['status'=>'SUCCESS','refund_id'=>'wx-r1','mchid'=>'m1','out_trade_no'=>$outTradeNo,'amount'=>['refund'=>$refundCents,'total'=>$totalCents]];}
    public function queryRefund(string $outRefundNo):array{return [];}
    public function decodeNotification(string $rawBody,array $headers,bool $refund=false):array
    {
        $this->sequence[]='decode';$this->decodeRefundFlags[]=$refund;
        if($refund){
            return [
                'notice'=>['id'=>'refund-notice-1','event_type'=>'REFUND.SUCCESS','resource_type'=>'encrypt-resource','resource'=>['ciphertext'=>'refund-cipher']],
                'resource'=>['refund_status'=>'SUCCESS','out_refund_no'=>'RF1','out_trade_no'=>'P1','mchid'=>'m1','amount'=>['refund'=>100,'total'=>100]],
                'headers'=>$headers,
            ];
        }
        return ['notice'=>['id'=>'notice-1','event_type'=>'TRANSACTION.SUCCESS','resource_type'=>'encrypt-resource','resource'=>['ciphertext'=>'cipher']],'resource'=>['trade_state'=>'SUCCESS','out_trade_no'=>'P1'],'headers'=>$headers];
    }
}
final class GatewayFactoryFake implements \WorkerAdmin\Contracts\PaymentGatewayFactory
{
    public GatewayFake $default;public GatewayFake $versioned;public array $versions=[];
    public function __construct(){ $this->default=new GatewayFake();$this->versioned=new GatewayFake(); }
    public function defaultGateway():\WorkerAdmin\Contracts\PaymentGateway{return $this->default;}
    public function forVersion(string $apiVersion):\WorkerAdmin\Contracts\PaymentGateway{$this->versions[]=$apiVersion;$this->versioned->version=$apiVersion;return $this->versioned;}
}
final class TxFake implements \WorkerAdmin\Contracts\TransactionManager
{
    public array $sequence=[];
    public function run(callable $callback){$this->sequence[]='begin';$value=$callback();$this->sequence[]='commit';return $value;}
}

// 支付成功通知：到账事务提交后再触发自动退款。
$notice=new NoticeRepoFake();$settle=new SettlementFake();$factory=new GatewayFactoryFake();$tx=new TxFake();
$settle->refund=['id'=>7,'payment_no'=>'P1','public_no'=>'RF1','amount_cents'=>100,'total_cents'=>100,'reason'=>'late','api_version'=>'v2'];
$processor=new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($notice,$settle,$factory,$tx);
$result=$processor->process('{"x":1}',['wechatpay-signature'=>'sig']);
$assert($result['duplicate']===false,'首次支付通知应执行');
$assert($notice->sequence===['register','processing','processed'],'支付通知状态按 received/processing/processed 推进');
$assert($settle->sequence===['settle','refund_lookup','refund_processing','refund_success'],'到账后检查并完成同步自动退款');
$assert($tx->sequence===['begin','commit','begin','commit'],'到账与同步退款各自在独立事务中完成');
$assert($factory->default->decodeRefundFlags===[false],'支付回调必须按支付通知验签解密');
$assert($factory->versions===['v2'],'自动退款使用原支付单保存的 API 版本');
$assert($factory->versioned->sequence===['refund_api'],'资金事务提交后才调用外部退款网关');
$assert($notice->processed===['notice-1',99],'支付通知关联到账支付单');

$dupNotice=new NoticeRepoFake();$dupNotice->should=false;$dupSettle=new SettlementFake();$dupFactory=new GatewayFactoryFake();$dupTx=new TxFake();
$result=(new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($dupNotice,$dupSettle,$dupFactory,$dupTx))->process('{}',[]);
$assert($result['duplicate']===true,'processed 重复支付通知直接幂等成功');
$assert($dupSettle->sequence===[]&&$dupTx->sequence===[],'重复支付通知不再次入账');

$autoRefundNotice=new NoticeRepoFake();$autoRefundSettle=new SettlementFake();$autoRefundFactory=new GatewayFactoryFake();$autoRefundTx=new TxFake();
$autoRefundSettle->refund=['id'=>8,'payment_no'=>'P2','public_no'=>'RF2','amount_cents'=>50,'total_cents'=>50,'reason'=>'late','api_version'=>'v3'];
$autoRefundFactory->versioned->throwRefund=true;
$result=(new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($autoRefundNotice,$autoRefundSettle,$autoRefundFactory,$autoRefundTx))->process('{}',[]);
$assert($result['auto_refund_error']==='refund network','自动退款异常返回给协议层记录');
$assert($autoRefundNotice->sequence===['register','processing','processed'],'自动退款失败不把已到账通知标记失败');

$badNotice=new NoticeRepoFake();$badSettle=new SettlementFake();$badSettle->throwSettle=true;$badFactory=new GatewayFactoryFake();$badTx=new TxFake();$thrown=false;
try{(new \WorkerAdmin\Application\Money\PaymentNotificationProcessor($badNotice,$badSettle,$badFactory,$badTx))->process('{}',[]);}catch(RuntimeException $e){$thrown=$e->getMessage()==='settle failed';}
$assert($thrown,'到账事务失败继续向微信返回失败');
$assert($badNotice->sequence===['register','processing','failed'],'到账失败通知标记 failed 允许微信重试');

// 退款结果通知：只做本地事务收口，不触发新的外部退款请求。
$refundNotice=new NoticeRepoFake();$refundSettlement=new RefundSettlementFake();$refundGateway=new GatewayFake();$refundTx=new TxFake();
$result=(new \WorkerAdmin\Application\Money\RefundNotificationProcessor($refundNotice,$refundSettlement,$refundGateway,$refundTx))->process('{"refund":1}',['wechatpay-signature'=>'sig']);
$assert($result['duplicate']===false,'首次退款通知应执行');
$assert($refundGateway->decodeRefundFlags===[true],'退款回调必须以 refund=true 验签解密');
$assert($refundSettlement->sequence===['apply'],'退款结果只收口一次');
$assert($refundTx->sequence===['begin','commit'],'退款业务事实在单一数据库事务内完成');
$assert($refundNotice->sequence===['register','processing','processed'],'退款通知状态按 received/processing/processed 推进');
$assert($refundNotice->processed===['refund-notice-1',null],'保持旧语义：退款通知不回填 payment_order_id');

$refundDupNotice=new NoticeRepoFake();$refundDupNotice->should=false;$refundDupSettlement=new RefundSettlementFake();$refundDupGateway=new GatewayFake();$refundDupTx=new TxFake();
$result=(new \WorkerAdmin\Application\Money\RefundNotificationProcessor($refundDupNotice,$refundDupSettlement,$refundDupGateway,$refundDupTx))->process('{}',[]);
$assert($result['duplicate']===true,'processed 重复退款通知直接幂等成功');
$assert($refundDupSettlement->sequence===[]&&$refundDupTx->sequence===[],'重复退款通知不重复收口');

$refundBadNotice=new NoticeRepoFake();$refundBadSettlement=new RefundSettlementFake();$refundBadSettlement->throwApply=true;$refundBadGateway=new GatewayFake();$refundBadTx=new TxFake();$refundThrown=false;
try{(new \WorkerAdmin\Application\Money\RefundNotificationProcessor($refundBadNotice,$refundBadSettlement,$refundBadGateway,$refundBadTx))->process('{}',[]);}catch(RuntimeException $e){$refundThrown=$e->getMessage()==='refund settle failed';}
$assert($refundThrown,'退款收口失败继续向微信返回失败');
$assert($refundBadNotice->sequence===['register','processing','failed'],'退款收口失败标记 failed 允许微信重试');

$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$paymentModule=(string)file_get_contents($root.'/server/app/Http/Api/Modules/PaymentNotificationApiModule.php');
$refundModule=(string)file_get_contents($root.'/server/app/Http/Api/Modules/RefundNotificationApiModule.php');
$refundRepository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseRefundResultSettlement.php');
$controller=(is_file($root.'/server/app/Controllers/ApiController.php') ? (string)file_get_contents($root.'/server/app/Controllers/ApiController.php') : '');
foreach ([['PaymentNotificationApiModule','wechatpay.notify'],['RefundNotificationApiModule','wechatpay.refund_notify']] as [$name,$route]) {
    $owner=(new ReflectionClass('WorkerAdmin\\Http\\Api\\Modules\\'.$name))->newInstanceWithoutConstructor();
    $other=$route==='wechatpay.notify'?'wechatpay.refund_notify':'wechatpay.notify';
    $assert($owner->supports($route) && !$owner->supports($other),'回调模块归属唯一：'.$route);
    $assert(!$owner->requiresContentSecurity($route),'微信回调跳过 UGC 安全检查：'.$route);
    $assert(strpos($controller,"'".$route."'")===false,'旧特殊 dispatch 分支已退休：'.$route);
}
$assert(strpos($legacy,'$this->paymentNotifications')===false && strpos($legacy,'$this->refundNotifications')===false,'Legacy 不再分发两个回调模块');
$assert(strpos($refundRepository,"['ABNORMAL','CLOSED']")!==false,'退款失败/关闭状态由新 Infrastructure 收口');
$assert(strpos($refundRepository,'releaseRechargeRefundHoldLocked')!==false,'充值纠错退款失败继续释放冻结');
$assert(strpos($refundRepository,'applyImmediateRefundSuccess')!==false,'退款成功复用已迁出的成功收口而非复制逻辑');

$assert(strpos($controller,'private function wechatPayNotify(')===false && strpos($controller,'private function wechatPayNotifyResponse(')===false,'旧回调 handler 和响应 helper 已删除');
$assert(strpos($controller,'private function processPaidPayment(')===false && strpos($controller,'private function processRefundResource(')===false,'支付状态已迁出，旧到账/退款 helper 已退休');
echo "Payment/refund notification retirement tests passed.\n";
})();

<?php

declare(strict_types=1);

(static function (): void {
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Wallet recharge creation migration: '.$message);};
final class WalletRechargeTrace{public static array $events=[];}
final class WalletRechargeRepoFake implements \WorkerAdmin\Contracts\WalletRechargeCreationRepository{
    public ?array $existing=null;public ?array $concurrent=null;public array $payment=[];public int $recent=0;public bool $throwPrepare=false;public array $marked=[];
    public function findRequest(int $userId,string $clientRequestId):?array{return $this->existing;}
    public function createdInLastHour(int $userId):int{return $this->recent;}
    public function preparePayment(array $actor,int $amountCents,string $clientRequestId,string $apiVersion,string $appId,string $mchId,string $clientIp,int $expireMinutes):array{WalletRechargeTrace::$events[]='prepare';if($this->throwPrepare)throw new RuntimeException('duplicate key');return $this->payment;}
    public function recoverConcurrentPayment(int $userId,string $clientRequestId):?array{return $this->concurrent;}
    public function markPending(int $paymentId,string $prepayId):void{WalletRechargeTrace::$events[]='mark';$this->marked=[$paymentId,$prepayId];}
}
final class WalletRechargeGatewayFake implements \WorkerAdmin\Contracts\PaymentGateway{
    public string $version='v3';public int $createCalls=0;public int $clientCalls=0;
    public function apiVersion():string{return $this->version;}public function enabled():bool{return true;}
    public function createJsapiPayment(string $outTradeNo,string $description,int $amountCents,string $openid):array{WalletRechargeTrace::$events[]='create';$this->createCalls++;return ['prepay_id'=>'wx-prepay','payment'=>['timeStamp'=>'1']];}
    public function queryPayment(string $outTradeNo):array{return [];}public function clientPaymentParameters(string $prepayId):array{WalletRechargeTrace::$events[]='client';$this->clientCalls++;return ['prepay_id'=>$prepayId];}
    public function createRefund(string $outTradeNo,string $outRefundNo,int $refundCents,int $totalCents,string $reason):array{return [];}public function queryRefund(string $outRefundNo):array{return [];}public function decodeNotification(string $rawBody,array $headers,bool $refund=false):array{return [];}
}
final class WalletRechargeFactoryFake implements \WorkerAdmin\Contracts\PaymentGatewayFactory{
    public WalletRechargeGatewayFake $default;public WalletRechargeGatewayFake $versioned;public array $versions=[];
    public function __construct(){$this->default=new WalletRechargeGatewayFake();$this->versioned=new WalletRechargeGatewayFake();}
    public function defaultGateway():\WorkerAdmin\Contracts\PaymentGateway{return $this->default;}
    public function forVersion(string $apiVersion):\WorkerAdmin\Contracts\PaymentGateway{$this->versions[]=$apiVersion;$this->versioned->version=$apiVersion;return $this->versioned;}
}
final class WalletRechargeSettingsFake implements \WorkerAdmin\Contracts\SettingsProvider{
    public array $values=['wallet.recharge_min_cents'=>10,'wallet.recharge_max_cents'=>500000,'wallet.recharge_hourly_limit'=>10,'wallet.recharge_expire_minutes'=>30,'wechat.miniapp_appid'=>'wx-app','wechat.pay.mchid'=>'mch'];
    public function get(string $key,$default=null){return $this->values[$key]??$default;}public function bool(string $key,bool $default=false):bool{return (bool)($this->values[$key]??$default);}public function int(string $key,int $default=0):int{return (int)($this->values[$key]??$default);}
}
final class WalletRechargeTxFake implements \WorkerAdmin\Contracts\TransactionManager{public function run(callable $callback){WalletRechargeTrace::$events[]='begin';try{$v=$callback();WalletRechargeTrace::$events[]='commit';return $v;}catch(Throwable $e){WalletRechargeTrace::$events[]='rollback';throw $e;}}}

$actor=['user_id'=>7,'payer_openid'=>'openid-7'];$settings=new WalletRechargeSettingsFake();
$repo=new WalletRechargeRepoFake();$factory=new WalletRechargeFactoryFake();$tx=new WalletRechargeTxFake();
$repo->payment=['id'=>11,'public_no'=>'P1','amount_cents'=>100,'status'=>'created','api_version'=>'v3','description'=>'余额充值','payer_openid'=>'openid-7','wechat_prepay_id'=>null];
WalletRechargeTrace::$events=[];$result=(new \WorkerAdmin\Application\Money\WalletRechargeCreation($repo,$factory,$settings,$tx))->create($actor,['amount_cents'=>100,'client_request_id'=>'request_123456'],'127.0.0.1');
$assert($result['data']['status']==='pending'&&$repo->marked===[11,'wx-prepay'],'新充值创建并回写 prepay');
$assert(WalletRechargeTrace::$events===['begin','prepare','commit','create','mark'],'微信创建必须发生在数据库提交后');

$repo2=new WalletRechargeRepoFake();$factory2=new WalletRechargeFactoryFake();$repo2->existing=['amount_cents'=>100];$repo2->payment=['id'=>12,'public_no'=>'P2','amount_cents'=>100,'status'=>'pending','api_version'=>'v2','description'=>'余额充值','payer_openid'=>'openid-7','wechat_prepay_id'=>'wx-existing'];
WalletRechargeTrace::$events=[];(new \WorkerAdmin\Application\Money\WalletRechargeCreation($repo2,$factory2,$settings,new WalletRechargeTxFake()))->create($actor,['amount_cents'=>100,'client_request_id'=>'request_abcdef'],'127.0.0.1');
$assert($factory2->versions===['v2']&&$factory2->versioned->createCalls===0&&$factory2->versioned->clientCalls===1,'历史 prepay 按保存版本复用');

$repo3=new WalletRechargeRepoFake();$factory3=new WalletRechargeFactoryFake();$repo3->throwPrepare=true;$repo3->concurrent=['id'=>14,'public_no'=>'P4','amount_cents'=>100,'status'=>'pending','api_version'=>'v3','description'=>'余额充值','payer_openid'=>'openid-7','wechat_prepay_id'=>'wx-race'];
WalletRechargeTrace::$events=[];(new \WorkerAdmin\Application\Money\WalletRechargeCreation($repo3,$factory3,$settings,new WalletRechargeTxFake()))->create($actor,['amount_cents'=>100,'client_request_id'=>'request_race_12'],'127.0.0.1');
$assert(WalletRechargeTrace::$events===['begin','prepare','rollback','client'],'唯一键竞争回滚后复用并发支付单');

$expectCode=static function(callable $fn,string $code)use($assert):void{try{$fn();$assert(false,'应抛 '.$code);}catch(\WorkerAdmin\Domain\Money\WalletRechargeCreationException $e){$assert($e->apiCode()===$code,'稳定错误码 '.$code);}};
$expectCode(static function()use($actor,$factory,$settings){$r=new WalletRechargeRepoFake();(new \WorkerAdmin\Application\Money\WalletRechargeCreation($r,$factory,$settings,new WalletRechargeTxFake()))->create($actor,['amount_cents'=>1,'client_request_id'=>'request_amount1'],'127.0.0.1');},'WALLET_RECHARGE_AMOUNT_INVALID');
$expectCode(static function()use($actor,$factory,$settings){$r=new WalletRechargeRepoFake();$r->recent=10;(new \WorkerAdmin\Application\Money\WalletRechargeCreation($r,$factory,$settings,new WalletRechargeTxFake()))->create($actor,['amount_cents'=>100,'client_request_id'=>'request_rate_123'],'127.0.0.1');},'WALLET_RECHARGE_RATE_LIMIT');

$money=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MoneyApiModule.php');$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');$controller=(is_file($root.'/server/app/Controllers/ApiController.php') ? (string)file_get_contents($root.'/server/app/Controllers/ApiController.php') : '');$kernel=(string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$assert(strpos($money,"'wallet.recharge.create'=>'POST'")!==false&&strpos($money,"'wallet.recharge.cancel'=>'POST'")!==false,'充值创建和取消统一归 MoneyApiModule');
$assert(strpos($kernel,'new MoneyApiModule($db)')!==false,'MoneyApiModule 已注册 Kernel');
$assert(strpos($legacy,"'wallet.recharge.create'")!==false&&strpos($legacy,'walletRechargeCreation')===false,'Legacy 对充值仅 anti-fallback');
$assert(strpos($controller,"'wallet.recharge.create' => ['POST', 'walletRechargeCreate']")===false&&strpos($controller,'private function walletRechargeCreate(')===false,'旧创建代码暂留待物理退休');
$assert(strpos($money,"'wallet.summary'=>'GET'")!==false&&strpos($money,"'wallet.ledger'=>'GET'")!==false,'钱包读能力已一并收口 Money 板块');
$assert(strpos($money,"'DEMO_EXTERNAL_ACTION_BLOCKED'")!==false,'充值真实副作用仍阻断演示身份');

echo "Wallet recharge Money-module migration tests passed.\n";
})();

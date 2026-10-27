<?php

declare(strict_types=1);

(static function (): void {
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{
    $prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;
    $file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file))require_once $file;
});
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Money module migration: '.$message);};

final class MoneyModuleRepoFake implements \WorkerAdmin\Contracts\WorkerMoneyRepository
{
    public array $summaryCalls=[];public ?array $order=['id'=>51,'public_no'=>'O51'];public array $saved=[];public array $delays=[];public array $adjustments=[];
    public function financeSummary(array $actor,string $section,int $page,int $limit):array{$this->summaryCalls[]=[$section,$page,$limit];return ['receivables'=>[],'balance'=>['available_cents'=>100,'frozen_cents'=>0],'balance_entries'=>[],'account'=>null,'withdrawals'=>[],'pagination'=>[],'real_name'=>['status'=>'none'],'collection_codes'=>['default'=>['status'=>'none']],'collection_code'=>['status'=>'none']];}
    public function findOrderByPublicNo(string $publicNo):?array{return $publicNo==='missing'?null:$this->order;}
    public function savePayoutAccount(int $workerId,string $holderName,string $account):void{$this->saved[]=[$workerId,$holderName,$account];}
    public function createOfflineDelay(array $actor,array $order,string $reason,string $expectedCollectAt):void{$this->delays[]=[$actor['worker_id'],$order['id'],$reason,$expectedCollectAt];}
    public function submitOfflineAdjustment(array $actor,array $order,int $requestedTailCents,string $reason):string{$this->adjustments[]=[$actor['worker_id'],$order['id'],$requestedTailCents,$reason];return 'ADJ-1';}
}
final class MoneyModuleSettingsFake implements \WorkerAdmin\Contracts\SettingsProvider
{
    public function get(string $key,$default=null){return ['order.id_watermark_text'=>'测试水印','withdrawal.minimum_cents'=>250][$key]??$default;}
    public function bool(string $key,bool $default=false):bool{return $default;}
    public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
}
final class MoneyModuleTxFake implements \WorkerAdmin\Contracts\TransactionManager
{
    public int $runs=0;public bool $inside=false;
    public function run(callable $callback){$this->runs++;$this->inside=true;try{return $callback();}finally{$this->inside=false;}}
}
$repo=new MoneyModuleRepoFake();$tx=new MoneyModuleTxFake();$useCase=new \WorkerAdmin\Application\Money\WorkerMoneyOperations($repo,new MoneyModuleSettingsFake(),$tx);$actor=['user_id'=>7,'worker_id'=>9,'payer_openid'=>'openid'];
$summary=$useCase->summary($actor,['section'=>'bad','page'=>0]);
$assert($repo->summaryCalls===[['all',1,10]],'finance summary normalizes section/page and fixed page size');
$assert($summary['real_name_watermark_text']==='测试水印'&&$summary['minimum_withdraw_cents']===250,'finance summary appends existing configuration fields');
$save=$useCase->savePayoutAccount($actor,['holder_name'=>' 张三 ','account'=>'138 0013 8000']);
$assert($save['message']==='支付宝收款账号已保存'&&$repo->saved===[[9,'张三','13800138000']],'payout account normalizes and saves in transaction');
$assert($tx->runs===1,'payout account uses one transaction');
$invalidAccount=false;try{$useCase->savePayoutAccount($actor,['holder_name'=>'','account'=>'1']);}catch(\WorkerAdmin\Domain\Money\MoneyOperationException $e){$invalidAccount=$e->apiCode()==='INVALID_PAYOUT_ACCOUNT'&&$e->getCode()===422;}$assert($invalidAccount,'payout validation keeps stable code');
$badReason=false;try{$useCase->createOfflineDelay($actor,['order_id'=>'O51','reason'=>'短']);}catch(\WorkerAdmin\Domain\Money\MoneyOperationException $e){$badReason=$e->apiCode()==='INVALID_REASON';}$assert($badReason,'offline delay reason validation stays at Application boundary');
$badDate=false;try{$useCase->createOfflineDelay($actor,['order_id'=>'O51','reason'=>'延期原因完整','expected_collect_at'=>'not-a-date']);}catch(\WorkerAdmin\Domain\Money\MoneyOperationException $e){$badDate=$e->apiCode()==='INVALID_DATE';}$assert($badDate,'offline delay date validation keeps stable code');
$delay=$useCase->createOfflineDelay($actor,['order_id'=>'O51','reason'=>'客户约定稍后付款','expected_collect_at'=>'2026-10-10 12:00:00']);
$assert($delay['message']==='尾款延期已登记，提交即代表平台已知悉，正常收款无需再次操作'&&count($repo->delays)===1,'offline delay delegates as one Money use case');
$badTail=false;try{$useCase->submitOfflineAdjustment($actor,['order_id'=>'O51','requested_tail_cents'=>-1,'reason'=>'实际金额有变化']);}catch(\WorkerAdmin\Domain\Money\MoneyOperationException $e){$badTail=$e->apiCode()==='INVALID_TAIL_AMOUNT';}$assert($badTail,'tail amount stable validation code');
$adjust=$useCase->submitOfflineAdjustment($actor,['order_id'=>'O51','requested_tail_cents'=>12345,'reason'=>'现场实际用量发生变化']);
$assert($adjust['data']['id']==='ADJ-1'&&count($repo->adjustments)===1,'offline adjustment returns generated public id');
$missing=false;try{$useCase->createOfflineDelay($actor,['order_id'=>'missing','reason'=>'客户约定稍后付款']);}catch(\WorkerAdmin\Domain\Money\MoneyOperationException $e){$missing=$e->apiCode()==='ORDER_NOT_FOUND'&&$e->getCode()===404;}$assert($missing,'Money order lookup keeps ORDER_NOT_FOUND/404');

$money=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MoneyApiModule.php');
$kernel=(string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$controller=(string)file_get_contents($root.'/server/app/Controllers/ApiController.php');
$repository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseWorkerMoneyRepository.php');
$composition=(string)file_get_contents($root.'/server/app/Foundation/MoneyComposition.php');
$routes=[
    "'payments.status'=>'GET'","'wallet.summary'=>'GET'","'wallet.ledger'=>'GET'","'wallet.recharge.create'=>'POST'","'wallet.recharge.cancel'=>'POST'",
    "'finance.summary'=>'GET'","'finance.account.save'=>'POST'","'finance.withdraw'=>'POST'","'finance.withdraw.cancel'=>'POST'",
    "'commission.payment.create'=>'POST'","'offline.delay.create'=>'POST'","'offline.collection.adjustment.submit'=>'POST'",
];
foreach($routes as $route)$assert(strpos($money,$route)!==false,'MoneyApiModule missing '.$route);
$assert(strpos($kernel,'new MoneyApiModule($db)')!==false,'MoneyApiModule is formal Kernel owner');
$assert(strpos($legacy,'PaymentStatusApiModule')===false&&strpos($legacy,'WalletRechargeCreationApiModule')===false&&strpos($legacy,'WithdrawalCreationApiModule')===false&&strpos($legacy,'WithdrawalCancellationApiModule')===false&&strpos($legacy,'CommissionPaymentCreationApiModule')===false,'Legacy no longer instantiates per-route Money staging modules');
foreach(['payments.status','wallet.summary','wallet.ledger','wallet.recharge.create','finance.summary','finance.account.save','finance.withdraw','finance.withdraw.cancel','commission.payment.create','offline.delay.create','offline.collection.adjustment.submit'] as $route)$assert(strpos($legacy,"'".$route."'")!==false,'Legacy anti-fallback missing '.$route);
$assert(strpos($controller,"'finance.summary' => ['GET', 'financeSummary']")===false&&strpos($controller,"'offline.collection.adjustment.submit' => ['POST', 'submitOfflineCollectionAdjustment']")===false,'retired Controller implementation must stay absent: ');
foreach(['finance.realname.upload','finance.collection-code.upload','admin.withdrawals','admin.withdrawal.review','payments.create','wechatpay.notify','wechatpay.refund_notify'] as $route)$assert(strpos($money,"'".$route."'")===false,'MoneyApiModule must not absorb out-of-scope route '.$route);
foreach(['commission_receivables','worker_balance_ledger','worker_payout_accounts','offline_payment_delay_requests','offline_collection_adjustment_requests',"purpose='collection_receipt'","business_type='commission'",'commission_payments SET status=\'failed\'','OrderNotificationService::queueAdminTask'] as $needle)$assert(strpos($repository,$needle)!==false,'Worker Money persistence missing '.$needle);
$assert(strpos($repository,'?int $actorId')!==false,'system Money event actor explicitly supports null');
$assert(strpos($composition,'PaymentStatusLookup')!==false&&strpos($composition,'WalletRechargeCreation')!==false&&strpos($composition,'WithdrawalCreation')!==false&&strpos($composition,'CommissionPaymentCreation')!==false&&strpos($composition,'WorkerMoneyOperations')!==false,'single MoneyComposition assembles specialized use cases');
$oldRuntime=[
    'server/app/Http/Api/Modules/PaymentStatusApiModule.php','server/app/Http/Api/Modules/WalletRechargeCreationApiModule.php','server/app/Http/Api/Modules/WithdrawalCreationApiModule.php','server/app/Http/Api/Modules/WithdrawalCancellationApiModule.php','server/app/Http/Api/Modules/CommissionPaymentCreationApiModule.php','server/app/Http/Api/Modules/WalletRechargeApiModule.php',
    'server/app/Foundation/PaymentStatusComposition.php','server/app/Foundation/WalletRechargeCreationComposition.php','server/app/Foundation/WithdrawalCreationComposition.php','server/app/Foundation/WithdrawalCancellationComposition.php','server/app/Foundation/CommissionPaymentCreationComposition.php',
];
foreach($oldRuntime as $relative)$assert(!is_file($root.'/'.$relative),'obsolete Money migration scaffold must be deleted: '.$relative);

echo "Money module consolidation regression checks passed.\n";
})();

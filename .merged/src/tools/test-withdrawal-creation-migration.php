<?php

declare(strict_types=1);

(static function (): void {
$root=dirname(__DIR__);spl_autoload_register(static function(string $class)use($root):void{$prefix='WorkerAdmin\\';if(strpos($class,$prefix)!==0)return;$file=$root.'/server/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;});
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Withdrawal creation migration: '.$message);};
final class WithdrawalCreationTrace{public static array $events=[];}
final class WithdrawalCreationRepositoryFake implements \WorkerAdmin\Contracts\WithdrawalCreationRepository{
    public bool $approved=true;public function isRealNameApproved(int $workerId):bool{return $this->approved;}
    public function createPending(int $workerId,int $amountCents):array{WithdrawalCreationTrace::$events[]='create';return ['id'=>31,'public_no'=>'WT-31','amount_cents'=>$amountCents];}
}
final class WithdrawalSubmittedNotifierFake implements \WorkerAdmin\Contracts\WithdrawalSubmittedNotifier{
    public array $calls=[];public function notifySubmitted(int $withdrawalRequestId,string $publicNo,int $workerId,int $amountCents):void{WithdrawalCreationTrace::$events[]='notify';$this->calls[]=[$withdrawalRequestId,$publicNo,$workerId,$amountCents];}
}
final class WithdrawalSettingsFake implements \WorkerAdmin\Contracts\SettingsProvider{public function get(string $key,$default=null){return $key==='withdrawal.minimum_cents'?100:$default;}public function bool(string $key,bool $default=false):bool{return $default;}public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}}
final class WithdrawalTransactionFake implements \WorkerAdmin\Contracts\TransactionManager{public function run(callable $callback){WithdrawalCreationTrace::$events[]='begin';$v=$callback();WithdrawalCreationTrace::$events[]='commit';return $v;}}
$repo=new WithdrawalCreationRepositoryFake();$notifier=new WithdrawalSubmittedNotifierFake();$useCase=new \WorkerAdmin\Application\Money\WithdrawalCreation($repo,$notifier,new WithdrawalSettingsFake(),new WithdrawalTransactionFake());
WithdrawalCreationTrace::$events=[];$result=$useCase->create(['worker_id'=>8],['amount_cents'=>500]);
$assert($result['data']['withdrawal_no']==='WT-31'&&$result['message']==='提现申请已提交，平台审核后人工支付宝付款','成功响应保持兼容');
$assert(WithdrawalCreationTrace::$events===['begin','create','notify','commit'],'提现冻结与管理员待办同事务');
$repo->approved=false;$realName=false;try{$useCase->create(['worker_id'=>8],['amount_cents'=>0]);}catch(\WorkerAdmin\Domain\Money\WithdrawalCreationException $e){$realName=$e->apiCode()==='REAL_NAME_REQUIRED'&&$e->getCode()===403;}$assert($realName,'实名校验优先');
$repo->approved=true;$small=false;try{$useCase->create(['worker_id'=>8],['amount_cents'=>99]);}catch(\WorkerAdmin\Domain\Money\WithdrawalCreationException $e){$small=$e->apiCode()==='WITHDRAW_TOO_SMALL'&&$e->getCode()===422;}$assert($small,'最低提现金额错误码保持');

$controller=(string)file_get_contents($root.'/server/app/Controllers/ApiController.php');$legacy=(string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');$money=(string)file_get_contents($root.'/server/app/Http/Api/Modules/MoneyApiModule.php');$kernel=(string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');$repository=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseWithdrawalCreationRepository.php');$notifierSource=(string)file_get_contents($root.'/server/app/Infrastructure/Messaging/LegacyWithdrawalSubmittedNotifier.php');
$assert(strpos($money,"'finance.withdraw'=>'POST'")!==false&&strpos($money,"'finance.withdraw.cancel'=>'POST'")!==false,'提现创建/撤销统一归 Money HTTP 边界');
$assert(strpos($kernel,'new MoneyApiModule($db)')!==false,'MoneyApiModule 为正式 Kernel owner');
$assert(strpos($legacy,"'finance.withdraw'")!==false&&strpos($legacy,'withdrawalCreation')===false,'Legacy 只保留提现 anti-fallback');
$assert(strpos($controller,"'finance.withdraw' => ['POST', 'createWithdrawal']")===false&&strpos($controller,'private function createWithdrawal(')===false,'旧提现创建代码暂留');
$assert(strpos($money,"'DEMO_EXTERNAL_ACTION_BLOCKED'")!==false,'演示提现阻断保留');
$assert(strpos($repository,'SELECT id FROM worker_profiles WHERE id=:id FOR UPDATE')!==false,'worker 行锁保留');
$assert(strpos($repository,"'withdrawal_freeze'")!==false&&strpos($repository,'withdrawal_allocations')!==false&&strpos($repository,'withdrawal_events')!==false,'冻结/allocation/event 保留');
$assert(strpos($notifierSource,'OrderNotificationService::queueAdminTask')!==false,'提现管理员待办仍走 outbox');
echo "Withdrawal creation Money-module migration tests passed.\n";
})();

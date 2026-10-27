<?php

declare(strict_types=1);

$base=dirname(__DIR__).'/server/app';
spl_autoload_register(static function(string $class)use($base):void{$prefix='WorkerAdmin\\';if(strncmp($class,$prefix,strlen($prefix))!==0)return;$file=$base.'/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;});

use WorkerAdmin\Application\Money\WalletRechargeLifecycle;
use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\WalletRechargeRepository;

$assert=static function(bool $ok,string $msg):void{if(!$ok){fwrite(STDERR,"FAIL: {$msg}\n");exit(1);}};

final class RechargeRepoFake implements WalletRechargeRepository
{
    public array $actor=['session_id'=>1,'user_id'=>9];
    public array $payments=[]; public int $closed=0;
    public function findActorByTokenHash(string $tokenHash):?array{return $this->actor;}
    public function touchSession(int $sessionId):void{}
    public function findOwnedPayment(string $paymentNo,int $userId):?array{return $this->payments[$paymentNo]??null;}
    public function expiredPendingPayments(int $limit):array{return array_values(array_filter($this->payments,static fn($row)=>!empty($row['expired'])));}
    public function closeUnpaid(int $paymentId,int $rechargeId):bool{foreach($this->payments as &$row){if((int)$row['id']===$paymentId){$row['status']='closed';$row['recharge_status']='closed';$this->closed++;return true;}}return false;}
}
final class PaymentFake implements PaymentGateway
{
    public array $states=[];
    public function apiVersion():string{return 'v3';} public function enabled():bool{return true;}
    public function createJsapiPayment(string $outTradeNo,string $description,int $amountCents,string $openid):array{return [];}
    public function queryPayment(string $outTradeNo):array{return ['trade_state'=>$this->states[$outTradeNo]??'NOTPAY'];}
    public function clientPaymentParameters(string $prepayId):array{return [];}
    public function createRefund(string $outTradeNo,string $outRefundNo,int $refundCents,int $totalCents,string $reason):array{return [];}
    public function queryRefund(string $outRefundNo):array{return [];}
    public function decodeNotification(string $rawBody,array $headers,bool $refund=false):array{return [];}
}
$repo=new RechargeRepoFake();$pay=new PaymentFake();$service=new WalletRechargeLifecycle($repo,$pay);$token=str_repeat('a',64);
$repo->payments['P1']=['id'=>1,'public_no'=>'P1','status'=>'pending','wechat_prepay_id'=>'prepay','recharge_id'=>11,'recharge_status'=>'awaiting_payment'];$pay->states['P1']='NOTPAY';
$result=$service->cancelByUser($token,'P1');$assert($result['status']==='closed'&&$repo->closed===1,'user cancel should close confirmed NOTPAY recharge');
$repo->payments['P2']=['id'=>2,'public_no'=>'P2','status'=>'pending','wechat_prepay_id'=>'prepay','recharge_id'=>12,'recharge_status'=>'awaiting_payment','expired'=>true];$pay->states['P2']='NOTPAY';
$repo->payments['P3']=['id'=>3,'public_no'=>'P3','status'=>'pending','wechat_prepay_id'=>'prepay','recharge_id'=>13,'recharge_status'=>'awaiting_payment','expired'=>true];$pay->states['P3']='SUCCESS';
$stats=$service->closeExpired();$assert($stats['closed']===1&&$stats['confirming']===1,'expiry cleanup should close NOTPAY and preserve SUCCESS');
$repo->payments['P4']=['id'=>4,'public_no'=>'P4','status'=>'created','wechat_prepay_id'=>'','recharge_id'=>14,'recharge_status'=>'awaiting_payment','expired'=>true];
$stats=$service->closeExpired();$assert($repo->payments['P4']['status']==='closed','expired local create failure without prepay_id should close');
echo "Wallet recharge lifecycle regression passed.\n";

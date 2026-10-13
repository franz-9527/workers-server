<?php

declare(strict_types=1);

namespace WorkerAdmin\Core {
    class Database {
        public array $payments=[]; public ?array $terms=null;
        public function fetch($sql,array $params=[]){if(strpos($sql,'order_platform_terms')!==false)return $this->terms;throw new \RuntimeException('unexpected fetch');}
        public function fetchAll($sql,array $params=[]){if(strpos($sql,'FROM payment_orders')!==false)return $this->payments;throw new \RuntimeException('unexpected fetchAll');}
    }
}
namespace WorkerAdmin\Domain\Order {
    final class OrderOperationException extends \RuntimeException {private string $codeName;public function __construct(string $message,string $codeName,int $httpStatus=409){parent::__construct($message,$httpStatus);$this->codeName=$codeName;}public function apiCode():string{return $this->codeName;}}
}
namespace {
    require_once dirname(__DIR__).'/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php';
    use WorkerAdmin\Core\Database;use WorkerAdmin\Domain\Order\OrderOperationException;use WorkerAdmin\Infrastructure\Persistence\PlatformOrderBookingPaymentVerifier;
    function same($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,"[FAIL] $label actual=".var_export($actual,true)." expected=".var_export($expected,true)."\n");exit(1);}echo "[PASS] $label\n";}
    function blocked(callable $fn,string $code,string $label):void{try{$fn();same('accepted','blocked',$label);}catch(OrderOperationException $e){same($e->apiCode(),$code,$label);}}
    $v=new PlatformOrderBookingPaymentVerifier();
    $db=new Database();same($v->verifiedPaidCents($db,1,100000,5000,'platform_managed'),5000,'平台托管外部预付款无需微信付款单');
    $db=new Database();same($v->verifiedPaidCents($db,1,100000,0,'platform_managed'),0,'平台托管允许外部未预收款');
    $db=new Database();$db->payments=[['id'=>1,'amount_cents'=>5000]];blocked(fn()=>$v->verifiedPaidCents($db,1,100000,5000,'platform_managed'),'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','平台托管出现微信预约费作为资金串线阻断');
    $db=new Database();blocked(fn()=>$v->verifiedPaidCents($db,1,100000,5000,'miniapp_booking'),'PLATFORM_BOOKING_FLOW_INVALID','旧 miniapp_booking 不再进入新平台资金链');
    $db=new Database();blocked(fn()=>$v->verifiedPaidCents($db,1,100000,100001,'platform_managed'),'PLATFORM_BOOKING_AMOUNT_MISMATCH','外部预付款不得超过客户合同价');
    $db=new Database();$db->terms=['customer_flow_mode'=>'platform_managed','booking_paid_cents'=>8800];same($v->verifiedPaidCents($db,1,100000,null,null),8800,'兼容调用从平台条款读取外部预付款事实');
    $db=new Database();blocked(fn()=>$v->verifiedPaidCents($db,1,100000,null,null),'PLATFORM_TERMS_REQUIRED','缺少平台条款不能猜测支付来源');
    echo "V2.5.0 platform managed verifier isolated regression: OK\n";
}

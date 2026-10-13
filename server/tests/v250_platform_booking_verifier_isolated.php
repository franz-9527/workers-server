<?php

declare(strict_types=1);

namespace WorkerAdmin\Core {
    class Database {
        public array $payments=[];
        public array $refunds=[];
        public ?string $storedFlow=null;
        public bool $flowLookupThrows=false;
        public function fetchValue($sql,array $params=[]){if($this->flowLookupThrows)throw new \RuntimeException('legacy fixture');return $this->storedFlow;}
        public function fetchAll($sql,array $params=[]){if(strpos($sql,'FROM payment_orders')!==false)return $this->payments;if(strpos($sql,'FROM refunds')!==false)return $this->refunds;throw new \RuntimeException('unexpected query');}
    }
}
namespace WorkerAdmin\Domain\Order {
    final class OrderOperationException extends \RuntimeException {
        private string $codeName;
        public function __construct(string $message,string $codeName,int $httpStatus=409){parent::__construct($message,$httpStatus);$this->codeName=$codeName;}
        public function apiCode():string{return $this->codeName;}
    }
}
namespace {
    require_once dirname(__DIR__).'/app/Infrastructure/Persistence/PlatformOrderBookingPaymentVerifier.php';
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Domain\Order\OrderOperationException;
    use WorkerAdmin\Infrastructure\Persistence\PlatformOrderBookingPaymentVerifier;

    function same($actual,$expected,string $label):void{if($actual!==$expected){fwrite(STDERR,"[FAIL] $label actual=".var_export($actual,true)." expected=".var_export($expected,true)."\n");exit(1);}echo "[PASS] $label\n";}
    function blocked(callable $fn,string $code,string $label):void{try{$fn();same('accepted','blocked',$label);}catch(OrderOperationException $e){same($e->apiCode(),$code,$label);}}

    $v=new PlatformOrderBookingPaymentVerifier();
    $db=new Database();
    same($v->verifiedPaidCents($db,1,100000,5000,'platform_managed'),5000,'平台托管正预约费无需微信付款单');

    $db=new Database();$db->payments=[['id'=>1,'amount_cents'=>5000]];
    blocked(fn()=>$v->verifiedPaidCents($db,1,100000,5000,'platform_managed'),'PLATFORM_BOOKING_UNEXPECTED_PAYMENT','平台托管再出现微信预约费按重复收款异常阻断');

    $db=new Database();
    blocked(fn()=>$v->verifiedPaidCents($db,1,100000,5000,'miniapp_booking'),'PLATFORM_BOOKING_NOT_PAID','小程序预约仍要求真实微信支付');

    $db=new Database();$db->payments=[['id'=>1,'amount_cents'=>5000]];
    same($v->verifiedPaidCents($db,1,100000,5000,'miniapp_booking'),5000,'小程序预约真实微信付款金额一致通过');

    $db=new Database();$db->storedFlow='platform_managed';
    same($v->verifiedPaidCents($db,1,100000,5000),5000,'财务旧调用可从平台条款识别外部预约费');

    $db=new Database();$db->flowLookupThrows=true;
    blocked(fn()=>$v->verifiedPaidCents($db,1,100000,5000),'PLATFORM_BOOKING_NOT_PAID','旧夹具无法读取flow时安全回退为严格微信校验');

    $db=new Database();
    same($v->verifiedPaidCents($db,1,100000,0),0,'历史平台托管零预约费保持兼容');
    echo "V2.5.0 platform booking verifier isolated regression: OK\n";
}

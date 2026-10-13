<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface PlatformOrderAdminRepository {
        public function dashboard(): array;
        public function create(array $command, int $adminId): int;
        public function publish(int $orderId, int $adminId, int $quoteDays): void;
        public function selectQuote(int $orderId, int $quoteId, int $adminId): void;
        public function createInvite(int $orderId, int $adminId, int $validDays): array;
        public function revokeInvite(int $orderId, int $inviteId, int $adminId): void;
    }
    interface SettingsProvider {
        public function get(string $key, $default = null);
        public function bool(string $key, bool $default = false): bool;
        public function int(string $key, int $default = 0): int;
    }
}

namespace WorkerAdmin\Domain\Order {
    final class OrderOperationException extends \RuntimeException {
        private string $codeName;
        public function __construct(string $message, string $codeName, int $httpStatus = 409){parent::__construct($message,$httpStatus);$this->codeName=$codeName;}
        public function apiCode(): string { return $this->codeName; }
    }
    final class PlatformOrderSettlementPolicy {
        public const MODE_WORKER_COLLECT='worker_collect';
        public const MODE_PLATFORM_BALANCE='platform_balance';
        public const EXTRA_INCOME_FIXED_OFFSET=1000000000;
        public function settlement(int $total,int $labor,int $booking,string $mode): array {
            if ($mode===self::MODE_WORKER_COLLECT && $total-$labor-$booking<0) throw new OrderOperationException('negative','PLATFORM_SETTLEMENT_NEGATIVE',422);
            return [];
        }
    }
}

namespace {
    require_once dirname(__DIR__).'/app/Application/Order/PlatformOrderAdmin.php';

    use WorkerAdmin\Application\Order\PlatformOrderAdmin;
    use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
    use WorkerAdmin\Contracts\SettingsProvider;
    use WorkerAdmin\Domain\Order\OrderOperationException;

    final class Settings implements SettingsProvider {
        public function get(string $key,$default=null){
            $values=['order.booking_fee_cents'=>5000,'order.auto_cancel_days'=>7,'platform_order.extra_income_rate_bp'=>2000,'platform_order.worker_balance_freeze_days'=>0,'map.tencent_key'=>'test-key'];
            return array_key_exists($key,$values)?$values[$key]:$default;
        }
        public function bool(string $key,bool $default=false):bool{return (bool)$this->get($key,$default);}
        public function int(string $key,int $default=0):int{return (int)$this->get($key,$default);}
    }
    final class Repo implements PlatformOrderAdminRepository {
        public array $command=[];
        public function dashboard():array{return ['rows'=>[]];}
        public function create(array $command,int $adminId):int{$this->command=$command;return 1;}
        public function publish(int $orderId,int $adminId,int $quoteDays):void{}
        public function selectQuote(int $orderId,int $quoteId,int $adminId):void{}
        public function createInvite(int $orderId,int $adminId,int $validDays):array{return [];}
        public function revokeInvite(int $orderId,int $inviteId,int $adminId):void{}
    }
    function baseInput(): array { return [
        'user_id'=>11,'category_id'=>22,'title'=>'测试平台单','description'=>'第三方平台订单','city'=>'绍兴市','district'=>'越城区','address'=>'测试路1号',
        'map_lat'=>'29.95','map_lng'=>'120.56','contact_name'=>'测试客户','contact_mobile'=>'13800138000','customer_pricing_mode'=>'fixed_total','customer_total'=>'1000',
        'external_prepayment'=>'50','dispatch_mode'=>'multi_quote','worker_settlement_mode'=>'worker_collect'
    ];}
    function same($actual,$expected,string $label): void {if($actual!==$expected){fwrite(STDERR,"[FAIL] $label actual=".var_export($actual,true)." expected=".var_export($expected,true)."\n");exit(1);}echo "[PASS] $label\n";}

    $repo=new Repo();$service=new PlatformOrderAdmin($repo,new Settings());
    $input=baseInput();$service->create($input,9);
    same($repo->command['customer_flow_mode'],'platform_managed','平台承接单固定为托管模式');
    same($repo->command['external_prepayment_cents'],5000,'外部预付款保存为平台专属金额');
    same(array_key_exists('booking_fee_cents',$repo->command),false,'平台创建命令不携带普通微信预约费字段');

    $input=baseInput();$input['external_prepayment']='0';$service->create($input,9);same($repo->command['external_prepayment_cents'],0,'平台托管允许零预付款');
    $input=baseInput();unset($input['external_prepayment']);$service->create($input,9);same($repo->command['external_prepayment_cents'],0,'未提交外部预付款默认按零处理');

    $input=baseInput();$input['customer_flow_mode']='miniapp_booking';$service->create($input,9);
    same($repo->command['customer_flow_mode'],'platform_managed','旧客户端 miniapp_booking 参数不能恢复平台微信预约模式');
    same($repo->command['external_prepayment_cents'],5000,'旧客户流参数不改变外部预付款事实');

    $input=baseInput();$input['external_prepayment']='1000.01';try{$service->create($input,9);same('accepted','blocked','预付款不能超过成交价');}catch(OrderOperationException $e){same($e->apiCode(),'PLATFORM_TOTAL_BELOW_BOOKING','外部预付款超过成交价被阻断');}
    $input=baseInput();$input['external_prepayment']='abc';try{$service->create($input,9);same('accepted','blocked','非法外部预付款格式');}catch(OrderOperationException $e){same($e->apiCode(),'PLATFORM_BOOKING_AMOUNT_INVALID','非法外部预付款格式被阻断');}

    $input=baseInput();$input['dispatch_mode']='single_grab';$input['worker_labor']='960';try{$service->create($input,9);same('accepted','blocked','师傅代收负差额');}catch(OrderOperationException $e){same($e->apiCode(),'PLATFORM_SETTLEMENT_NEGATIVE','一口价工费结合外部预付款形成负差额时阻断');}

    $dashboard=$service->dashboard();
    same(array_key_exists('defaultBookingFee',$dashboard),false,'平台后台不再暴露普通预约费默认值');
    same($dashboard['defaultExtraRate'],2000,'平台加项收入默认比例仍正常');
    echo "V2.5.0 managed-only platform application isolated regression: OK\n";
}

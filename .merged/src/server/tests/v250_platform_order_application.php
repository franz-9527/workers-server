<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\PlatformOrderAdmin;
use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy;

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Contracts/PlatformOrderAdminRepository.php';
require_once $root . '/server/app/Contracts/SettingsProvider.php';
require_once $root . '/server/app/Domain/Order/OrderOperationException.php';
require_once $root . '/server/app/Domain/Order/PlatformOrderSettlementPolicy.php';
require_once $root . '/server/app/Application/Order/PlatformOrderAdmin.php';

function appPass(string $label): void { fwrite(STDOUT, "[PASS] {$label}\n"); }
function appFail(string $label, string $detail = ''): void { fwrite(STDERR, "[FAIL] {$label}" . ($detail !== '' ? "\n{$detail}" : '') . "\n"); exit(1); }
function appSame($actual, $expected, string $label): void { if ($actual !== $expected) appFail($label, 'expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)); appPass($label); }

final class FakePlatformOrderSettings implements SettingsProvider
{
    private array $values;
    public function __construct(array $values = []) { $this->values = $values + ['order.booking_fee_cents'=>5000,'order.auto_cancel_days'=>7,'platform_order.extra_income_rate_bp'=>2000,'platform_order.worker_balance_freeze_days'=>0,'map.tencent_key'=>'test-map-key']; }
    public function get(string $key, $default = null) { return array_key_exists($key, $this->values) ? $this->values[$key] : $default; }
    public function bool(string $key, bool $default = false): bool { return (bool)$this->get($key, $default); }
    public function int(string $key, int $default = 0): int { return (int)$this->get($key, $default); }
}

final class FakePlatformOrderRepository implements PlatformOrderAdminRepository
{
    public ?array $created = null; public int $createdBy = 0; public ?array $published = null; public ?array $selected = null; public ?array $inviteCreated = null; public ?array $inviteRevoked = null;
    public function dashboard(): array { return ['rows'=>[], 'users'=>[], 'categories'=>[], 'quotesByOrder'=>[], 'invitesByOrder'=>[]]; }
    public function create(array $command, int $adminId): int { $this->created=$command; $this->createdBy=$adminId; return 321; }
    public function publish(int $orderId, int $adminId, int $quoteDays): void { $this->published=[$orderId,$adminId,$quoteDays]; }
    public function selectQuote(int $orderId, int $quoteId, int $adminId): void { $this->selected=[$orderId,$quoteId,$adminId]; }
    public function createInvite(int $orderId, int $adminId, int $validDays): array { $this->inviteCreated=[$orderId,$adminId,$validDays]; return ['order_id'=>$orderId,'order_no'=>'TEST-321','invite_id'=>88,'token'=>'invite-token','expires_at'=>'2026-10-08 00:00:00','dispatch_mode'=>'multi_quote']; }
    public function revokeInvite(int $orderId, int $inviteId, int $adminId): void { $this->inviteRevoked=[$orderId,$inviteId,$adminId]; }
}

function basePlatformInput(): array
{
    return [
        'user_id'=>11,'category_id'=>22,'title'=>'客厅墙布安装','description'=>'外部渠道已与客户确认施工内容',
        'city'=>'绍兴市','district'=>'越城区','address'=>'测试路 1 号','map_lat'=>'29.950894','map_lng'=>'120.567175',
        'map_point_name'=>'测试定位点','map_point_address'=>'绍兴市越城区测试路附近','contact_name'=>'测试客户','contact_mobile'=>'13800138000',
        'customer_pricing_mode'=>'fixed_total','customer_total'=>'1000','external_prepayment'=>'50',
        'dispatch_mode'=>'multi_quote','worker_settlement_mode'=>'worker_collect',
    ];
}

$repo = new FakePlatformOrderRepository();
$service = new PlatformOrderAdmin($repo, new FakePlatformOrderSettings());
$orderId = $service->create(basePlatformInput(), 9);
appSame($orderId,321,'固定总价平台托管单可创建');
appSame($repo->createdBy,9,'创建操作保留管理员');
appSame($repo->created['customer_flow_mode']??null,'platform_managed','平台承接单固定为托管模式');
appSame($repo->created['customer_total_cents']??null,100000,'客户合同价按分保存');
appSame($repo->created['external_prepayment_cents']??null,5000,'外部已收预付款使用平台专属事实传入持久层');
appSame(array_key_exists('booking_fee_cents',$repo->created??[]),false,'平台创建命令不再携带普通微信预约费字段');
appSame($repo->created['fulfillment_mode']??null,'direct_install','默认直接施工');
appSame($repo->created['worker_labor_cents']??null,0,'多师傅报价创建时不预写师傅工费');
appSame($repo->created['extra_income_rate_bp']??null,2000,'订单外加项平台收入比例默认20%');
appSame($repo->created['lat']??null,29.950894,'地图纬度独立传给持久层');
appSame($repo->created['lng']??null,120.567175,'地图经度独立传给持久层');

$unbound=basePlatformInput();$unbound['user_id']=0;$unbound['fulfillment_mode']='measure_then_install';$service->create($unbound,9);
appSame($repo->created['user_id']??null,0,'平台托管允许客户尚未注册');
appSame($repo->created['fulfillment_mode']??null,'measure_then_install','先测量后施工继续使用同一平台单');

// 即使旧客户端偷偷提交 miniapp_booking，应用层也不采用该值，最终命令固定 platform_managed。
$legacy=basePlatformInput();$legacy['customer_flow_mode']='miniapp_booking';$service->create($legacy,9);
appSame($repo->created['customer_flow_mode']??null,'platform_managed','旧 miniapp_booking 请求不能恢复平台微信预约模式');

$zero=basePlatformInput();$zero['external_prepayment']='0';$service->create($zero,9);
appSame($repo->created['external_prepayment_cents']??null,0,'平台托管允许外部未预收款');

$over=basePlatformInput();$over['external_prepayment']='1000.01';$rejected=false;
try{$service->create($over,9);}catch(OrderOperationException $e){$rejected=$e->apiCode()==='PLATFORM_TOTAL_BELOW_BOOKING';}
appSame($rejected,true,'外部预付款不得超过客户合同价');

$itemized=basePlatformInput();$itemized['customer_pricing_mode']='itemized';unset($itemized['customer_total']);$itemized['item_description']=['贴墙布','撕墙纸'];$itemized['item_quantity']=['50.4','40'];$itemized['item_unit']=['平','平'];$itemized['item_unit_price']=['12','5'];$itemized['item_note']=['按实际面积','旧墙纸拆除'];$service->create($itemized,9);
appSame($repo->created['customer_total_cents']??null,80480,'结构化计价金额正确');
appSame(count($repo->created['pricing_items']??[]),2,'结构化开单保存两条计价项目');

$fixedGrab=basePlatformInput();$fixedGrab['dispatch_mode']='single_grab';$fixedGrab['worker_labor']='700';$service->create($fixedGrab,9);
appSame($repo->created['worker_labor_cents']??null,70000,'一口价抢单按师傅工费成交');

$negative=basePlatformInput();$negative['dispatch_mode']='single_grab';$negative['worker_labor']='960';$negativeRejected=false;
try{$service->create($negative,9);}catch(OrderOperationException $e){$negativeRejected=$e->apiCode()==='PLATFORM_SETTLEMENT_NEGATIVE';}
appSame($negativeRejected,true,'师傅代收创建阶段按外部预付款计算并禁止负差额');

$balance=basePlatformInput();$balance['dispatch_mode']='single_grab';$balance['worker_settlement_mode']='platform_balance';$balance['worker_labor']='700';$service->create($balance,9);
appSame($repo->created['worker_settlement_mode']??null,'platform_balance','平台余额结算可与一口价组合');

$fixedIncome=basePlatformInput();$fixedIncome['extra_income_mode']='fixed';$fixedIncome['extra_income_fixed']='50.00';$service->create($fixedIncome,9);
appSame($repo->created['extra_income_rate_bp']??null,PlatformOrderSettlementPolicy::EXTRA_INCOME_FIXED_OFFSET+5000,'平台外加项支持固定收入快照');

$service->publish(321,9);appSame($repo->published,[321,9,7],'发布平台单沿用报价期限设置');
$service->selectQuote(321,654,9);appSame($repo->selected,[321,654,9],'平台选择师傅工费报价进入统一成交动作');
$invite=$service->createInvite(321,9,7);appSame($invite['invite_id']??null,88,'平台邀请返回一次性口令记录');
$service->revokeInvite(321,88,9);appSame($repo->inviteRevoked,[321,88,9],'平台邀请可撤销');

echo "\nV2.5.0 platform managed order application regression: OK\n";

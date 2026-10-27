<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function pricingReviewAssert(bool $condition, string $label): void
{
    if (!$condition) { fwrite(STDERR, '[FAIL] ' . $label . "\n"); exit(1); }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}
function pricingReviewSource(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    if (!is_string($value)) { fwrite(STDERR, '[FAIL] 无法读取 ' . $relative . "\n"); exit(1); }
    return $value;
}

require_once $root . '/server/app/Domain/Order/OrderOperationException.php';
require_once $root . '/server/app/Contracts/PlatformOrderPricingReviewRepository.php';
require_once $root . '/server/app/Application/Order/PlatformOrderPricingReview.php';

final class FakePlatformOrderPricingReviewRepository implements \WorkerAdmin\Contracts\PlatformOrderPricingReviewRepository
{
    public array $last = [];
    public function review(string $orderKey, array $command, int $adminId): array
    {
        $this->last=['action'=>'review','order_key'=>$orderKey,'command'=>$command,'admin_id'=>$adminId];
        return ['workflow_profile'=>'construction','advanced_to_construction'=>true,'pricing_review_status'=>'confirmed','requires_customer_confirmation'=>false];
    }
    public function confirm(string $orderKey, int $userId): array
    {
        throw new \WorkerAdmin\Domain\Order\OrderOperationException('平台托管核价无需客户二次确认', 'PLATFORM_PRICING_CONFIRM_NOT_REQUIRED', 409);
    }
}

$fake=new FakePlatformOrderPricingReviewRepository();
$app=new \WorkerAdmin\Application\Order\PlatformOrderPricingReview($fake);
$adminRequired=false;
try{$app->review(['is_admin'=>false,'user_id'=>8],['order_id'=>'WO-1','worker_labor'=>'300']);}
catch(\WorkerAdmin\Domain\Order\OrderOperationException $e){$adminRequired=$e->apiCode()==='ADMIN_REQUIRED';}
pricingReviewAssert($adminRequired,'平台测量后核价仅管理员可提交');

$result=$app->review(['is_admin'=>true,'real_user_id'=>99],[
    'order_id'=>'WO-PLATFORM-250','worker_labor'=>'368.50','note'=>'按测量结果核实际墙布用量',
    'items'=>[['sort_order'=>1,'quantity'=>'50.4'],['sort_order'=>2,'quantity'=>'12.125']],
]);
pricingReviewAssert(($result['advanced_to_construction']??false)===true,'核价后同一订单进入施工衔接');
pricingReviewAssert(($result['pricing_review_status']??'')==='confirmed','平台托管核价完成立即生效');
pricingReviewAssert(($result['requires_customer_confirmation']??true)===false,'平台托管不再等待客户小程序确认价格');
pricingReviewAssert(($fake->last['command']['worker_labor_cents']??0)===36850,'师傅施工工费使用整数分');
pricingReviewAssert(($fake->last['command']['items'][0]['quantity_milli']??0)===50400,'实际数量转换为千分位整数');
pricingReviewAssert(($fake->last['command']['items'][1]['quantity_milli']??0)===12125,'实际数量最多保留三位小数');
pricingReviewAssert(($fake->last['admin_id']??0)===99,'核价审计使用真实管理员 ID');

$measurementPolicy=pricingReviewSource('server/app/Infrastructure/Persistence/PlatformPricingAwareOrderMeasurementCompletionRepository.php');
$pricingRepository=pricingReviewSource('server/app/Infrastructure/Persistence/DatabasePlatformOrderPricingReviewRepository.php');
$readiness=pricingReviewSource('server/app/Infrastructure/Persistence/PlatformOrderPricingReviewReadiness.php');
$apiModule=pricingReviewSource('server/app/Http/Api/Modules/PlatformOrderPricingReviewApiModule.php');
$legacy=pricingReviewSource('server/app/Http/Api/Modules/LegacyApiModule.php');
$kernel=pricingReviewSource('server/app/Http/Api/ApiKernel.php');
$container=pricingReviewSource('server/app/Foundation/OrderApplicationContainer.php');
$measurementComponent=pricingReviewSource('miniprogram/components/measurement-order-detail/index.js');
$measurementView=pricingReviewSource('miniprogram/components/measurement-order-detail/index.wxml');
$pricingUi=pricingReviewSource('miniprogram/pages/adminWorkbench/pricingReview.js');
$appointmentRepository=pricingReviewSource('server/app/Infrastructure/Persistence/DatabaseOrderAppointmentRepository.php');
$fulfillmentContext=pricingReviewSource('server/app/Infrastructure/Persistence/DatabaseOrderFulfillmentContextRepository.php');
$fulfillmentApplication=pricingReviewSource('server/app/Application/Order/OrderFulfillment.php');

pricingReviewAssert(strpos($measurementPolicy,"result_status='accepted'")!==false,'平台师傅提交完整测量成果后自动确认成果');
pricingReviewAssert(strpos($measurementPolicy,'awaiting_pricing_review')!==false,'平台测量确认后进入待核价');
pricingReviewAssert(strpos($container,'PlatformPricingAwareOrderMeasurementCompletionRepository')!==false,'平台核价感知测量策略接入组合根');
pricingReviewAssert(strpos($pricingRepository,"customer_flow_mode='platform_managed'")!==false,'核价仓储只接受平台托管模式');
pricingReviewAssert(strpos($pricingRepository,"booking_fee_cents")!==false && strpos($pricingRepository,"!== 0")!==false,'平台核价拒绝残留普通预约费字段');
pricingReviewAssert(strpos($pricingRepository,'booking_paid_cents')!==false,'核价使用平台专属外部预付款事实');
pricingReviewAssert(strpos($pricingRepository,'PlatformOrderBookingPaymentVerifier')!==false,'核价检查微信预约费资金串线');
pricingReviewAssert(strpos($pricingRepository,'intdiv($quantityMilli * $unitPrice + 500, 1000)')!==false,'核价金额使用整数定点计算');
pricingReviewAssert(strpos($pricingRepository,"workflow_profile='construction'")!==false,'核价成功后原地进入施工工作流');
pricingReviewAssert(strpos($pricingRepository,"platform_measurement_pricing_reviewed")!==false,'核价保留独立审计事件');
pricingReviewAssert(strpos($pricingRepository,"platform_measurement_pricing_confirmed")!==false,'同一事务形成施工价格生效事件');
pricingReviewAssert(strpos($pricingRepository,"confirmation_mode'=>'platform_managed_auto'")!==false,'托管核价由系统自动生效');
pricingReviewAssert(strpos($pricingRepository,"requires_customer_confirmation'=>false")!==false,'仓储明确不要求客户确认');
pricingReviewAssert(strpos($pricingRepository,'PLATFORM_PRICING_CONFIRM_NOT_REQUIRED')!==false,'旧确认仓储入口明确退役而非继续业务');
pricingReviewAssert(strpos($readiness,"customer_flow_mode='platform_managed'")!==false,'核价可操作条件固定平台托管');
pricingReviewAssert(strpos($readiness,"business_type IN ('order_booking','booking_fee')")!==false,'核价可操作条件拦截平台单微信预约费串线');

pricingReviewAssert(strpos($apiModule,'platform.pricing.confirm')===false,'平台核价 API 不再暴露客户确认路由');
pricingReviewAssert(strpos($legacy,"'platform.pricing.confirm'")===false,'退役确认路由不再标记为已迁移接口');
pricingReviewAssert(strpos($kernel,'PlatformOrderPricingReviewApiModule')!==false,'管理员核价 API 模块仍正常注册');
pricingReviewAssert(strpos($apiModule,'SELECT ')===false,'核价 Http 模块保持零 SQL');
pricingReviewAssert(strpos($measurementComponent,'platform.pricing.confirm')===false,'测量组件不再调用客户确认接口');
pricingReviewAssert(strpos($measurementComponent,'confirmPlatformPricing')===false,'测量组件不再保留客户确认动作');
pricingReviewAssert(strpos($measurementView,'awaiting_customer_confirmation')===false,'测量 UI 不再展示等待客户确认状态');
pricingReviewAssert(strpos($measurementView,'确认施工价格')===false,'测量 UI 不再显示客户确认按钮');
pricingReviewAssert(strpos($measurementView,'平台核价已生效')!==false,'客户端明确展示平台核价已生效');
pricingReviewAssert(strpos($pricingUi,"admin.platform.pricing.review")!==false,'管理员工作台仍调用独立核价接口');

// 下游预约/履约继续以 pricing_confirmed 事件作为通用闸门；新核价事务会立即产生该事件，
// 因此无需重写成熟的预约和履约模块，也不会出现“核价完成但无法施工”的死路。
pricingReviewAssert(strpos($appointmentRepository,'platform_measurement_pricing_confirmed')!==false,'施工预约继续复用价格生效事件闸门');
pricingReviewAssert(strpos($fulfillmentContext,'platform_measurement_pricing_confirmed')!==false,'履约上下文继续复用价格生效事件闸门');
pricingReviewAssert(strpos($fulfillmentApplication,'assertPlatformConstructionPricingConfirmed')!==false,'施工写动作继续统一受价格生效闸门保护');

echo "\nV2.5.0 platform managed measurement pricing review regression: OK\n";

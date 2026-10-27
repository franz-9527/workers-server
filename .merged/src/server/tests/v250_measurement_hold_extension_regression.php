<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderMeasurementHoldExtension;
use WorkerAdmin\Contracts\OrderMeasurementHoldExtensionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/OrderMeasurementHoldExtensionRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Order/OrderMeasurementHoldExtension.php';

function measurementHoldAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeMeasurementHoldExtensionRepository implements OrderMeasurementHoldExtensionRepository
{
    public array $result = [];
    public array $call = [];
    public array $reviewResult = [];
    public array $reviewCalls = [];

    public function request(array $actor, string $orderNo, int $days, string $reason): array
    {
        $this->call = [$actor,$orderNo,$days,$reason];
        return $this->result;
    }

    public function review(string $requestId, string $decision, string $note, array $operator): array
    {
        $this->reviewCalls[] = [$requestId,$decision,$note,$operator];
        return $this->reviewResult;
    }
}

$repo = new FakeMeasurementHoldExtensionRepository();
$app = new OrderMeasurementHoldExtension($repo);
$repo->result = ['id'=>'MH1','status'=>'approved','auto_approved'=>true,'message'=>'首次小范围延期已自动批准，并已通知师傅。'];
$result = $app->request(['user_id'=>7], ['order_id'=>'CL-1','days'=>5,'reason'=>'材料还没有最终确定']);
measurementHoldAssert(($repo->call[1] ?? '') === 'CL-1', '测量延期传递统一订单号');
measurementHoldAssert(($repo->call[2] ?? 0) === 5, '测量延期默认业务仍使用5天范围');
measurementHoldAssert(($result['data']['auto_approved'] ?? false) === true, '首次小范围延期可自动批准');

$repo->result = ['id'=>'MH2','status'=>'pending','auto_approved'=>false,'message'=>'本次延期需要平台审核。'];
$result = $app->request(['user_id'=>7], ['order_id'=>'CL-1','days'=>5,'reason'=>'仍需继续保留测量优惠']);
measurementHoldAssert(($result['data']['auto_approved'] ?? true) === false, '重复延期仍进入平台审核');

$invalid = false;
try { $app->request(['user_id'=>7], ['order_id'=>'','days'=>5]); }
catch (OrderOperationException $e) { $invalid = $e->apiCode() === 'ORDER_ID_REQUIRED'; }
measurementHoldAssert($invalid, '无订单号延期请求被阻断');

$repo->reviewResult = ['status'=>'approved','order_id'=>1,'message'=>'延期已批准'];
$review = $app->review(['user_id'=>7], ['request_id'=>'MH-PENDING','decision'=>'approved','note'=>'核实后同意']);
$miniReviewCall = $repo->reviewCalls[0] ?? [];
measurementHoldAssert(($miniReviewCall[0] ?? '') === 'MH-PENDING', '管理员审核传递延期申请编号');
measurementHoldAssert(($miniReviewCall[1] ?? '') === 'approved', '管理员审核使用稳定 decision');
measurementHoldAssert(($miniReviewCall[3]['channel'] ?? '') === 'mini_admin', '历史小程序调用兼容为明确 mini_admin ID 域');
measurementHoldAssert(($miniReviewCall[3]['user_id'] ?? 0) === 7, '管理员审核事件追踪真实 users.id');
measurementHoldAssert(($review['data']['status'] ?? '') === 'approved', '审核结果回传 Application');

$repo->reviewResult = ['status'=>'rejected','order_id'=>1,'message'=>'延期申请已驳回'];
$webReview = $app->review(['channel'=>'web_admin','admin_user_id'=>77], [
    'request_id'=>'MH-WEB','decision'=>'rejected','note'=>'当前风险状态未解除，暂不延长冻结期',
]);
$webReviewCall = $repo->reviewCalls[1] ?? [];
measurementHoldAssert(($webReview['data']['status'] ?? '') === 'rejected', 'Web 管理员复用同一测量延期审核用例');
measurementHoldAssert(($webReviewCall[3]['channel'] ?? '') === 'web_admin' && ($webReviewCall[3]['admin_user_id'] ?? 0) === 77, 'Web 延期审核显式携带 admin_users.id 域');

$badChannel = false;
try {
    $app->review(['channel'=>'legacy_admin','user_id'=>7], ['request_id'=>'MH-X','decision'=>'approved','note'=>'旧来源']);
} catch (OrderOperationException $e) {
    $badChannel = $e->apiCode() === 'ADMIN_REQUIRED';
}
measurementHoldAssert($badChannel, '测量延期审核拒绝未知管理员来源');

$rejectNoteRequired = false;
try { $app->review(['user_id'=>7], ['request_id'=>'MH-PENDING','decision'=>'rejected','note'=>'']); }
catch (OrderOperationException $e) { $rejectNoteRequired = $e->apiCode() === 'REVIEW_NOTE_REQUIRED'; }
measurementHoldAssert($rejectNoteRequired, '驳回延期必须填写审核原因');

$application = (string)file_get_contents($root.'/server/app/Application/Order/OrderMeasurementHoldExtension.php');
$repository = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderMeasurementHoldExtensionRepository.php');
$module = (string)file_get_contents($root.'/server/app/Http/Api/Modules/OrderMeasurementHoldExtensionApiModule.php');
$kernel = (string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$legacy = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$mini = (string)file_get_contents($root.'/miniprogram/components/measurement-order-detail/detail-core.js');
$webModule = (string)file_get_contents($root.'/server/app/Http/Admin/Modules/MeasurementHoldReviewAdminModule.php');
$webView = (string)file_get_contents($root.'/server/views/admin/measurement-hold-review.php');
$adminKernel = (string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$workbenchView = (string)file_get_contents($root.'/server/views/admin/exception-dashboard.php');

measurementHoldAssert(strpos($kernel, 'new OrderMeasurementHoldExtensionApiModule($db)') !== false, '测量延期申请与审核进入模块化 ApiKernel');
measurementHoldAssert(strpos($module, "'admin.measurement.hold.extend.review'") !== false, '测量延期审核由同一领域模块接管');
measurementHoldAssert(strpos($legacy, "'orders.measurement.hold.extend.request'") !== false, '测量延期请求从 Legacy 回退排除');
measurementHoldAssert(strpos($legacy, "'admin.measurement.hold.extend.review'") !== false, '测量延期审核从 Legacy 回退排除');
measurementHoldAssert(strpos($application, "\$channel === 'web_admin'") !== false && strpos($application, "'admin_user_id'=>\$adminId") !== false, 'Application 显式区分 Web 与小程序管理员 ID 域');
measurementHoldAssert(strpos($repository, "measurement.auto_hold_extension_days', 5") !== false, '首次自动延期范围可由后台设置并默认5天');
measurementHoldAssert(strpos($repository, '$historyCount === 0') !== false, '只有首次延期允许自动批准');
measurementHoldAssert(strpos($repository, 'max_hold_until') !== false, '自动批准仍受最长冻结期限硬约束');
measurementHoldAssert(strpos($repository, "['negotiating','open','processing','pending']") !== false, '争议状态强制转人工审核');
measurementHoldAssert(strpos($repository, 'order_cancel_requests') !== false && strpos($repository, 'refund_status') !== false, '退款/取消状态强制转人工审核');
measurementHoldAssert(strpos($repository, "relation_type='measurement_construction'") !== false, '已关联施工单后不再允许延长测量冻结期');
measurementHoldAssert(strpos($repository, "\$channel === 'mini_admin'") !== false && strpos($repository, "\$channel === 'web_admin'") !== false, 'Repository 只按明确管理员通道解析审核人');
measurementHoldAssert(strpos($repository, "'reviewed_by'=>\$reviewedBy") !== false, 'Web 审核可写真实 reviewed_by，mini 保持 NULL');
measurementHoldAssert(strpos($repository, "'operator_user_id'=>\$channel === 'mini_admin' ? \$operatorId : null") !== false, 'mini 审核事件只写 users.id 审计字段');
measurementHoldAssert(strpos($repository, "'operator_admin_id'=>\$channel === 'web_admin' ? \$operatorId : null") !== false, 'Web 审核事件单独写 admin_users.id 审计字段');
measurementHoldAssert(strpos($repository, "'notify_recipients'=>['user','worker']") !== false, '自动延长冻结期同时通知用户和师傅');
measurementHoldAssert(strpos($mini, "days: 5") !== false && strpos($mini, 'orders.measurement.hold.extend.request') !== false, '小程序继续保持一次申请5天的简单操作');

measurementHoldAssert(strpos($webModule, 'AdminWorkbenchReadComposition') !== false, 'Web 延期审核先从统一异常读模型确认任务');
measurementHoldAssert(strpos($webModule, 'OrderApplicationContainer') !== false && strpos($webModule, '->measurementHoldExtension()->review(') !== false, 'Web 延期审核复用统一 Application');
measurementHoldAssert(strpos($webModule, "['channel'=>'web_admin','admin_user_id'=>\$adminId]") !== false, 'Web 延期模块显式传真实后台管理员 ID 域');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    measurementHoldAssert(strpos($webModule, $sqlToken) === false, 'Web 延期审核模块不得包含 SQL: '.trim($sqlToken));
}
measurementHoldAssert(strpos($webView, 'measurement_hold_approve') !== false && strpos($webView, 'measurement_hold_reject') !== false, 'Web 页面只渲染统一异常任务允许的批准/驳回动作');
measurementHoldAssert(strpos($webView, '不会突破本单最长冻结期限') !== false, 'Web 页面明确批准时间仍由服务端限制');
$measurementModulePos = strpos($adminKernel, 'new MeasurementHoldReviewAdminModule(');
$legacyModulePos = strpos($adminKernel, 'new LegacyAdminModule(');
measurementHoldAssert($measurementModulePos !== false && $legacyModulePos !== false && $measurementModulePos < $legacyModulePos, 'Web 测量延期模块在 Legacy 前注册');
measurementHoldAssert(strpos($workbenchView, "\$taskType === 'measurement_hold_review'") !== false, '异常工作台识别测量冻结延期任务');
measurementHoldAssert(strpos($workbenchView, "admin_url('measurement-hold-review'") !== false, '测量延期异常卡片直接进入专项审核页');

echo "\nV2.5.0 measurement hold extension automation regression: OK\n";

<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderCancellation;
use WorkerAdmin\Contracts\OrderCancellationRepository;
use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/OrderCancellationRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Domain/Admin/WorkbenchTaskProjection.php';
require_once $root.'/server/app/Application/Order/OrderCancellation.php';

function cancelAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeCancellationRepository implements OrderCancellationRepository
{
    public bool $auto = false;
    public array $calls = [];
    public array $reviewCalls = [];

    public function request(array $actor, string $orderNo, string $reason, string $reasonCode): array
    {
        $this->calls[] = [$actor,$orderNo,$reason,$reasonCode];
        return [
            'request_id'=>'CR-TEST',
            'auto_approved'=>$this->auto,
            'refund_cents'=>1888,
            'refund_count'=>$this->auto ? 1 : 0,
        ];
    }

    public function review(array $operator, string $orderNo, string $decision, string $workerArrived, string $responsibility, string $reason, int $refundCents): array
    {
        $this->reviewCalls[] = [$operator,$orderNo,$decision,$workerArrived,$responsibility,$reason,$refundCents];
        return ['approved'=>$decision === 'approve','refund_cents'=>$decision === 'approve' ? $refundCents : 0,'refund_ids'=>$decision === 'approve' ? [1] : []];
    }
}

$repo = new FakeCancellationRepository();
$app = new OrderCancellation($repo);
$actor = ['user_id'=>101];

$manual = $app->request($actor, ['order_id'=>'OD-1','reason'=>'用户计划发生变化']);
cancelAssert(($manual['data']['auto_approved'] ?? true) === false, '缺少协商对方的资金异常保留平台核对');
cancelAssert(strpos((string)$manual['message'], '等待平台核对') !== false, '人工审核分支文案明确');
cancelAssert(($repo->calls[0][1] ?? '') === 'OD-1', '订单号传入取消仓储');

$repo->auto = true;
$auto = $app->request($actor, ['order_id'=>'OD-2','reason'=>'尚未成交，不再需要施工']);
cancelAssert(($auto['data']['auto_approved'] ?? false) === true, '低风险取消可返回自动批准');
cancelAssert(strpos((string)$auto['message'], '无需管理员审核') !== false, '自动取消明确告知无需管理员审核');

try {
    $app->request($actor, ['order_id'=>'OD-3','reason'=>'取消']);
    cancelAssert(false, '过短取消原因必须被拒绝');
} catch (OrderOperationException $exception) {
    cancelAssert($exception->apiCode() === 'REASON_REQUIRED', '过短原因返回稳定业务错误码');
}

$review = $app->review(['user_id'=>909], [
    'order_id'=>'OD-REVIEW',
    'decision'=>'approve',
    'worker_arrived'=>'yes',
    'responsibility'=>'worker',
    'reason'=>'师傅确认无法继续履约，同意取消退款',
    'refund_amount_cents'=>1200,
]);
$reviewCall = $repo->reviewCalls[0] ?? [];
cancelAssert(($review['data']['approved'] ?? false) === true, '管理员退款审核通过结果回传');
cancelAssert(($reviewCall[0]['channel'] ?? '') === 'mini_admin' && ($reviewCall[0]['user_id'] ?? 0) === 909, '历史小程序调用兼容为明确 mini_admin ID 域');
cancelAssert(($reviewCall[1] ?? '') === 'OD-REVIEW' && ($reviewCall[2] ?? '') === 'approve', '退款审核只向仓储传递必要业务参数');
cancelAssert(($reviewCall[6] ?? 0) === 1200, '退款金额以分为单位传递');

$webReview = $app->review(['channel'=>'web_admin','admin_user_id'=>77], [
    'order_id'=>'OD-WEB-REVIEW',
    'decision'=>'reject',
    'worker_arrived'=>'no',
    'responsibility'=>'user',
    'reason'=>'Web 后台核对后确认本次申请不符合退款条件',
    'refund_amount_cents'=>1888,
]);
$webReviewCall = $repo->reviewCalls[1] ?? [];
cancelAssert(($webReview['data']['approved'] ?? true) === false, 'Web 管理员复用同一取消退款审核用例');
cancelAssert(($webReviewCall[0]['channel'] ?? '') === 'web_admin' && ($webReviewCall[0]['admin_user_id'] ?? 0) === 77, 'Web 退款审核显式携带 admin_users.id 域');

$badChannel = false;
try {
    $app->review(['channel'=>'legacy_admin','user_id'=>909], [
        'order_id'=>'OD-REVIEW','decision'=>'reject','responsibility'=>'user','reason'=>'旧管理员来源不能绕过显式 ID 域',
    ]);
} catch (OrderOperationException $exception) {
    $badChannel = $exception->apiCode() === 'ADMIN_REQUIRED';
}
cancelAssert($badChannel, '退款审核拒绝未知管理员来源');

$badDecision = false;
try {
    $app->review(['user_id'=>909], ['order_id'=>'OD-REVIEW','decision'=>'x','reason'=>'审核说明']);
} catch (OrderOperationException $exception) {
    $badDecision = $exception->apiCode() === 'INVALID_REFUND_DECISION';
}
cancelAssert($badDecision, '退款审核动作使用稳定业务错误码校验');

// 双方已经同意但进入资金执行核账后，语义属于 finance attention，而不是再次退款裁决。
$executionTask = ['task_type'=>'refund_execution_attention','mode'=>'attention'];
cancelAssert(WorkbenchTaskProjection::matchesScope($executionTask, 'finance'), '双方已同意后的退款执行异常进入 finance scope');
cancelAssert(!WorkbenchTaskProjection::matchesScope($executionTask, 'refund_dispute'), '退款执行核账不回到普通退款/争议裁决 scope');
cancelAssert(WorkbenchTaskProjection::isException($executionTask), '退款执行核账仍属于管理员异常工作台事项');

$applicationSource = (string)file_get_contents($root.'/server/app/Application/Order/OrderCancellation.php');
$repositorySource = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderCancellationRepository.php');
$workbenchSource = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php');
$moduleSource = (string)file_get_contents($root.'/server/app/Http/Api/Modules/OrderCancellationApiModule.php');
$kernelSource = (string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$legacySource = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$webModuleSource = (string)file_get_contents($root.'/server/app/Http/Admin/Modules/RefundReviewAdminModule.php');
$webViewSource = (string)file_get_contents($root.'/server/views/admin/refund-review.php');
$adminKernelSource = (string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$dashboardSource = (string)file_get_contents($root.'/server/views/admin/exception-dashboard.php');
$retrySource = (string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryBridge.php');
$retryProcessorSource = (string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php');
$gatewaySource = (string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/LegacySchedulerGateway.php');

cancelAssert(strpos($kernelSource, 'new OrderCancellationApiModule($db)') !== false, '取消模块已接入 ApiKernel');
cancelAssert(strpos($moduleSource, "'admin.refund.review'") !== false, '管理员退款审核由取消领域模块接管');
cancelAssert(strpos($moduleSource, 'requireWorkbenchAdmin') !== false, '管理员退款审核继续执行小程序管理员鉴权');
cancelAssert(strpos($legacySource, "'orders.cancel.request'") !== false, '取消路由已从 Legacy 回退排除');
cancelAssert(strpos($legacySource, "'admin.refund.review'") !== false, '退款审核已从 Legacy 回退排除');
cancelAssert(strpos($applicationSource, "\$channel === 'web_admin'") !== false && strpos($applicationSource, "'admin_user_id'=>\$adminId") !== false, 'Application 显式区分 Web 后台管理员 ID 域');
cancelAssert(strpos($repositorySource, "\$channel === 'mini_admin'") !== false && strpos($repositorySource, "\$channel === 'web_admin'") !== false, 'Repository 只按明确管理员通道解析 ID');
cancelAssert(strpos($repositorySource, "'requested_by_admin_id'=>\$decidedBy") !== false, 'Web 审核生成退款单时写入真实 requested_by_admin_id');
cancelAssert(strpos($repositorySource, "'decided_by'=>\$decidedBy") !== false, 'Web 退款裁定写入真实 decided_by');
cancelAssert(strpos($repositorySource, "'operator_user_id'=>\$channel === 'mini_admin' ? \$operatorId : null") !== false, 'mini 管理员仅写 users.id 审计字段');
cancelAssert(strpos($repositorySource, "'operator_admin_id'=>\$channel === 'web_admin' ? \$operatorId : null") !== false, 'Web 管理员单独写 admin_users.id 审计字段');
cancelAssert(strpos($repositorySource, "order_source_type'] ?? 'consumer') !== 'consumer'") !== false, '平台/商家单不走自动取消');
foreach (['assignments','quote_selections','appointment_records','change_requests','disputes','after_sales','run_empty_fee_claims'] as $table) {
    cancelAssert(strpos($repositorySource, 'FROM '.$table.' WHERE order_id=') !== false, '自动取消检查 '.$table.' 历史事实');
}
cancelAssert(strpos($repositorySource, "lifecycle_status='cancel_approved_pending_refund'") !== false, '自动批准只进入待原路退款生命周期');
cancelAssert(strpos($repositorySource, "'notify_recipients'=>(\$agreed || \$workerFault) ? ['user','worker'] : ['user']") !== false, '自动取消事件只通知用户不制造管理员噪音');
cancelAssert(strpos($repositorySource, "WHERE id=:id AND status='pending'") !== false, '退款审核用 pending 条件写防止重复处理');
cancelAssert(strpos($repositorySource, 'REFUND_AMOUNT_EXCEEDS_AVAILABLE') !== false, '服务端重新校验当前最大可退金额');
cancelAssert(strpos($repositorySource, 'REFUND_EXECUTION_WEB_REQUIRED') !== false, '双方已同意但资金复杂时普通审核接口硬阻断');
cancelAssert(strpos($repositorySource, "reason_code'] === 'bilateral_refund_execution_required'") !== false, '资金执行状态在事务锁内重新校验');
cancelAssert(strpos($workbenchSource, "cr.reason_code='bilateral_refund_execution_required'") !== false, '工作台单独识别双方已同意的退款执行核账');
cancelAssert(strpos($workbenchSource, "'refund_execution_attention'") !== false, '资金核账投影为退款执行异常而非再次退款裁决');
cancelAssert(strpos($workbenchSource, "'双方已同意退款，待财务核账执行'") !== false, '工作台标题明确双方协议已成立');
cancelAssert(strpos($workbenchSource, "'attention',[]") !== false, '资金执行异常在小程序只提醒且无通过/拒绝按钮');
cancelAssert(strpos($workbenchSource, "\$task['facts']=[") !== false && strpos($workbenchSource, "'requested_refund_cents'=>max(0,(int)\$row['requested_refund_cents'])") !== false, '普通退款审核任务提供结构化申请金额给 Web 页面');
cancelAssert(strpos($repositorySource, "'publish_status'=>\$order['publish_status'] ?? null") !== false, '取消申请快照保存真实 publish_status');
cancelAssert(strpos($repositorySource, 'decodeSnapshot') !== false && strpos($repositorySource, "'restored_publish_status'=>\$restorePublish") !== false, '拒绝退款从申请前快照恢复发布状态并写入审计事件');
cancelAssert(strpos($repositorySource, "if (\$hasMeasurementLink) \$restorePublish = 'hidden';") !== false, '关联测量施工单拒绝退款后仍强制保持隐藏');

cancelAssert(strpos($webModuleSource, 'AdminWorkbenchReadComposition') !== false, 'Web 退款审核先从统一异常读模型确认任务');
cancelAssert(strpos($webModuleSource, 'OrderApplicationContainer') !== false && strpos($webModuleSource, '->cancellation()->review(') !== false, 'Web 退款审核复用统一取消 Application');
cancelAssert(strpos($webModuleSource, "['channel'=>'web_admin','admin_user_id'=>\$adminId]") !== false, 'Web 退款模块显式传真实后台管理员 ID 域');
cancelAssert(substr_count($webModuleSource, 'currentRefundTask(') >= 3, 'Web GET 和 POST 都校验当前 request_id 仍是异常任务');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    cancelAssert(strpos($webModuleSource, $sqlToken) === false, 'Web 退款审核模块不得包含 SQL: '.trim($sqlToken));
}
cancelAssert(strpos($webViewSource, "\$facts['requested_refund_cents']") !== false, 'Web 退款页直接消费结构化申请金额而非解析摘要');
cancelAssert(strpos($webViewSource, '服务端会重新锁定订单、取消申请和原支付') !== false, 'Web 退款页明确最终金额以事务内资金事实为准');
$refundModulePos = strpos($adminKernelSource, 'new RefundReviewAdminModule(');
$legacyModulePos = strpos($adminKernelSource, 'new LegacyAdminModule(');
cancelAssert($refundModulePos !== false && $legacyModulePos !== false && $refundModulePos < $legacyModulePos, 'Web 退款审核模块在 Legacy 前接管');
cancelAssert(strpos($dashboardSource, "\$taskType === 'refund_review'") !== false, '异常工作台识别普通取消退款审核任务');
cancelAssert(strpos($dashboardSource, "admin_url('refund-review'") !== false, '普通退款异常卡片直接进入专项审核页');

cancelAssert(strpos($retryProcessorSource, "c.status IN ('approved','processing')") !== false, '退款重试桥同时覆盖自动批准与人工批准');
cancelAssert(strpos($retryProcessorSource, "r.status IN ('pending_review','approved')") !== false, '退款在已抢占 approved 后进程中断仍可继续恢复');
cancelAssert(strpos($retrySource, "status IN ('approved','processing')") !== false, '退款提交成功写回允许幂等处理中状态');
cancelAssert(strpos($gatewaySource, "\$task === 'auto_cancel' || \$task === 'all'") !== false, '自动取消任务会推进已批准退款重试');

echo "\nV2.5.0 order cancellation automation regression: OK\n";

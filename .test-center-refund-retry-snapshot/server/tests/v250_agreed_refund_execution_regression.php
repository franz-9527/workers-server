<?php

declare(strict_types=1);

use WorkerAdmin\Application\Admin\AgreedRefundExecution;
use WorkerAdmin\Contracts\AgreedRefundExecutionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/AgreedRefundExecutionRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Admin/AgreedRefundExecution.php';

function agreedRefundAssert(bool $condition, string $label): void
{
    if (!$condition) { fwrite(STDERR, '[FAIL] '.$label."\n"); exit(1); }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeAgreedRefundExecutionRepository implements AgreedRefundExecutionRepository
{
    public array $calls = [];
    public function cases(): array { return [['request_id'=>'CR-AGREED-1','order_no'=>'OD-1','requested_refund_cents'=>1200,'current_refundable_cents'=>1200,'can_prepare'=>true]]; }
    public function prepare(string $requestNo, string $note, int $adminId): array
    {
        $this->calls[] = [$requestNo,$note,$adminId];
        return ['request_id'=>$requestNo,'order_no'=>'OD-1','refund_cents'=>1200,'refund_count'=>1,'refund_ids'=>[91],'requires_manual_submit'=>true];
    }
}

$repo = new FakeAgreedRefundExecutionRepository();$app = new AgreedRefundExecution($repo);
agreedRefundAssert(count($app->cases()) === 1, 'Web 财务页读取双方已同意待执行退款');
$result = $app->prepare(['request_id'=>'CR-AGREED-1','note'=>'已核对微信原支付和历史退款，金额一致'], 7);
agreedRefundAssert(($result['requires_manual_submit'] ?? true) === false, '财务核账已经是最后一次人工确认，不再要求二次提交');
agreedRefundAssert(($result['auto_submit_queued'] ?? false) === true, '核账完成的退款进入可靠自动原路退款执行');
agreedRefundAssert(($repo->calls[0][0] ?? '') === 'CR-AGREED-1', 'Application 只按协议公开编号执行');
agreedRefundAssert(($repo->calls[0][2] ?? 0) === 7, 'Web admin_users.id 显式传入财务仓储');
$shortNote=false;try{$app->prepare(['request_id'=>'CR-AGREED-1','note'=>'核账'],7);}catch(OrderOperationException $exception){$shortNote=$exception->apiCode()==='AGREED_REFUND_EXECUTION_NOTE_REQUIRED';}agreedRefundAssert($shortNote,'资金执行必须填写明确核账说明');
$badAdmin=false;try{$app->prepare(['request_id'=>'CR-AGREED-1','note'=>'已完成真实资金核对'],0);}catch(OrderOperationException $exception){$badAdmin=$exception->apiCode()==='ADMIN_REQUIRED';}agreedRefundAssert($badAdmin,'Web 财务执行必须是有效后台管理员');

$applicationSource=(string)file_get_contents($root.'/server/app/Application/Admin/AgreedRefundExecution.php');
$repositorySource=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseAgreedRefundExecutionRepository.php');
$moduleSource=(string)file_get_contents($root.'/server/app/Http/Admin/Modules/AgreedRefundExecutionAdminModule.php');
$kernelSource=(string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$viewSource=(string)file_get_contents($root.'/server/views/admin/refund-execution.php');
$resourceListSource=(string)file_get_contents($root.'/server/views/admin/resource-list.php');
$cancellationSource=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderCancellationRepository.php');
$orderDetailQuerySource=(string)file_get_contents($root.'/server/app/Application/Order/OrderDetailQuery.php');
$workbenchSource=(string)file_get_contents($root.'/server/app/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php');
$retrySource=(string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryBridge.php');
$retryProcessorSource=(string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/ApprovedOrderRefundRetryProcessor.php');
$financeSource=(string)file_get_contents($root.'/server/app/Application/Money/WebAdminFinance.php');

agreedRefundAssert(strpos($moduleSource, "['refunds.execution','refunds.execution.prepare']") !== false, '专用 Web 模块完整接管退款执行核账路由');
agreedRefundAssert(strpos($moduleSource, "requirePermission('refunds.manage')") !== false, '退款执行复用退款财务权限');
agreedRefundAssert(strpos($kernelSource, 'new AgreedRefundExecutionAdminModule($db, $auth, $csrf)') !== false, '退款执行模块已注册到 AdminKernel 且位于 Legacy 前');
agreedRefundAssert(strpos($applicationSource, "\$result['requires_manual_submit'] = false") !== false, 'Application 明确核账完成后无需二次人工提交');
agreedRefundAssert(strpos($applicationSource, "\$result['auto_submit_queued'] = true") !== false, 'Application 明确退款进入自动执行队列');
agreedRefundAssert(strpos($repositorySource, "reason_code='bilateral_refund_execution_required'") !== false, 'Repository 只读取双方已同意但需平台执行的记录');
agreedRefundAssert(strpos($repositorySource, "['active','cancel_approved_pending_refund']") !== false, 'Web 财务桥接受双方同意后已冻结履约的订单并兼容旧 active 记录');
agreedRefundAssert(strpos($repositorySource, "'created'") !== false, 'Repository 事务内仍只生成 created 退款，不在持锁事务中调用外部微信');
agreedRefundAssert(strpos($repositorySource, 'message_outbox') === false, '生成退款单阶段禁止直接写微信退款发件箱');
agreedRefundAssert(strpos($repositorySource, 'WeChatPayService') === false, '生成退款单阶段禁止直接调用微信退款接口');
agreedRefundAssert(strpos($repositorySource, 'AGREED_REFUND_AMOUNT_CHANGED') !== false, '真实可退金额变化时硬阻断');
agreedRefundAssert(strpos($repositorySource, 'AGREED_REFUND_DISPUTE_ACTIVE') !== false, '未结争议阻断退款执行');
agreedRefundAssert(strpos($repositorySource, 'AGREED_REFUND_RUN_EMPTY_ACTIVE') !== false, '未结跑空费阻断退款执行');
agreedRefundAssert(strpos($repositorySource, 'worker_balance_ledger') !== false, '非平台单会核对已生效资金台账');
agreedRefundAssert(strpos($repositorySource, "reason_code='bilateral_refund_execution_prepared'") !== false, '生成退款单后进入独立财务执行状态');
agreedRefundAssert(strpos($repositorySource, 'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)') !== false, '财务执行审计事件必须写 event_key');
agreedRefundAssert(strpos($repositorySource, "'event_key'=>'agreed_refund_execution_prepared:'.(int)\$request['id']") !== false, '财务执行审计事件使用稳定协议 ID 幂等键');
agreedRefundAssert(strpos($cancellationSource, "reason_code='bilateral_refund_execution_required'") !== false, '双方接受但资金复杂时转入财务执行语义');
agreedRefundAssert(strpos($cancellationSource, "lifecycle_status='cancel_approved_pending_refund',refund_status='pending_review',publish_status='hidden'") !== false, '双方接受后立即冻结订单履约而不是只改 reason_code');
agreedRefundAssert(strpos($cancellationSource, "UPDATE assignments SET status='cancelled'") !== false, '双方接受后真实师傅指派同步关闭');
agreedRefundAssert(strpos($cancellationSource, "UPDATE order_deadlines SET status='cancelled'") !== false, '双方接受后自动履约期限同步关闭');
agreedRefundAssert(strpos($cancellationSource, '订单已停止履约；涉及资金分配或可退金额变化') !== false, '双方收到明确的停止履约与待财务执行提示');
agreedRefundAssert(strpos($orderDetailQuerySource, "=== 'cancel_approved_pending_refund'") !== false, '订单详情把冻结待退款状态识别为退款处理中');
agreedRefundAssert(strpos($orderDetailQuerySource, "\$lifecycleActive = (string)(\$order['lifecycle_status'] ?? '') === 'active';") !== false, '订单详情动作继续要求 active 生命周期，冻结后不再开放履约操作');
agreedRefundAssert(strpos($viewSource, '不重新判断是否同意退款') !== false, 'Web 页面明确平台不能重新否定双方协议');
agreedRefundAssert(strpos($viewSource, '本次核账就是最后一次人工确认') !== false, 'Web 页面明确核账后无需第二次管理员审核');
agreedRefundAssert(strpos($viewSource, '自动进入原路退款') !== false, 'Web 页面明确后续退款由系统推进');
agreedRefundAssert(strpos($moduleSource, '无需再到退款管理重复审核') !== false, 'Web 核账成功提示不再要求第二次退款审核');
agreedRefundAssert(strpos($resourceListSource, "admin_url('refunds.execution')") !== false, '现有退款管理页提供双方协议待执行入口');
agreedRefundAssert(strpos($resourceListSource, '双方协议待执行') !== false, '退款执行入口文案对财务管理员可发现');
agreedRefundAssert(strpos($resourceListSource, '$isAgreedRefund=') !== false, '退款管理保留双方协议专属解释文案');
agreedRefundAssert(strpos($resourceListSource, "\$isLockedRefund=!empty(\$row['cancel_request_id'])") !== false, '退款管理按 cancel_request_id 事实锁定取消类退款金额，不再靠 reason 文案决定权限');
agreedRefundAssert(strpos($resourceListSource, '取消/退款申请已锁定本次退款金额，财务确认阶段不可修改') !== false, '普通取消类退款也明确显示金额已锁定');
agreedRefundAssert(strpos($resourceListSource, '双方已接受该退款金额，财务确认阶段不可修改') !== false, '双方协议退款在 Web 提交阶段显示金额锁定');
agreedRefundAssert(strpos($resourceListSource, '可输入部分退款金额，提交前会再次校验原支付单可退余额。') !== false, '非取消类独立退款仍保留部分退款输入能力');
agreedRefundAssert(strpos($cancellationSource, 'REFUND_EXECUTION_WEB_REQUIRED') !== false, '小程序退款审核继续拒绝执行双方已同意的财务记录');
agreedRefundAssert(strpos($workbenchSource, "'refund_execution_attention'") !== false, '小程序工作台只投影财务执行提醒');
agreedRefundAssert(strpos($retryProcessorSource, "r.status IN ('pending_review','approved')") !== false, '可靠退款桥继续覆盖原取消退款的 pending/approved 恢复');
agreedRefundAssert(strpos($retryProcessorSource, "r.status='created'") !== false && strpos($retryProcessorSource, "c.reason_code='bilateral_refund_execution_prepared'") !== false, '可靠退款桥只自动抢占已完成财务核账的双方协议 created 退款');
agreedRefundAssert(strpos($retryProcessorSource, "UPDATE refunds\n             SET status='approved'") !== false, '财务核账退款在调用微信前先原子抢占为 approved');
agreedRefundAssert(strpos($financeSource, "['created','pending_review','requested','approved']") !== false, '正式 Finance Application 继续兼容 created/approved 退款');
agreedRefundAssert(strpos($financeSource, "if (!empty(\$refund['cancel_request_id']))") !== false, '取消/协商退款在正式 Finance Application 中锁定拆分金额');
agreedRefundAssert(strpos($financeSource, '取消订单退款已按原支付单拆分，单笔金额不可再次修改。') !== false, '正式 Finance Application 拒绝修改双方协议生成的退款金额');
agreedRefundAssert(!is_file($root.'/server/app/Controllers/AdminController.php'), '退休 AdminController 不得恢复');

echo "\nV2.5.0 agreed refund Web execution regression: OK\n";

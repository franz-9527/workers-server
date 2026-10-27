<?php

declare(strict_types=1);

use WorkerAdmin\Application\Admin\FailedRefundRecovery;
use WorkerAdmin\Contracts\FailedRefundRecoveryRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/FailedRefundRecoveryRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Application/Admin/FailedRefundRecovery.php';

function failedRefundAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeFailedRefundRecoveryRepository implements FailedRefundRecoveryRepository
{
    public array $calls = [];

    public function cases(): array
    {
        return [[
            'id'=>9,'refund_no'=>'RF-FAIL-9','order_no'=>'OD-9','amount_cents'=>1200,
            'failure_code'=>'ABNORMAL','recovery_kind'=>'abnormal',
        ]];
    }

    public function detail(int $refundId): array
    {
        $this->calls[] = ['detail',$refundId];
        return ['id'=>$refundId,'refund_no'=>'RF-FAIL-'.$refundId,'failure_code'=>'ABNORMAL'];
    }

    public function recheck(int $refundId, int $adminId): array
    {
        $this->calls[] = ['recheck',$refundId,$adminId];
        return ['refund_id'=>$refundId,'refund_no'=>'RF-FAIL-'.$refundId,'wechat_status'=>'PROCESSING','local_status'=>'processing','recovered'=>true];
    }

    public function replaceClosed(int $refundId, int $adminId): array
    {
        $this->calls[] = ['replaceClosed',$refundId,$adminId];
        return [
            'old_refund_id'=>$refundId,'old_refund_no'=>'RF-CLOSED-'.$refundId,
            'refund_id'=>99,'refund_no'=>'RF-NEW-99','amount_cents'=>1200,
            'status'=>'created','requires_manual_submit'=>true,
        ];
    }
}

$repo = new FakeFailedRefundRecoveryRepository();
$app = new FailedRefundRecovery($repo);
failedRefundAssert(count($app->cases()) === 1, '失败退款页面读取待恢复记录');
failedRefundAssert(($app->detail(['id'=>9])['id'] ?? 0) === 9, '详情只按退款内部 ID 查询');
$result = $app->recheck(['id'=>9], 7);
failedRefundAssert(($result['recovered'] ?? false) === true, 'Application 只把有效原退款恢复到统一查单链');
failedRefundAssert(($repo->calls[1][2] ?? 0) === 7, '失败退款恢复显式传入 Web admin_users.id');
$replacement = $app->replaceClosed(['id'=>10], 7);
failedRefundAssert(($replacement['requires_manual_submit'] ?? false) === true, 'CLOSED 替代退款只生成 created，仍需人工提交微信');
failedRefundAssert(($repo->calls[2][0] ?? '') === 'replaceClosed', 'CLOSED 使用独立替代退款用例，不复用普通重试');

$badId = false;
try {
    $app->detail(['id'=>0]);
} catch (OrderOperationException $exception) {
    $badId = $exception->apiCode() === 'REFUND_ID_REQUIRED';
}
failedRefundAssert($badId, '失败退款详情拒绝无效退款 ID');

$badAdmin = false;
try {
    $app->recheck(['id'=>9], 0);
} catch (OrderOperationException $exception) {
    $badAdmin = $exception->apiCode() === 'ADMIN_REQUIRED';
}
failedRefundAssert($badAdmin, '重新查询微信状态必须是有效后台管理员');

$badReplaceAdmin = false;
try {
    $app->replaceClosed(['id'=>9], 0);
} catch (OrderOperationException $exception) {
    $badReplaceAdmin = $exception->apiCode() === 'ADMIN_REQUIRED';
}
failedRefundAssert($badReplaceAdmin, 'CLOSED 生成替代退款也必须是有效后台管理员');

$repositorySource = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseFailedRefundRecoveryRepository.php');
$moduleSource = (string)file_get_contents($root.'/server/app/Http/Admin/Modules/FailedRefundRecoveryAdminModule.php');
$kernelSource = (string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$listViewSource = (string)file_get_contents($root.'/server/views/admin/refund-failures.php');
$detailViewSource = (string)file_get_contents($root.'/server/views/admin/refund-failure.php');
$resourceListSource = (string)file_get_contents($root.'/server/views/admin/resource-list.php');
$schedulerSource = (string)file_get_contents($root.'/server/app/Services/SchedulerService.php');

failedRefundAssert(strpos($repositorySource, "r.status='failed' AND r.cancel_request_id IS NOT NULL") !== false, '失败恢复列表只接管取消订单退款');
failedRefundAssert(strpos($repositorySource, 'FAILED_REFUND_RECOVERY_UNSUPPORTED') !== false, '非取消退款即使直访也由 Repository 硬阻断');
failedRefundAssert(strpos($repositorySource, 'FAILED_REFUND_DEMO_UNSUPPORTED') !== false, '演示退款直访禁止查询真实微信');
failedRefundAssert(strpos($repositorySource, "['SUCCESS','PROCESSING','ABNORMAL','CLOSED']") !== false, '只接受已知微信退款状态');
failedRefundAssert(strpos($repositorySource, 'FAILED_REFUND_RECHECK_ID_MISMATCH') !== false, '微信退款单号与本地不一致时停止同步');
failedRefundAssert(strpos($repositorySource, 'FAILED_REFUND_RECHECK_AMOUNT_MISMATCH') !== false, '微信退款金额与本地退款金额不一致时停止同步');
failedRefundAssert(strpos($repositorySource, 'FAILED_REFUND_RECHECK_PAYMENT_FACT_MISMATCH') !== false, '微信商户、原支付单或原支付总额不一致时停止同步');
failedRefundAssert(strpos($repositorySource, 'payment_total_cents') !== false && strpos($repositorySource, 'payment_mch_id') !== false, '恢复查单与统一调度器同级核对原支付总额和商户号');
failedRefundAssert(strpos($repositorySource, "SET status='processing'") !== false, '微信仍有效时只恢复为 processing');
failedRefundAssert(strpos($repositorySource, "SET status='succeeded'") === false, '失败恢复入口不得自行伪造退款成功');
failedRefundAssert(strpos($repositorySource, 'createRefund(') === false, '失败恢复入口禁止直接调用普通退款接口');
failedRefundAssert(strpos($repositorySource, 'queryRefund(') !== false, '失败恢复与 CLOSED 替代前都先调用微信退款查单');
failedRefundAssert(strpos($repositorySource, '系统不会采集银行卡信息或重复发起普通退款') !== false, 'ABNORMAL 继续留给微信异常退款流程处理');
failedRefundAssert(strpos($repositorySource, 'CLOSED_REFUND_STATE_REQUIRED') !== false, '只有微信真实 CLOSED 才能生成替代退款');
failedRefundAssert(strpos($repositorySource, 'CLOSED_REFUND_REPLACEMENT_EXISTS') !== false, '同一原支付和取消申请禁止重复生成替代退款');
failedRefundAssert(strpos($repositorySource, 'CLOSED_REFUND_ARCHIVE_CHANGED') !== false, '归档旧 CLOSED 尝试时并发状态变化会整事务回滚');
failedRefundAssert(strpos($repositorySource, "cancel_request_id=NULL") !== false, '旧 CLOSED 尝试归档后脱离当前取消申请聚合');
failedRefundAssert(strpos($repositorySource, "failure_code='CLOSED_REPLACED'") !== false, '旧 CLOSED 尝试保留明确替代归档标记');
failedRefundAssert(strpos($repositorySource, "'created'") !== false, 'CLOSED 替代只生成 created 待提交退款单');

failedRefundAssert(strpos($moduleSource, "['refunds.failures','refunds.failure','refunds.failure.recheck','refunds.failure.replace-closed']") !== false, '专用 Web 模块完整接管失败退款与 CLOSED 替代路由');
failedRefundAssert(strpos($moduleSource, "requirePermission('refunds.manage')") !== false, '失败退款恢复复用退款财务权限');
failedRefundAssert(strpos($kernelSource, 'new FailedRefundRecoveryAdminModule($db, $auth, $csrf)') !== false, '失败退款恢复模块已注册到 AdminKernel');
failedRefundAssert(strpos($listViewSource, '钱包充值纠错、测量迟到退款等特殊退款不进入本页') !== false, '列表明确特殊退款不由通用恢复入口处理');
failedRefundAssert(strpos($detailViewSource, '平台后台不收集银行卡信息') !== false, 'ABNORMAL 页面不新增银行卡敏感信息采集');
failedRefundAssert(strpos($detailViewSource, '重新查询微信状态') !== false, 'ABNORMAL/未知状态可重新查询微信真实状态');
failedRefundAssert(strpos($detailViewSource, "admin_url('refunds.failure.replace-closed')") !== false, 'CLOSED 页面提供独立生成替代退款入口');
failedRefundAssert(strpos($detailViewSource, '新单金额固定为') !== false, 'CLOSED 替代退款金额在页面明确锁定');
failedRefundAssert(strpos($resourceListSource, "admin_url('refunds.failures')") !== false, '退款管理页提供失败退款处理入口');
failedRefundAssert(strpos($resourceListSource, '失败退款处理') !== false, '失败退款入口文案对财务管理员可发现');
failedRefundAssert(strpos($resourceListSource, "admin_url('refunds.execution')") !== false, '新增失败入口不破坏双方协议待执行入口');
failedRefundAssert(strpos($resourceListSource, '$isClosedReplacement=') !== false, '退款管理识别 CLOSED 替代退款单');
failedRefundAssert(strpos($resourceListSource, '$isLockedRefund=!empty($row[\'cancel_request_id\'])') !== false, '关联取消申请的退款均锁定金额，覆盖双方协议与 CLOSED 替代退款');
failedRefundAssert(strpos($resourceListSource, '($row[\'status\']===\'approved\'||$isLockedRefund)?\'readonly\':\'\'') !== false, '已批准或金额锁定的退款输入保持只读');
failedRefundAssert(strpos($resourceListSource, '微信 CLOSED 替代退款沿用原取消/退款金额，财务确认阶段不可修改') !== false, 'CLOSED 替代退款在人工提交页明确金额不可修改');
failedRefundAssert(strpos($resourceListSource, 'withdrawals.transition') !== false, '通用财务视图更新后提现操作仍保留');
failedRefundAssert(strpos($resourceListSource, 'disputes.resolve') !== false, '通用财务视图更新后争议操作仍保留');
failedRefundAssert(strpos($resourceListSource, 'reviews.update') !== false, '通用财务视图更新后评价操作仍保留');

failedRefundAssert(strpos($schedulerSource, "if(\$status==='SUCCESS')") !== false, '统一退款调度器继续负责真实 SUCCESS 最终收口');
failedRefundAssert(strpos($schedulerSource, 'finalizeCancelledOrderRefunds') !== false, '取消订单退款最终状态继续复用原聚合收口器');

echo "\nV2.5.0 failed refund recovery regression: OK\n";

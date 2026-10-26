<?php

declare(strict_types=1);

use WorkerAdmin\Application\Order\OrderDispute;
use WorkerAdmin\Contracts\OrderDisputeRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Contracts/OrderDisputeRepository.php';
require_once $root . '/server/app/Domain/Order/OrderOperationException.php';
require_once $root . '/server/app/Application/Order/OrderDispute.php';

function disputeAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

final class FakeDisputeRepository implements OrderDisputeRepository
{
    public array $calls = [];

    public function startNegotiation(array $actor, string $orderNo, string $description, array $evidenceIds): array
    {
        $this->calls[] = ['start',$actor,$orderNo,$description,$evidenceIds];
        return ['id'=>'DSP-START','status'=>'negotiating','admin_escalated'=>false];
    }

    public function escalate(array $actor, string $disputeNo, string $note): array
    {
        $this->calls[] = ['escalate',$actor,$disputeNo,$note];
        return ['id'=>$disputeNo,'status'=>'open','admin_escalated'=>true];
    }

    public function resolveByAgreement(array $actor, string $disputeNo, string $note): array
    {
        $this->calls[] = ['resolve',$actor,$disputeNo,$note];
        return ['id'=>$disputeNo,'status'=>'resolved','admin_escalated'=>false];
    }

    public function resolveByAdmin(
        array $operator,
        string $orderNo,
        string $resolution,
        string $responsibility,
        int $refundCents = 0,
        int $runEmptyCents = 0
    ): array {
        $this->calls[] = ['admin_resolve',$operator,$orderNo,$resolution,$responsibility,$refundCents,$runEmptyCents];
        return [
            'id'=>'DSP-ADMIN','status'=>'resolved','order_id'=>88,
            'refund_cents'=>$refundCents,'run_empty_fee_cents'=>$runEmptyCents,'auto_refund_cents'=>0,
        ];
    }
}

$repo = new FakeDisputeRepository();
$app = new OrderDispute($repo);
$actor = ['user_id'=>10];

$started = $app->execute('disputes.create', $actor, [
    'order_id'=>'OD-1',
    'description'=>'完工后发现接缝需要重新处理',
    'evidence_media_ids'=>['M1','M1','M2'],
]);
disputeAssert(($started['data']['status'] ?? '') === 'negotiating', '提交异议先进入双方协商状态');
disputeAssert(($started['data']['admin_escalated'] ?? true) === false, '初次异议不直接标记平台介入');
disputeAssert(($repo->calls[0][4] ?? []) === ['M1','M2'], '争议证据去重后传入仓储');
disputeAssert(strpos((string)$started['message'], '先与师傅沟通') !== false, '初次异议提示先自行协商');

$escalated = $app->execute('disputes.escalate', $actor, ['dispute_id'=>'DSP-START','note'=>'双方两次沟通仍无法解决']);
disputeAssert(($escalated['data']['status'] ?? '') === 'open', '明确申请后才切换平台争议状态');
disputeAssert(($escalated['data']['admin_escalated'] ?? false) === true, '平台介入状态明确返回');

$resolved = $app->execute('disputes.resolve', $actor, ['dispute_id'=>'DSP-START','note'=>'师傅已返工完成']);
disputeAssert(($resolved['data']['status'] ?? '') === 'resolved', '双方可在平台介入前自行解决');
disputeAssert(strpos((string)$resolved['message'], '恢复验收流程') !== false, '自行解决后明确恢复验收');

$adminResolved = $app->execute('admin.dispute.resolve', ['user_id'=>77], [
    'order_id'=>'OD-ADMIN',
    'resolution'=>'双方记录核实完毕，当前争议结案',
    'responsibility'=>'shared',
]);
$adminCall = $repo->calls[count($repo->calls)-1];
disputeAssert(($adminResolved['data']['status'] ?? '') === 'resolved', '管理员普通零金额争议可复用领域用例结案');
disputeAssert(($adminCall[0] ?? '') === 'admin_resolve', '管理员结案调用专用仓储能力');
disputeAssert(($adminCall[1]['channel'] ?? '') === 'mini_admin' && ($adminCall[1]['user_id'] ?? 0) === 77, '管理员结案显式携带 mini_admin ID 域');
disputeAssert(($adminCall[2] ?? '') === 'OD-ADMIN' && ($adminCall[4] ?? '') === 'shared', '管理员结案传递订单与责任归属');
disputeAssert(($adminCall[5] ?? -1) === 0 && ($adminCall[6] ?? -1) === 0, '小程序管理员普通结案固定零金额');

$webResolved = $app->execute('admin.dispute.resolve', ['channel'=>'web_admin','admin_user_id'=>88], [
    'order_id'=>'OD-WEB',
    'resolution'=>'Web 后台核对双方证据后裁定退款与跑空费',
    'responsibility'=>'worker',
    'refund_amount_cents'=>1200,
    'run_empty_fee_cents'=>300,
]);
$webCall = $repo->calls[count($repo->calls)-1];
disputeAssert(($webResolved['data']['status'] ?? '') === 'resolved', 'Web 管理员复用同一争议 Application');
disputeAssert(($webCall[1]['channel'] ?? '') === 'web_admin' && ($webCall[1]['admin_user_id'] ?? 0) === 88, 'Web 争议裁定显式携带 admin_users.id 域');
disputeAssert(($webCall[5] ?? 0) === 1200 && ($webCall[6] ?? 0) === 300, 'Web 资金裁定以分为单位传入同一 Repository');

$miniMoneyBlocked = false;
try {
    $app->execute('admin.dispute.resolve', ['user_id'=>77], [
        'order_id'=>'OD-ADMIN','resolution'=>'小程序不能直接判资金','responsibility'=>'worker',
        'refund_amount_cents'=>100,'run_empty_fee_cents'=>0,
    ]);
} catch (OrderOperationException $exception) {
    $miniMoneyBlocked = $exception->apiCode() === 'DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED';
}
disputeAssert($miniMoneyBlocked, '小程序管理员不能通过直调接口执行资金争议裁定');

$unknownAdminBlocked = false;
try {
    $app->execute('admin.dispute.resolve', ['channel'=>'legacy_admin','user_id'=>77], [
        'order_id'=>'OD-ADMIN','resolution'=>'未知来源不能操作','responsibility'=>'shared',
    ]);
} catch (OrderOperationException $exception) {
    $unknownAdminBlocked = $exception->apiCode() === 'ADMIN_REQUIRED';
}
disputeAssert($unknownAdminBlocked, '争议裁定拒绝未知管理员来源');

try {
    $app->execute('disputes.create', $actor, ['order_id'=>'OD-1','description'=>'说明足够长','evidence_media_ids'=>[]]);
    disputeAssert(false, '无证据异议必须被拒绝');
} catch (OrderOperationException $exception) {
    disputeAssert($exception->apiCode() === 'DISPUTE_EVIDENCE_REQUIRED', '无证据异议返回稳定业务错误码');
}

$adminInvalid = false;
try {
    $app->execute('admin.dispute.resolve', ['user_id'=>77], ['order_id'=>'OD-ADMIN','resolution'=>'x','responsibility'=>'shared']);
} catch (OrderOperationException $exception) {
    $adminInvalid = $exception->apiCode() === 'RESOLUTION_REQUIRED';
}
disputeAssert($adminInvalid, '管理员争议结案说明必须满足最小长度');

$repositorySource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/DatabaseOrderDisputeRepository.php');
$legacySource = (string)file_get_contents($root . '/server/app/Http/Api/Modules/LegacyApiModule.php');
$kernelSource = (string)file_get_contents($root . '/server/app/Http/Api/ApiKernel.php');
$moduleSource = (string)file_get_contents($root . '/server/app/Http/Api/Modules/OrderDisputeApiModule.php');
$workbenchSource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php');
$schedulerSource = (string)file_get_contents($root . '/server/app/Services/SchedulerService.php');
$componentSource = (string)file_get_contents($root . '/miniprogram/components/order-dispute-panel/index.js');
$componentView = (string)file_get_contents($root . '/miniprogram/components/order-dispute-panel/index.wxml');
$pageView = (string)file_get_contents($root . '/miniprogram/pages/userOrder/detail.wxml');
$webModuleSource = (string)file_get_contents($root . '/server/app/Http/Admin/Modules/DisputeReviewAdminModule.php');
$webViewSource = (string)file_get_contents($root . '/server/views/admin/dispute-review.php');
$adminKernelSource = (string)file_get_contents($root . '/server/app/Http/Admin/AdminKernel.php');
$dashboardSource = (string)file_get_contents($root . '/server/views/admin/exception-dashboard.php');

$startPos = strpos($repositorySource, 'public function startNegotiation');
$escalatePos = strpos($repositorySource, 'public function escalate');
$adminQueuePos = strpos($repositorySource, 'OrderNotificationService::queueAdminTask');
disputeAssert($startPos !== false && $escalatePos !== false && $adminQueuePos !== false && $adminQueuePos > $escalatePos, '管理员待办只存在于平台升级路径');
disputeAssert(strpos(substr($repositorySource, $startPos, $escalatePos - $startPos), 'queueAdminTask') === false, '双方协商阶段不生成管理员待办');
disputeAssert(strpos($repositorySource, "dispute_status='negotiating'") !== false, '协商状态写入订单阻断自动验收');
disputeAssert(strpos($repositorySource, "'notify_recipients'=>['user','worker']") !== false, '平台介入/管理员结案事件通知双方');
disputeAssert(strpos($repositorySource, "'notify_recipients'=>['worker']") !== false, '双方自行解决只补充通知师傅');
disputeAssert(strpos($repositorySource, "\$channel === 'mini_admin'") !== false && strpos($repositorySource, "\$channel === 'web_admin'") !== false, '争议 Repository 显式区分 mini/Web 管理员 ID 域');
disputeAssert(strpos($repositorySource, "\$handledBy = null") !== false && strpos($repositorySource, "\$handledBy = \$operatorId") !== false, 'mini 不写 handled_by，Web 写真实 admin_users.id');
disputeAssert(strpos($repositorySource, "'operator_user_id'") !== false && strpos($repositorySource, "'operator_admin_id'") !== false, '争议事件按管理员来源保存带类型 ID');
disputeAssert(strpos($repositorySource, 'bookingFeeAllocations') !== false, 'Web 资金争议复用原预约费占用口径');
disputeAssert(strpos($repositorySource, '$autoRefundCents = max(0, $availableBookingFee - $allocatedAfterDecision)') !== false, '资金裁定自动退回未分配预约费避免资金悬空');
disputeAssert(strpos($repositorySource, "'run_empty_fee','credit'") !== false && strpos($repositorySource, "'dispute',:dispute_id") !== false, '跑空费继续写入争议余额台账');
disputeAssert(strpos($repositorySource, 'DatabaseOrderCancellationRiskRepository($db)') !== false, '资金争议终止履约前继续捕获取消风控事实');

disputeAssert(strpos($workbenchSource, "WHERE d.status IN ('open','processing')") !== false, '管理员工作台只接收已平台介入争议');
$workbenchDisputeQuery = strstr($workbenchSource, "FROM disputes d\n             JOIN orders o ON o.id=d.order_id");
disputeAssert($workbenchDisputeQuery !== false && strpos(substr($workbenchDisputeQuery, 0, 600), "'negotiating'") === false, '双方协商状态不进入管理员工作台待办');
disputeAssert(strpos($workbenchSource, '涉及金额处理，请到 Web 后台裁定') !== false && strpos($workbenchSource, "$needsWeb ? [] : ['dispute_resolve']") !== false, '金额争议在小程序异常投影中只提醒不提供结案动作');
disputeAssert(strpos($workbenchSource, "'refund_amount_cents'=>max(0, (int)\$row['refund_amount_cents'])") !== false, '争议任务提供结构化退款金额给 Web 页面');
disputeAssert(strpos($workbenchSource, "'run_empty_fee_cents'=>max(0, (int)\$row['run_empty_fee_cents'])") !== false, '争议任务提供结构化跑空费给 Web 页面');
disputeAssert(strpos($schedulerSource, "!in_array((string) \$order['dispute_status'], ['none', 'resolved'], true)") !== false, '统一自动任务把 negotiating 视为阻断状态');
disputeAssert(strpos($schedulerSource, 'runUnifiedMeasurementAutoAccept') !== false && strpos($schedulerSource, 'hasBlockingBusiness($db,$order)') !== false, '测量成果自动确认同样复用统一阻断判断');

foreach (['disputes.create','disputes.escalate','disputes.resolve','admin.dispute.resolve'] as $route) {
    disputeAssert(strpos($legacySource, "'{$route}'") !== false, $route.' 已从小程序 Legacy 回退排除');
}
disputeAssert(strpos($kernelSource, 'new OrderDisputeApiModule($db)') !== false, '争议模块已接入 ApiKernel');
disputeAssert(strpos($moduleSource, "'admin.dispute.resolve'") !== false, '管理员争议结案由 OrderDisputeApiModule 接管');
disputeAssert(strpos($moduleSource, 'requireWorkbenchAdmin') !== false, '管理员争议结案继续执行小程序管理员鉴权');

disputeAssert(strpos($webModuleSource, "['dispute-review','dispute-review.resolve','disputes.resolve']") !== false, 'Web 专项模块同时接管旧 disputes.resolve 路由');
disputeAssert(strpos($webModuleSource, 'disputeProjectionById') !== false, '旧 Web 数字争议 ID 通过统一读模型映射当前异常任务');
disputeAssert(strpos($webModuleSource, 'OrderApplicationContainer') !== false && strpos($webModuleSource, "->dispute()->execute(") !== false, 'Web 争议裁定复用统一 OrderDispute Application');
disputeAssert(strpos($webModuleSource, "['channel'=>'web_admin','admin_user_id'=>\$adminId]") !== false, 'Web 争议模块传递真实 admin_users.id 域');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    disputeAssert(strpos($webModuleSource, $sqlToken) === false, 'Web 争议 Http 模块不得包含 SQL: '.trim($sqlToken));
}
disputeAssert(strpos($webViewSource, '未分配且尚未占用的预约费会自动补入退款') !== false, 'Web 争议页明确资金自动补退规则');
disputeAssert(strpos($webViewSource, '只做责任和金额决策，不手工修改订单状态') !== false, 'Web 争议页不暴露内部状态机编辑');
$webModulePos = strpos($adminKernelSource, 'new DisputeReviewAdminModule(');
$legacyModulePos = strpos($adminKernelSource, 'new LegacyAdminModule(');
disputeAssert($webModulePos !== false && $legacyModulePos !== false && $webModulePos < $legacyModulePos, 'Web 争议模块在 Legacy 前接管旧路由');
disputeAssert(strpos($dashboardSource, "admin_url('dispute-review'") !== false, '异常工作台争议卡片一步进入专项裁定页');
disputeAssert(strpos($dashboardSource, "admin_url('disputes',['q'=>\$item['public_no']])") === false, '异常工作台不再跳到无法按 q 过滤的旧争议列表');

disputeAssert(strpos($componentSource, "OrderApi.request(route, 'POST', payload)") !== false, '争议组件统一调用模块化接口');
disputeAssert(strpos($componentView, '已协商解决') !== false && strpos($componentView, '申请平台介入') !== false, '争议组件只保留两个轻量后续动作');
disputeAssert(strpos($pageView, '<order-dispute-panel') !== false, '用户订单详情已挂载争议协商组件');

echo "\nV2.5.0 dispute negotiation regression: OK\n";
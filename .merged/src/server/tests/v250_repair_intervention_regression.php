<?php

declare(strict_types=1);

use WorkerAdmin\Application\Admin\AdminRepairResolution;
use WorkerAdmin\Application\Order\OrderRepairIntervention;
use WorkerAdmin\Contracts\AdminRepairResolutionRepository;
use WorkerAdmin\Contracts\OrderRepairInterventionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\RepairInterventionPolicy;
use WorkerAdmin\Services\MessageEventCatalog;

$root = dirname(__DIR__, 2);
require_once $root.'/server/app/Contracts/OrderRepairInterventionRepository.php';
require_once $root.'/server/app/Contracts/AdminRepairResolutionRepository.php';
require_once $root.'/server/app/Domain/Order/OrderOperationException.php';
require_once $root.'/server/app/Domain/Order/RepairInterventionPolicy.php';
require_once $root.'/server/app/Application/Order/OrderRepairIntervention.php';
require_once $root.'/server/app/Application/Admin/AdminRepairResolution.php';
require_once $root.'/server/app/Services/MessageEventCatalog.php';

function repairInterventionAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] '.$label."\n");
}

$now = new DateTimeImmutable('2026-10-07 12:00:00', new DateTimeZone('UTC'));
$waiting = RepairInterventionPolicy::evaluate('processing', null, '2026-10-05 13:00:01', 'UTC', $now);
repairInterventionAssert($waiting['eligible'] === false, '未满48小时不能申请平台介入');
repairInterventionAssert($waiting['reason'] === 'waiting_worker', '等待师傅阶段返回稳定原因');
repairInterventionAssert($waiting['available_at'] === '2026-10-07 13:00', '返回明确的48小时可介入时间');

$timeout = RepairInterventionPolicy::evaluate('processing', null, '2026-10-05 12:00:00', 'UTC', $now);
repairInterventionAssert($timeout['eligible'] === true, '恰满48小时允许平台介入');
repairInterventionAssert($timeout['reason'] === 'response_timeout', '48小时超时返回稳定原因');

$rejected = RepairInterventionPolicy::evaluate('processing', '师傅拒绝维修：不属于保障范围', '2026-10-07 11:30:00', 'UTC', $now);
repairInterventionAssert($rejected['eligible'] === true, '师傅明确拒绝无需等待48小时');
repairInterventionAssert($rejected['reason'] === 'worker_rejected', '师傅拒绝返回稳定原因');

$already = RepairInterventionPolicy::evaluate('processing', '用户申请平台介入：协商未果', '2026-10-01 00:00:00', 'UTC', $now);
repairInterventionAssert($already['eligible'] === false && $already['reason'] === 'already_escalated', '已介入不能重复申请');

final class FakeRepairInterventionRepository implements OrderRepairInterventionRepository
{
    public array $calls = [];

    public function request(array $actor, string $orderNo, string $afterSaleNo, string $note): array
    {
        $this->calls[] = [$actor,$orderNo,$afterSaleNo,$note];
        return ['after_sale_id'=>$afterSaleNo,'status'=>'processing','intervention_reason'=>'worker_rejected'];
    }
}

final class FakeAdminRepairResolutionRepository implements AdminRepairResolutionRepository
{
    public array $calls = [];

    public function resolve(array $operator, string $orderNo, string $afterSaleNo, string $decision, string $note): array
    {
        $this->calls[] = [$operator,$orderNo,$afterSaleNo,$decision,$note];
        return [
            'order_id'=>$orderNo,
            'after_sale_id'=>$afterSaleNo,
            'status'=>$decision === 'continue' ? 'processing' : $decision,
            'decision'=>$decision,
        ];
    }
}

$repo = new FakeRepairInterventionRepository();
$app = new OrderRepairIntervention($repo);
$result = $app->request(['user_id'=>10], ['order_id'=>'OD-1','after_sale_id'=>'AS-1','note'=>'师傅已明确拒绝']);
repairInterventionAssert(($result['data']['status'] ?? '') === 'processing', '应用层返回平台介入状态');
repairInterventionAssert(($repo->calls[0][1] ?? '') === 'OD-1' && ($repo->calls[0][2] ?? '') === 'AS-1', '应用层绑定订单与售后编号');

try {
    $app->request(['user_id'=>10], ['order_id'=>'OD-1','after_sale_id'=>'','note'=>'']);
    repairInterventionAssert(false, '缺少售后编号必须拒绝');
} catch (OrderOperationException $exception) {
    repairInterventionAssert($exception->apiCode() === 'AFTER_SALE_ID_REQUIRED', '缺售后编号返回稳定错误码');
}

$adminRepo = new FakeAdminRepairResolutionRepository();
$adminApp = new AdminRepairResolution($adminRepo);
$continued = $adminApp->execute(['channel'=>'mini_admin','user_id'=>901], [
    'order_id'=>'OD-1','after_sale_id'=>'AS-1','decision'=>'continue','note'=>'根据双方材料，要求师傅继续完成本次保修维修',
]);
repairInterventionAssert(($continued['data']['status'] ?? '') === 'processing', '平台可要求继续维修而不提前结案');
repairInterventionAssert(($adminRepo->calls[0][0]['channel'] ?? '') === 'mini_admin', '平台裁定显式携带 mini_admin ID 域');
repairInterventionAssert(($adminRepo->calls[0][3] ?? '') === 'continue', '平台继续维修动作传入仓储');

$resolved = $adminApp->execute(['channel'=>'mini_admin','user_id'=>901], [
    'order_id'=>'OD-1','after_sale_id'=>'AS-1','decision'=>'resolved','note'=>'用户确认维修结果符合约定，本次售后可以结案',
]);
repairInterventionAssert(($resolved['data']['status'] ?? '') === 'resolved', '平台可独立确认售后已解决');

$webResolved = $adminApp->execute(['channel'=>'web_admin','user_id'=>77], [
    'order_id'=>'OD-2','after_sale_id'=>'AS-2','decision'=>'resolved','note'=>'Web 后台核对双方材料后确认维修问题已经解决',
]);
repairInterventionAssert(($webResolved['data']['status'] ?? '') === 'resolved', 'Web 管理员复用同一平台售后裁定用例');
repairInterventionAssert(($adminRepo->calls[2][0]['channel'] ?? '') === 'web_admin', 'Web 平台裁定显式携带 web_admin ID 域');
repairInterventionAssert(($adminRepo->calls[2][0]['user_id'] ?? 0) === 77, 'Web 平台裁定保留真实 admin_users.id');

$badChannel = false;
try {
    $adminApp->execute(['channel'=>'legacy_admin','user_id'=>1], [
        'order_id'=>'OD-1','after_sale_id'=>'AS-1','decision'=>'resolved','note'=>'旧通道不得绕过显式管理员身份域',
    ]);
} catch (OrderOperationException $exception) {
    $badChannel = $exception->apiCode() === 'ADMIN_REQUIRED';
}
repairInterventionAssert($badChannel, '平台售后裁定只允许 mini_admin 或 web_admin 两种显式通道');

$badDecision = false;
try {
    $adminApp->execute(['channel'=>'mini_admin','user_id'=>901], [
        'order_id'=>'OD-1','after_sale_id'=>'AS-1','decision'=>'refund','note'=>'尝试在维修裁定里处理资金',
    ]);
} catch (OrderOperationException $exception) {
    $badDecision = $exception->apiCode() === 'REPAIR_PLATFORM_DECISION_INVALID';
}
repairInterventionAssert($badDecision, '维修平台裁定禁止混入退款等资金动作');

$shortNote = false;
try {
    $adminApp->execute(['channel'=>'mini_admin','user_id'=>901], [
        'order_id'=>'OD-1','after_sale_id'=>'AS-1','decision'=>'closed','note'=>'关闭',
    ]);
} catch (OrderOperationException $exception) {
    $shortNote = $exception->apiCode() === 'REPAIR_PLATFORM_NOTE_REQUIRED';
}
repairInterventionAssert($shortNote, '平台最终关闭售后必须填写明确处理说明');

$continueMeta = MessageEventCatalog::get('repair_platform_continue');
$resolvedMeta = MessageEventCatalog::get('repair_platform_resolved');
$closedMeta = MessageEventCatalog::get('repair_platform_closed');
repairInterventionAssert(is_array($continueMeta) && ($continueMeta['status'] ?? '') === '平台要求继续维修', '平台继续维修已注册统一消息事件');
repairInterventionAssert(($continueMeta['recipients'] ?? []) === ['user','worker'], '平台继续维修同时通知用户和师傅');
repairInterventionAssert(($continueMeta['subscription_scene'] ?? '') === 'repair_progress', '平台继续维修复用维修进度订阅场景');
repairInterventionAssert(($continueMeta['priority'] ?? '') === 'important', '平台要求继续维修属于重要进度');
repairInterventionAssert(!empty($continueMeta['inapp_visible']), '平台继续维修默认进入站内通知目录');
repairInterventionAssert(is_array($resolvedMeta) && ($resolvedMeta['priority'] ?? '') === 'normal', '平台确认解决按普通售后结果展示');
repairInterventionAssert(($resolvedMeta['recipients'] ?? []) === ['user','worker'], '平台确认解决同时通知双方');
repairInterventionAssert(is_array($closedMeta) && ($closedMeta['subscription_scene'] ?? '') === 'repair_progress', '平台关闭售后复用维修进度订阅场景');

$repositorySource = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseOrderRepairInterventionRepository.php');
$adminRepositorySource = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/DatabaseAdminRepairResolutionRepository.php');
$actionModuleSource = (string)file_get_contents($root.'/server/app/Http/Api/Modules/AdminWorkbenchActionApiModule.php');
$webAdminModuleSource = (string)file_get_contents($root.'/server/app/Http/Admin/Modules/RepairInterventionAdminModule.php');
$webViewSource = (string)file_get_contents($root.'/server/views/admin/repair-intervention.php');
$adminKernelSource = (string)file_get_contents($root.'/server/app/Http/Admin/AdminKernel.php');
$workbenchViewSource = (string)file_get_contents($root.'/server/views/admin/exception-dashboard.php');
$workbenchSource = (string)file_get_contents($root.'/server/app/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php');
$contentGateSource = (string)file_get_contents($root.'/server/app/Infrastructure/External/WeChatRequestContentSecurityGate.php');
$kernelSource = (string)file_get_contents($root.'/server/app/Http/Api/ApiKernel.php');
$legacySource = (string)file_get_contents($root.'/server/app/Http/Api/Modules/LegacyApiModule.php');
$reminderSource = (string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/RepairEscalationReminderTask.php');
$detailSource = (string)file_get_contents($root.'/server/app/Application/Order/OrderDetailQuery.php');
$adminDetailJs = (string)file_get_contents($root.'/miniprogram/pages/adminWorkbench/detail.js');

repairInterventionAssert(strpos($kernelSource, 'new OrderRepairInterventionApiModule($db)') !== false, '售后平台介入模块已接入 ApiKernel');
repairInterventionAssert(strpos($legacySource, "'after_sales.repair.escalate'") !== false, '售后介入路由已从 Legacy 回退排除');
repairInterventionAssert(strpos($legacySource, "'admin.after_sales.repair.resolve'") !== false, '平台维修裁定路由已从 Legacy 回退排除');
repairInterventionAssert(strpos($repositorySource, 'RepairInterventionPolicy::evaluate') !== false, '写接口复用统一48小时资格策略');
repairInterventionAssert(strpos($detailSource, 'RepairInterventionPolicy::evaluate') !== false, '详情按钮复用统一48小时资格策略');
repairInterventionAssert(strpos($repositorySource, "'notify_recipients'=>['worker']") !== false, '平台介入订单事件只提醒师傅');
repairInterventionAssert(substr_count($repositorySource, 'queueAdminTask(') === 1, '平台介入只生成一条管理员待办');
repairInterventionAssert(strpos($reminderSource, 'INTERVAL 48 HOUR') !== false, '48小时提醒任务与介入策略保持一致');

repairInterventionAssert(strpos($actionModuleSource, "'admin.after_sales.repair.resolve'") !== false, '小程序异常工作台写模块接管平台维修裁定');
repairInterventionAssert(strpos($contentGateSource, "'admin.after_sales.repair.resolve'=>2") !== false, '小程序平台维修裁定说明经过请求内容安全');
repairInterventionAssert(strpos($adminRepositorySource, "['mini_admin','web_admin']") !== false, 'Repository 只接受两种显式管理员 ID 域');
repairInterventionAssert(strpos($adminRepositorySource, 'handled_by=IF(:handled_by>0,:handled_by,handled_by)') !== false, 'Web admin_users.id 可合法记录到 after_sales.handled_by');
repairInterventionAssert(strpos($adminRepositorySource, '$channel === \'web_admin\' ? \'admin\' : \'mini_admin\'') !== false, '订单事件按管理员通道写入正确 actor_type');
repairInterventionAssert(strpos($adminRepositorySource, '\'operator_admin_id\'=>$channel === \'web_admin\' ? $operatorUserId : null') !== false, 'Web 管理员 ID 单独写入审计 payload');
repairInterventionAssert(strpos($adminRepositorySource, 'refunds') === false && strpos($adminRepositorySource, 'worker_balance') === false, '维修裁定 Repository 不混入退款或师傅资金处理');
repairInterventionAssert(strpos($workbenchSource, "['repair_platform_continue','repair_platform_resolve','repair_platform_close']") !== false, '首次平台介入下发三种明确裁定动作');
repairInterventionAssert(strpos($workbenchSource, "['repair_platform_resolve','repair_platform_close']") !== false, '要求继续维修后不再重复下发 continue 按钮');
repairInterventionAssert(strpos($adminDetailJs, "route = 'admin.after_sales.repair.resolve'") !== false, '小程序异常详情已接入平台维修裁定接口');
repairInterventionAssert(strpos($adminDetailJs, '资金事项仍需走退款/争议流程') !== false, '小程序前端明确售后裁定与资金处理分离');

repairInterventionAssert(strpos($webAdminModuleSource, 'AdminWorkbenchReadComposition') !== false, 'Web 售后介入页复用统一异常读模型确认任务');
repairInterventionAssert(strpos($webAdminModuleSource, 'AdminWorkbenchActionComposition') !== false, 'Web 售后介入页复用统一平台售后写用例');
repairInterventionAssert(strpos($webAdminModuleSource, '[\'channel\'=>\'web_admin\',\'user_id\'=>$adminId]') !== false, 'Web 模块显式传递 web_admin ID 域');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    repairInterventionAssert(strpos($webAdminModuleSource, $sqlToken) === false, 'Web 售后介入模块不得包含 SQL: '.trim($sqlToken));
}
repairInterventionAssert(strpos($webViewSource, 'is_array($task[\'allowed_actions\'] ?? null)') !== false, 'Web 页面只渲染统一异常投影允许的售后动作');
repairInterventionAssert(strpos($webViewSource, '退款、赔付、跑空费、师傅余额等资金事项必须继续使用退款/争议/财务核账流程') !== false, 'Web 页面明确售后裁定与资金处理分离');
$repairModulePos = strpos($adminKernelSource, 'new RepairInterventionAdminModule(');
$legacyModulePos = strpos($adminKernelSource, 'new LegacyAdminModule(');
repairInterventionAssert($repairModulePos !== false && $legacyModulePos !== false && $repairModulePos < $legacyModulePos, 'Web 售后介入模块在 Legacy 前接管路由');
repairInterventionAssert(strpos($workbenchViewSource, '$taskType === \'after_sale_intervention\'') !== false, 'Web 异常工作台为售后平台介入提供专项处理入口');
repairInterventionAssert(strpos($workbenchViewSource, "admin_url('repair-intervention'") !== false, 'Web 异常卡片直接进入售后平台介入页');

echo "\nV2.5.0 repair intervention regression: OK\n";

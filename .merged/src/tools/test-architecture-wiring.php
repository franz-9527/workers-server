<?php

declare(strict_types=1);

// 组合根 smoke 不加载完整 bootstrap；为仍处于 Legacy Adapter 后方的旧服务提供只读默认配置桩。
if (!function_exists('setting')) {
    function setting($key, $default = null) { return $default; }
}

$base = dirname(__DIR__) . '/server/app';
spl_autoload_register(static function (string $class) use ($base): void {
    $prefix = 'WorkerAdmin\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $file = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

use WorkerAdmin\Application\Identity\OcrResultRecorder;
use WorkerAdmin\Application\Messaging\ChannelDispatcher;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Application\Scheduling\SchedulerRunner;
use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Contracts\ContentModerationGateway;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Http\Api\Modules\AdminOrderProxyApiModule;
use WorkerAdmin\Http\Api\Modules\AdminWorkbenchActionApiModule;
use WorkerAdmin\Http\Api\Modules\AdminWorkbenchApiModule;
use WorkerAdmin\Http\Api\Modules\LegacyApiModule;
use WorkerAdmin\Http\Api\Modules\OrderCancellationApiModule;
use WorkerAdmin\Http\Api\Modules\OrderDisputeApiModule;
use WorkerAdmin\Http\Api\Modules\OrderMeasurementHoldExtensionApiModule;
use WorkerAdmin\Services\UserWalletService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$db = new Database(['database'=>'architecture_smoke','username'=>'none','password'=>'none']);
$container = new ApplicationContainer($db);
$assert($container->wallet() instanceof UserWalletManager, 'ApplicationContainer wallet wiring failed');
$assert($container->ocrResults() instanceof OcrResultRecorder, 'ApplicationContainer OCR wiring failed');
$assert($container->scheduler(20) instanceof SchedulerRunner, 'ApplicationContainer scheduler wiring failed');
$assert($container->paymentGateway() instanceof PaymentGateway, 'payment gateway wiring failed');
$assert($container->smsGateway() instanceof SmsGateway, 'sms gateway wiring failed');
$assert($container->objectStorage() instanceof ObjectStorage, 'object storage wiring failed');
$assert($container->subscribeMessages() instanceof SubscribeMessageGateway, 'subscribe message wiring failed');
$assert($container->adminAlerts() instanceof AdminAlertGateway, 'admin alert wiring failed');
$assert($container->contentModeration() instanceof ContentModerationGateway, 'content moderation wiring failed');
$assert($container->messaging() instanceof ChannelDispatcher, 'messaging application dispatcher wiring failed');

// 对象存储端口不得再次吸收 header/echo 之类 HTTP 响应职责。
$storageContract = new ReflectionClass(ObjectStorage::class);
$assert(!$storageContract->hasMethod('output'), 'ObjectStorage contract must remain transport neutral');

$walletFacade = new ReflectionClass(UserWalletService::class);
$requiredMethods = [
    'summary','ledger','ensureWallet','lockWallet','applyLocked',
    'consumeReservationLocked','consumeReservation','releaseReservationLocked','releaseReservation',
    'createRechargeRefundHoldLocked','consumeRechargeRefundHoldLocked','releaseRechargeRefundHoldLocked',
];
foreach ($requiredMethods as $method) {
    $assert($walletFacade->hasMethod($method), 'UserWalletService compatibility method missing: ' . $method);
    $assert($walletFacade->getMethod($method)->isPublic(), 'UserWalletService compatibility method not public: ' . $method);
}

foreach (['lockWallet','applyLocked','consumeReservationLocked','releaseReservationLocked','createRechargeRefundHoldLocked','consumeRechargeRefundHoldLocked','releaseRechargeRefundHoldLocked'] as $method) {
    $parameters = $walletFacade->getMethod($method)->getParameters();
    $type = $parameters[0]->getType();
    $assert($type !== null && (string) $type === Database::class, '锁内钱包方法必须继续接收同一个 Database: ' . $method);
}

// V2.5.0 管理员订单工作台：正常运营待办与异常待办并存，异常仍保持独立可筛选。
$normalTasks = ['audit_pending','assignment_required','platform_pricing_review'];
foreach ($normalTasks as $type) {
    $assert(!WorkbenchTaskProjection::isException(['task_type'=>$type]), '正常任务不应被标记为异常: ' . $type);
    $assert(WorkbenchTaskProjection::matchesScope(['task_type'=>$type], 'todo'), '正常任务必须进入全部待处理: ' . $type);
    $assert(WorkbenchTaskProjection::matchesScope(['task_type'=>$type], 'operation'), '正常任务必须进入正常运营范围: ' . $type);
}
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'audit_pending'], 'audit'), '普通审核必须进入 audit scope');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'assignment_required'], 'assignment'), '待安排师傅必须进入 assignment scope');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_pricing_review'], 'pricing'), '平台测量核价必须进入 pricing scope');

$exceptionTasks = [
    'platform_auto_publish_failed',
    'refund_review',
    'dispute_open',
    'platform_finance_risk',
    'platform_income_verification',
    'contact_reveal_review',
    'after_sale_intervention',
];
foreach ($exceptionTasks as $type) {
    $assert(WorkbenchTaskProjection::isException(['task_type'=>$type]), '异常任务应进入异常范围: ' . $type);
}
$projected = WorkbenchTaskProjection::exceptionTasks([
    ['task_type'=>'audit_pending'],
    ['task_type'=>'platform_auto_publish_failed'],
    ['task_type'=>'platform_pricing_review'],
    ['task_type'=>'contact_reveal_review'],
]);
$assert(count($projected) === 2, '异常子投影必须排除普通审核和正常核价');
$assert(($projected[0]['task_type'] ?? '') === 'platform_auto_publish_failed', '异常投影必须保留自动发布失败');
$assert(($projected[1]['task_type'] ?? '') === 'contact_reveal_review', '异常投影必须保留电话公开申请');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_auto_publish_failed'], 'publish'), '自动发布失败必须进入 publish scope');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_finance_risk'], 'finance'), '资金风险必须进入 finance scope');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'platform_income_verification'], 'income_verification'), '线下收入核实必须进入 income_verification scope');
$assert(WorkbenchTaskProjection::matchesScope(['task_type'=>'contact_reveal_review'], 'privacy'), '电话公开申请必须进入 privacy scope');
$summary = WorkbenchTaskProjection::summary([
    101=>['tasks'=>[
        ['task_type'=>'platform_auto_publish_failed','mode'=>'attention'],
        ['task_type'=>'platform_finance_risk','mode'=>'attention'],
    ]],
    102=>['tasks'=>[
        ['task_type'=>'audit_pending','mode'=>'decision'],
    ]],
]);
$assert($summary['todo_orders'] === 2 && $summary['exception_orders'] === 1 && $summary['operation_orders'] === 1, '待处理统计必须同时覆盖正常和异常订单');
$assert($summary['attention_orders'] === 1, '同一订单多个 attention 任务只统计一笔关注订单');
$assert($summary['auto_publish_orders'] === 1 && $summary['finance_orders'] === 1 && $summary['audit_orders'] === 1, '工作台分类统计必须按订单命中');

// 正式读链：Http -> Application Query -> Contract -> Infrastructure Repository -> Sources。
$builderSource = (string)file_get_contents($base . '/Services/AdminWorkbenchTaskBuilder.php');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    $assert(strpos($builderSource, $sqlToken) === false, '旧 AdminWorkbenchTaskBuilder 兼容门面不得包含 SQL: ' . trim($sqlToken));
}
$assert(strpos($builderSource, 'DatabaseAdminWorkbenchTaskRepository') !== false, '旧 Builder 必须委托统一只读 Repository');

$orderListReaderSource = (string)file_get_contents($base . '/Infrastructure/Persistence/DatabaseAdminWorkbenchOrderListReader.php');
$readRepositorySource = (string)file_get_contents($base . '/Infrastructure/Persistence/DatabaseAdminWorkbenchTaskRepository.php');
foreach (['OperationalOrderWorkbenchTaskSource','PlatformOrderWorkbenchTaskSource','OrderExceptionWorkbenchTaskSource'] as $sourceClass) {
    $assert(strpos($readRepositorySource, $sourceClass) !== false, '统一只读 Repository 必须组合 Source: ' . $sourceClass);
}
$assert(strpos($readRepositorySource, 'function allOrders(') !== false, '全部订单必须拥有独立分页读模型');
$assert(strpos($orderListReaderSource, "return \$this->result(\$items, 'all',") !== false && strpos($readRepositorySource, "return \$this->lists->allOrders(") !== false, '全部订单读模型必须显式返回 all scope');
$exceptionSource = (string)file_get_contents($base . '/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php');
$assert(strpos($exceptionSource, 'order_cancel_requests') !== false, '退款异常事实应位于 Infrastructure Source');
$assert(strpos($exceptionSource, "d.status IN ('open','processing')") !== false, '争议异常事实应位于 Infrastructure Source');
$assert(strpos($exceptionSource, 'order_worker_contact_reveal_requests') !== false, '隐私异常事实应位于 Infrastructure Source');
$assert(strpos($exceptionSource, 'order_measurement_hold_extensions') !== false, '测量延期异常事实应位于 Infrastructure Source');
$assert(strpos($exceptionSource, 'after_sale_intervention') !== false, '平台介入售后事实应位于 Infrastructure Source');

$querySource = (string)file_get_contents($base . '/Application/Admin/AdminWorkbenchQuery.php');
$assert(strpos($querySource, 'AdminWorkbenchTaskRepository') !== false, 'Application Query 必须只依赖工作台 Contract');
$assert(strpos($querySource, 'WorkerAdmin\\Infrastructure\\') === false, 'Application Query 禁止直接依赖 Infrastructure');
$assert(strpos($querySource, 'WorkerAdmin\\Services\\') === false, 'Application Query 禁止回退旧 Services');
$assert(strpos($querySource, 'function allOrders(') !== false, 'Application Query 必须暴露全部订单查询');

$readModuleSource = (string)file_get_contents($base . '/Http/Api/Modules/AdminWorkbenchApiModule.php');
$assert(strpos($readModuleSource, 'AdminWorkbenchQuery') !== false, 'Http 工作台读模块必须通过 Application Query');
$assert(strpos($readModuleSource, 'AdminWorkbenchReadComposition') !== false, 'Http 工作台读模块必须由 Foundation 组合正式读链');
$assert(strpos($readModuleSource, 'WorkerAdmin\\Services\\') === false, 'Http 工作台读模块禁止依赖旧 Services');
$assert(strpos($readModuleSource, "'todo','all','operation','exception','audit','assignment','pricing'") !== false, '小程序工作台必须暴露正常待办、全部订单和异常主范围');
$assert(strpos($readModuleSource, "\$scope === 'all'") !== false && strpos($readModuleSource, 'allOrders(') !== false, '全部订单 scope 必须走独立分页读模型');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    $assert(strpos($readModuleSource, $sqlToken) === false, 'Http 工作台读模块不得包含 SQL: ' . trim($sqlToken));
}

// 工作台三条读路由完整迁出 Legacy。admin.orders 无条件由新模块接管，scope 只在 dispatch 内校验。
$adminWorkbenchModule = new AdminWorkbenchApiModule($db);
$legacyModule = new LegacyApiModule($db);
foreach (['admin.dashboard','admin.orders','admin.workbench.order'] as $route) {
    unset($_GET['scope']);
    $assert($adminWorkbenchModule->supports($route), 'AdminWorkbenchApiModule 必须完整接管: ' . $route);
    $assert(!$legacyModule->supports($route), '工作台读路由不得回退 LegacyApiModule: ' . $route);
}
foreach (['todo','all','operation','exception','audit','assignment','pricing','refund_after_sale','settlement','publish','refund_dispute','finance','income_verification','privacy','measurement','after_sale','attention'] as $scope) {
    $_GET['scope'] = $scope;
    $assert($adminWorkbenchModule->supports('admin.orders'), 'admin.orders 必须始终由新模块接管，scope=' . $scope);
}
unset($_GET['scope']);

// 普通下单、历史商品与管理员代录统一路由归属，不能落回 Legacy。
$submissionModule = new \WorkerAdmin\Http\Api\Modules\OrderSubmissionApiModule($db);
foreach (['forms.order.products','admin.orders.proxy.form','admin.orders.proxy.customers','admin.orders.proxy.create','admin.orders.proxy.ready'] as $route) {
    $assert($submissionModule->supports($route), 'OrderSubmissionApiModule 必须接管: ' . $route);
    $assert(!$legacyModule->supports($route), '代录/商品路由不得回退 Legacy: ' . $route);
}
$assert($submissionModule->requiresContentSecurity('admin.orders.proxy.create'), '管理员代录必须执行文本内容安全检查');

// 管理员异常写动作继续由模块化领域入口接管，Legacy 不得再成为隐式回退路径。
$actionModule = new AdminWorkbenchActionApiModule($db);
$measurementModule = new OrderMeasurementHoldExtensionApiModule($db);
$disputeModule = new OrderDisputeApiModule($db);
$cancellationModule = new OrderCancellationApiModule($db);
$adminActionRoutes = [
    'admin.order.contact-reveal.review'=>$actionModule,
    'admin.after_sales.repair.resolve'=>$actionModule,
    'admin.measurement.hold.extend.review'=>$measurementModule,
    'admin.dispute.resolve'=>$disputeModule,
    'admin.refund.review'=>$cancellationModule,
];
foreach ($adminActionRoutes as $route=>$module) {
    $assert($module->supports($route), '模块化异常动作必须接管: ' . $route);
    $assert($module->requiresContentSecurity($route), '异常动作备注必须继续经过内容安全入口: ' . $route);
    $assert(!$legacyModule->supports($route), '已迁移异常动作不得回退 LegacyApiModule: ' . $route);
}

// 旧代理入口只返回明确拒绝；事实型协助走独立 Application 用例。
$proxyModule = new AdminOrderProxyApiModule($db);
$assert($proxyModule->supports('admin.proxy.action'), 'AdminOrderProxyApiModule 必须完整接管 admin.proxy.action');
$assert($proxyModule->requiresContentSecurity('admin.proxy.action'), 'admin.proxy.action 备注必须经过内容安全入口');
$assert(!$legacyModule->supports('admin.proxy.action'), 'admin.proxy.action 不得回退 LegacyApiModule');
$proxyApplicationSource = (string)file_get_contents($base . '/Application/Admin/AdminOrderProxyAction.php');
$proxyRepositorySource = (string)file_get_contents($base . '/Infrastructure/Persistence/DatabaseAdminOrderProxyActionRepository.php');
$assert(strpos($proxyApplicationSource, 'ADMIN_REPAIR_PROXY_REMOVED') !== false, '管理员维修代理完成必须在 Application 永久禁用');
$assert(strpos($proxyRepositorySource, 'after_sales') === false, '管理员代理 Repository 不得重新写售后表');
$assert(strpos($proxyRepositorySource, 'ADMIN_ASSIGNMENT_PROXY_REMOVED') !== false, '旧代理仓储也永久拒绝代确认接单');
$assert(strpos($proxyRepositorySource, 'UPDATE assignments') === false, '旧代理仓储不得残留推进接单状态的 SQL');
foreach (['admin.order.assistance.context','admin.order.assistance.save','admin.order.assistance.media.upload'] as $route) {
    $assert($proxyModule->supports($route), '管理员事实补录必须由模块化入口承接: '.$route);
    $assert(!$legacyModule->supports($route), '管理员事实补录不得回退旧入口: '.$route);
}
$assert($proxyModule->requiresContentSecurity('admin.order.assistance.save'), '事实补录说明继续经过内容安全门禁');
$assert($proxyModule->requiresContentSecurity('admin.order.assistance.media.upload'), '管理员代图继续经过上传内容安全门禁');
$assert(!$proxyModule->requiresContentSecurity('admin.order.assistance.context'), '事实补录 GET 不执行提交内容检测');

echo "架构组合根、管理员订单工作台投影与兼容门面回归通过。\n";

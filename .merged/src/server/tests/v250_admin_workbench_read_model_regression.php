<?php

declare(strict_types=1);

use WorkerAdmin\Application\Admin\AdminWorkbenchQuery;
use WorkerAdmin\Contracts\AdminWorkbenchTaskRepository;

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Contracts/AdminWorkbenchTaskRepository.php';
require_once $root . '/server/app/Application/Admin/AdminWorkbenchQuery.php';

function workbenchReadAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $label . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

final class FakeAdminWorkbenchTaskRepository implements AdminWorkbenchTaskRepository
{
    public array $calls = [];

    public function summary(): array
    {
        $this->calls[] = ['summary'];
        return ['todo_orders'=>3,'operation_orders'=>1,'exception_orders'=>2,'all_orders'=>9];
    }

    public function groupsForScope(string $scope): array
    {
        $this->calls[] = ['groupsForScope',$scope];
        return [11=>['order_id'=>11,'tasks'=>[['task_type'=>'refund_review']]]];
    }

    public function tasksForOrder(int $orderId): array
    {
        $this->calls[] = ['tasksForOrder',$orderId];
        return [['task_type'=>'audit_pending']];
    }

    public function exceptionTasksForOrder(int $orderId): array
    {
        $this->calls[] = ['exceptionTasksForOrder',$orderId];
        return [['task_type'=>'refund_review']];
    }

    public function orderProjection(string $publicNo): ?array
    {
        $this->calls[] = ['orderProjection',$publicNo];
        if ($publicNo === 'MISSING') return null;
        return [
            'order_id'=>$publicNo,
            'order_version'=>7,
            'tasks'=>[['task_type'=>'audit_pending'],['task_type'=>'refund_review']],
            'operation_tasks'=>[['task_type'=>'audit_pending']],
            'exception_tasks'=>[['task_type'=>'refund_review']],
            'task_count'=>2,
            'operation_count'=>1,
            'exception_count'=>1,
        ];
    }

    public function orderProjectionById(int $orderId): ?array
    {
        $this->calls[] = ['orderProjectionById',$orderId];
        if ($orderId < 1) return null;
        return [
            'order_id'=>'OD-INTERNAL-'.$orderId,
            'order_version'=>8,
            'tasks'=>[['task_type'=>'refund_review','source_id'=>'CR-INTERNAL-'.$orderId]],
            'operation_tasks'=>[],
            'exception_tasks'=>[['task_type'=>'refund_review','source_id'=>'CR-INTERNAL-'.$orderId]],
            'task_count'=>1,
            'operation_count'=>0,
            'exception_count'=>1,
        ];
    }

    public function disputeProjectionById(int $disputeId): ?array
    {
        $this->calls[] = ['disputeProjectionById',$disputeId];
        if ($disputeId < 1) return null;
        return [
            'order_id'=>'OD-DISPUTE-'.$disputeId,
            'order_version'=>9,
            'dispute_internal_id'=>$disputeId,
            'dispute_id'=>'DSP-INTERNAL-'.$disputeId,
            'task'=>['task_type'=>'dispute_open','source_id'=>'DSP-INTERNAL-'.$disputeId],
        ];
    }

    public function contactRevealProjectionById(int $requestId): ?array
    {
        $this->calls[] = ['contactRevealProjectionById',$requestId];
        if ($requestId < 1) return null;
        return [
            'order_id'=>'OD-CONTACT-'.$requestId,
            'order_version'=>10,
            'request_internal_id'=>$requestId,
            'request_id'=>'CONTACT-INTERNAL-'.$requestId,
            'task'=>['task_type'=>'contact_reveal_review','source_id'=>'CONTACT-INTERNAL-'.$requestId],
        ];
    }

    public function list(string $scope, int $page, int $pageSize, string $keyword): array
    {
        $this->calls[] = ['list',$scope,$page,$pageSize,$keyword];
        return [
            'items'=>[['public_no'=>'OD-READ-1']],
            'scope'=>$scope,
            'search'=>$keyword,
            'pagination'=>[
                'page'=>$page,
                'per_page'=>$pageSize,
                'total'=>1,
                'total_pages'=>1,
                'has_more'=>false,
            ],
        ];
    }

    public function allOrders(int $page, int $pageSize, string $keyword): array
    {
        $this->calls[] = ['allOrders',$page,$pageSize,$keyword];
        return [
            'items'=>[[
                'public_no'=>'OD-ALL-1',
                'task_count'=>2,
                'operation_count'=>1,
                'exception_count'=>1,
                'task_overflow_count'=>0,
            ]],
            'scope'=>'all',
            'search'=>$keyword,
            'pagination'=>[
                'page'=>$page,
                'per_page'=>$pageSize,
                'total'=>1,
                'total_pages'=>1,
                'has_more'=>false,
            ],
        ];
    }
}

$repo = new FakeAdminWorkbenchTaskRepository();
$query = new AdminWorkbenchQuery($repo);

$summary = $query->summary();
workbenchReadAssert(($summary['todo_orders'] ?? 0) === 3 && ($summary['exception_orders'] ?? 0) === 2, 'Application Query 汇总结果完全来自 Contract');

$projection = $query->orderProjection('OD-READ-1');
workbenchReadAssert(($projection['order_id'] ?? '') === 'OD-READ-1', '单订单完整工作台投影由 Query 透传');
workbenchReadAssert(($projection['operation_count'] ?? 0) === 1 && ($projection['exception_count'] ?? 0) === 1, '单订单投影同时保留正常待办与异常待办');
workbenchReadAssert(($repo->calls[1] ?? []) === ['orderProjection','OD-READ-1'], '单订单投影只传公开订单号');
workbenchReadAssert($query->orderProjection('MISSING') === null, '不存在订单保持 null 语义供 Http 映射 404');

$internalProjection = $query->orderProjectionById(19);
workbenchReadAssert(($internalProjection['order_id'] ?? '') === 'OD-INTERNAL-19', '兼容 Web 路由可通过只读用例解析内部订单 ID');
workbenchReadAssert(($repo->calls[3] ?? []) === ['orderProjectionById',19], '内部订单 ID 解析仍只通过 Contract');

$disputeProjection = $query->disputeProjectionById(23);
workbenchReadAssert(($disputeProjection['dispute_id'] ?? '') === 'DSP-INTERNAL-23', '旧 Web 争议数字 ID 可映射为当前公开争议任务');
workbenchReadAssert(($repo->calls[4] ?? []) === ['disputeProjectionById',23], '内部争议 ID 解析仍只通过 Contract');

$list = $query->list('refund_dispute', 3, 25, '退款');
workbenchReadAssert(($list['scope'] ?? '') === 'refund_dispute', '待办列表 scope 不被 Application 改写');
workbenchReadAssert(($list['pagination']['page'] ?? 0) === 3, '待办列表分页参数不被 Application 改写');
workbenchReadAssert(($repo->calls[5] ?? []) === ['list','refund_dispute',3,25,'退款'], '列表查询参数完整传给只读 Repository');

$contactProjection = $query->contactRevealProjectionById(29);
workbenchReadAssert(($contactProjection['request_id'] ?? '') === 'CONTACT-INTERNAL-29', '内部电话公开申请 ID 可映射为当前公开审核任务');
workbenchReadAssert(($repo->calls[6] ?? []) === ['contactRevealProjectionById',29], '内部电话公开申请 ID 解析仍只通过 Contract');
workbenchReadAssert($query->contactRevealProjectionById(0) === null, '无效电话公开申请 ID 保持 null 语义');

$all = $query->allOrders(2, 25, '师傅');
workbenchReadAssert(($all['scope'] ?? '') === 'all' && ($all['pagination']['page'] ?? 0) === 2, '全部订单独立查询由 Application 透传');
workbenchReadAssert(($all['items'][0]['operation_count'] ?? 0) === 1 && ($all['items'][0]['exception_count'] ?? 0) === 1, '全部订单卡片可以同时携带正常与异常角标');
workbenchReadAssert(end($repo->calls) === ['allOrders',2,25,'师傅'], '全部订单查询参数完整传给只读 Repository');

require_once __DIR__.'/support/order_runtime.php';
$contactDb = new class extends \WorkerAdmin\Core\Database {
    public bool $pending = true;
    public string $sourceId = 'CONTACT-29';
    public int $reads = 0;
    public function __construct() {}
    public function fetch($sql, array $params = []) {
        $this->reads++;
        if (($params['id'] ?? 0) !== 29) return null;
        return ['request_internal_id'=>29,'request_id'=>'CONTACT-29','order_internal_id'=>19,'order_id'=>'OD-19','version'=>10];
    }
    public function fetchAll($sql, array $params = []) {
        if (!$this->pending || strpos($sql,'FROM order_worker_contact_reveal_requests r') === false) return [];
        workbenchReadAssert(strpos($sql, 'o.id IN (19)') !== false, '电话公开任务只按申请所属订单读取');
        return [['order_id'=>19,'order_public_no'=>'OD-19','order_version'=>10,'source_id'=>$this->sourceId,'context_type'=>'service','reason'=>'联系超时','worker_name'=>'师傅','task_created_at'=>'2026-10-08 00:00:00']];
    }
};
$contactRead = new \WorkerAdmin\Infrastructure\Persistence\DatabaseAdminWorkbenchTaskRepository($contactDb, true);
$actualContact = $contactRead->contactRevealProjectionById(29);
workbenchReadAssert(($actualContact['request_id'] ?? '') === 'CONTACT-29' && ($actualContact['order_id'] ?? '') === 'OD-19', '真实工作台仓储实现内部申请到公开任务的映射');
$contactDb->sourceId = 'CONTACT-OTHER';
workbenchReadAssert($contactRead->contactRevealProjectionById(29) === null, '同一订单另一申请的待办不能冒充目标申请');
$contactDb->pending = false;
workbenchReadAssert($contactRead->contactRevealProjectionById(29) === null, '已处理申请不会恢复为管理员待办');
workbenchReadAssert($contactRead->contactRevealProjectionById(99) === null, '不存在申请返回 null');
$reads = $contactDb->reads;
workbenchReadAssert($contactRead->contactRevealProjectionById(0) === null && $contactDb->reads === $reads, '无效内部申请 ID 不访问数据库');

$querySource = (string)file_get_contents($root . '/server/app/Application/Admin/AdminWorkbenchQuery.php');
$contractSource = (string)file_get_contents($root . '/server/app/Contracts/AdminWorkbenchTaskRepository.php');
$repositorySource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/DatabaseAdminWorkbenchTaskRepository.php');
$orderListSource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/DatabaseAdminWorkbenchOrderListReader.php');
$operationalSource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/OperationalOrderWorkbenchTaskSource.php');
$platformSource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/PlatformOrderWorkbenchTaskSource.php');
$exceptionSource = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/OrderExceptionWorkbenchTaskSource.php');
$moduleSource = (string)file_get_contents($root . '/server/app/Http/Api/Modules/AdminWorkbenchApiModule.php');
$legacySource = (string)file_get_contents($root . '/server/app/Http/Api/Modules/LegacyApiModule.php');
$builderSource = (string)file_get_contents($root . '/server/app/Services/AdminWorkbenchTaskBuilder.php');
$compositionSource = (string)file_get_contents($root . '/server/app/Foundation/AdminWorkbenchReadComposition.php');
$webModuleSource = (string)file_get_contents($root . '/server/app/Http/Admin/Modules/ExceptionDashboardAdminModule.php');
$refundWebSource = (string)file_get_contents($root . '/server/app/Http/Admin/Modules/RefundReviewAdminModule.php');
$disputeWebSource = (string)file_get_contents($root . '/server/app/Http/Admin/Modules/DisputeReviewAdminModule.php');
$contactWebSource = (string)file_get_contents($root . '/server/app/Http/Admin/Modules/ContactRevealReviewAdminModule.php');
$adminKernelSource = (string)file_get_contents($root . '/server/app/Http/Admin/AdminKernel.php');
$webViewSource = (string)file_get_contents($root . '/server/views/admin/exception-dashboard.php');
$orderListViewSource = (string)file_get_contents($root . '/server/views/admin/orders/index.php');

workbenchReadAssert(strpos($querySource, 'AdminWorkbenchTaskRepository') !== false, 'Application Query 只面向工作台 Contract');
workbenchReadAssert(strpos($querySource, 'WorkerAdmin\\Infrastructure\\') === false, 'Application Query 不依赖 Infrastructure');
workbenchReadAssert(strpos($querySource, 'WorkerAdmin\\Services\\') === false, 'Application Query 不依赖旧 Services');

foreach (['summary','groupsForScope','tasksForOrder','exceptionTasksForOrder','orderProjection','orderProjectionById','disputeProjectionById','contactRevealProjectionById','list','allOrders'] as $method) {
    workbenchReadAssert(strpos($contractSource, 'function ' . $method . '(') !== false, 'Contract 保持读模型方法: ' . $method);
    workbenchReadAssert(strpos($repositorySource, 'function ' . $method . '(') !== false, 'Repository 实现读模型方法: ' . $method);
}
workbenchReadAssert(strpos($repositorySource, 'projectionFromOrder') !== false, '公开订单号和内部 ID 共用同一完整工作台投影构造');
workbenchReadAssert(strpos($repositorySource, "(string)(\$candidate['task_type'] ?? '') === 'dispute_open'") !== false, '内部争议 ID 只有仍属于当前 dispute_open 异常任务才可映射');
workbenchReadAssert(strpos($orderListSource, "return \$this->result(\$items, 'all',") !== false && strpos($repositorySource, "return \$this->lists->allOrders(") !== false, '全部订单使用独立分页读模型而不是异常候选集合');
workbenchReadAssert(strpos($orderListSource, '$this->groups->groupsForOrderIds($pageIds)') !== false, '全部订单只为当前页批量补任务角标');
workbenchReadAssert(strpos($orderListSource, 'tasksForOrder(') === false, '全部订单不得在循环中逐单调用 tasksForOrder');
workbenchReadAssert(strpos($orderListSource, "array_slice(\$tasks, 0, 3)") !== false && strpos($orderListSource, "'task_overflow_count'") !== false, '全部订单卡片只返回前三项任务并保留溢出计数');
foreach ([[$operationalSource,'正常任务源'],[$platformSource,'平台任务源'],[$exceptionSource,'异常任务源']] as $sourceContract) {
    workbenchReadAssert(strpos($sourceContract[0], 'function tasksForOrderIds(array $orderIds)') !== false, $sourceContract[1] . '支持当前页批量读取');
    workbenchReadAssert(strpos($sourceContract[0], 'o.id IN (') !== false, $sourceContract[1] . '批量读取必须在 SQL 层按订单 ID 收窄');
}

workbenchReadAssert(strpos($compositionSource, 'new AdminWorkbenchQuery(') !== false, 'Foundation 组合 Application Query');
workbenchReadAssert(strpos($compositionSource, 'new DatabaseAdminWorkbenchTaskRepository(') !== false, 'Foundation 注入 Infrastructure Repository');
workbenchReadAssert(strpos($moduleSource, 'AdminWorkbenchReadComposition') !== false, '小程序 Http 通过 Foundation 获取读模型');
workbenchReadAssert(strpos($moduleSource, 'AdminWorkbenchQuery') !== false, '小程序 Http 只调用 Application Query');
workbenchReadAssert(strpos($moduleSource, 'WorkerAdmin\\Services\\') === false, '小程序工作台读模块不依赖旧 Services');
workbenchReadAssert(strpos($moduleSource, 'WorkerAdmin\\Infrastructure\\') === false, '小程序工作台读模块不直接依赖 Infrastructure');
foreach (['SELECT ','INSERT ','UPDATE ','DELETE '] as $sqlToken) {
    workbenchReadAssert(strpos($moduleSource, $sqlToken) === false, '小程序工作台读模块不得包含 SQL: ' . trim($sqlToken));
    workbenchReadAssert(strpos($webModuleSource, $sqlToken) === false, 'Web 异常工作台模块不得包含 SQL: ' . trim($sqlToken));
    workbenchReadAssert(strpos($refundWebSource, $sqlToken) === false, 'Web 退款审核模块不得包含 SQL: ' . trim($sqlToken));
    workbenchReadAssert(strpos($disputeWebSource, $sqlToken) === false, 'Web 争议裁定模块不得包含 SQL: ' . trim($sqlToken));
    workbenchReadAssert(strpos($contactWebSource, $sqlToken) === false, 'Web 电话公开模块不得包含 SQL: ' . trim($sqlToken));
    workbenchReadAssert(strpos($builderSource, $sqlToken) === false, '旧 Builder 兼容门面不得包含 SQL: ' . trim($sqlToken));
}
workbenchReadAssert(strpos($builderSource, 'DatabaseAdminWorkbenchTaskRepository') !== false, '旧 Builder 与新链共用同一 Repository');
workbenchReadAssert(strpos($builderSource, 'OperationalOrderWorkbenchTaskSource') === false, '旧 Builder 不再自行组合 Source');
workbenchReadAssert(strpos($builderSource, 'OrderExceptionWorkbenchTaskSource') === false, '旧 Builder 不再自行维护异常规则');

workbenchReadAssert(strpos($moduleSource, "['admin.dashboard','admin.orders','admin.workbench.order','admin.workbench.workers']") !== false, '小程序工作台三条读路由由新模块无条件接管');
workbenchReadAssert(strpos($moduleSource, 'ADMIN_WORKBENCH_SCOPE_NOT_ALLOWED') !== false, '未知 scope 仍由工作台新模块明确拒绝而非回退 Legacy');
workbenchReadAssert(strpos($moduleSource, "'todo','all','operation','exception','audit','assignment','pricing','refund_after_sale','settlement'") !== false, '小程序工作台同时暴露正常待办、全部订单、异常和核心业务分类');
workbenchReadAssert(strpos($moduleSource, "\$scope === 'all'") !== false && strpos($moduleSource, 'allOrders(') !== false, '全部订单 scope 使用独立读模型');
foreach (['admin.dashboard','admin.orders','admin.workbench.order'] as $route) {
    workbenchReadAssert(strpos($legacySource, "'{$route}'") !== false, $route . ' 已从小程序 Legacy 回退排除');
}

workbenchReadAssert(strpos($webModuleSource, 'AdminWorkbenchReadComposition') !== false, 'Web 异常工作台复用统一读模型组合根');
workbenchReadAssert(strpos($webModuleSource, 'AdminWorkbenchTaskBuilder') === false, 'Web 异常工作台不得依赖旧 Builder');
workbenchReadAssert(strpos($webModuleSource, 'WorkerAdmin\\Services\\') === false, 'Web 异常工作台不得依赖旧 Services');
workbenchReadAssert(strpos($webModuleSource, "'exception','publish','refund_dispute','finance','income_verification','privacy'") !== false, 'Web 异常面板继续只暴露六个异常 scope');
workbenchReadAssert(strpos($webModuleSource, "return \$route === 'dashboard';") !== false, 'Web 专用模块接管 dashboard 路由');
$exceptionModulePos = strpos($adminKernelSource, 'new ExceptionDashboardAdminModule(');
$refundModulePos = strpos($adminKernelSource, 'new RefundReviewAdminModule(');
$disputeModulePos = strpos($adminKernelSource, 'new DisputeReviewAdminModule(');
$contactModulePos = strpos($adminKernelSource, 'new ContactRevealReviewAdminModule(');
$legacyModulePos = strpos($adminKernelSource, 'new LegacyAdminModule(');
workbenchReadAssert($exceptionModulePos !== false && $legacyModulePos !== false && $exceptionModulePos < $legacyModulePos, 'AdminKernel 在 Legacy 前注册 Web 异常工作台');
workbenchReadAssert($refundModulePos !== false && $refundModulePos < $legacyModulePos, 'Web 退款审核模块在 Legacy 前接管');
workbenchReadAssert($disputeModulePos !== false && $disputeModulePos < $legacyModulePos, 'Web 争议裁定模块在 Legacy 前接管');
workbenchReadAssert($contactModulePos !== false && $contactModulePos < $legacyModulePos, 'Web 电话公开模块在 Legacy 前接管');

workbenchReadAssert(strpos($refundWebSource, "'orders.cancel.review'") !== false, '旧订单退款审核路由由新退款模块兼容接管');
workbenchReadAssert(strpos($refundWebSource, 'orderProjectionById(') !== false, '旧退款表单只通过统一工作台读模型解析内部订单 ID');
workbenchReadAssert(strpos($refundWebSource, '->cancellation()->review(') !== false, '旧退款表单最终调用统一 OrderCancellation');
workbenchReadAssert(strpos($refundWebSource, 'WorkerAdmin\\Controllers\\') === false && strpos($refundWebSource, 'WorkerAdmin\\Services\\') === false, 'Web 退款兼容入口不依赖旧 Controller/Service');
workbenchReadAssert(strpos($orderListViewSource, "admin_url('orders.cancel.review'") !== false, '旧订单列表表单仍可兼容提交，但业务路由已被新模块截获');

workbenchReadAssert(strpos($disputeWebSource, "'disputes.resolve'") !== false, '旧争议列表提交路由由新争议模块兼容接管');
workbenchReadAssert(strpos($disputeWebSource, 'disputeProjectionById(') !== false, '旧争议表单只通过统一工作台读模型解析内部争议 ID');
workbenchReadAssert(strpos($disputeWebSource, "->dispute()->execute(") !== false, '旧争议表单最终调用统一 OrderDispute');
workbenchReadAssert(strpos($disputeWebSource, 'WorkerAdmin\\Controllers\\') === false && strpos($disputeWebSource, 'WorkerAdmin\\Services\\') === false, 'Web 争议兼容入口不依赖旧 Controller/Service');

workbenchReadAssert(strpos($contactWebSource, "'orders.contact-reveal.review'") !== false, '旧电话公开审核路由由新模块兼容接管');
workbenchReadAssert(strpos($contactWebSource, 'reviewById(') !== false && strpos($contactWebSource, "['channel'=>'web_admin','admin_user_id'=>\$adminId]") !== false, '旧电话表单复用统一审批用例并保持 Web 管理员 ID 域');
workbenchReadAssert(strpos($contactWebSource, 'catch (RuntimeException $exception)') !== false, '电话公开可预期业务冲突不会被误报系统异常');
workbenchReadAssert(strpos($orderListViewSource, "admin_url('orders.contact-reveal.review'") !== false, '旧电话公开表单仍可兼容提交，但业务路由已被新模块截获');

workbenchReadAssert(strpos($webViewSource, '正常审核、正常派单、测量核价不进入这里') !== false, 'Web 异常面板仍明确不承接正常运营动作');
workbenchReadAssert(strpos($webViewSource, "'exception'=>'全部异常'") !== false, 'Web 异常面板以异常总览为默认主导航');
workbenchReadAssert(strpos($webViewSource, "['platform_auto_publish_failed','platform_auto_publish_stuck']") !== false && strpos($webViewSource, "admin_url('platform-orders')") !== false, '自动发布异常直接进入平台承接单修复入口');
workbenchReadAssert(strpos($webViewSource, "['platform_finance_risk','platform_refund_finance_review','platform_refund_recovery_verification','platform_income_verification']") !== false && strpos($webViewSource, "admin_url('platform-order-finance')") !== false, '平台资金异常直接进入统一结算核账');
workbenchReadAssert(strpos($webViewSource, "\$sourceType === 'cancel_request'") !== false && strpos($webViewSource, "admin_url('refunds.execution')") !== false, '双方已同意退款直接进入 Web 财务执行核账');
workbenchReadAssert(strpos($webViewSource, "\$sourceType === 'refund'") !== false && strpos($webViewSource, "admin_url('refunds',['q'=>\$item['public_no']])") !== false, 'failed 退款进入真正支持订单号搜索的通用退款管理');
workbenchReadAssert(strpos($webViewSource, "admin_url('dispute-review'") !== false, '争议异常一步进入专项裁定页');
workbenchReadAssert(strpos($webViewSource, "admin_url('disputes',['q'=>\$item['public_no']])") === false, '争议异常不再跳到无法按 q 过滤的旧争议列表');

echo "\nV2.5.0 admin workbench read model regression: OK\n";

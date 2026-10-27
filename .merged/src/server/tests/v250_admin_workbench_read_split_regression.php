<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function workbenchSplitAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $label . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

$repository = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/DatabaseAdminWorkbenchTaskRepository.php');
$groups = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/AdminWorkbenchTaskGroupReader.php');
$lists = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/DatabaseAdminWorkbenchOrderListReader.php');

workbenchSplitAssert(strpos($repository, 'AdminWorkbenchTaskGroupReader') !== false, 'Contract Repository 委托任务聚合读取器');
workbenchSplitAssert(strpos($repository, 'DatabaseAdminWorkbenchOrderListReader') !== false, 'Contract Repository 委托订单卡片读取器');
workbenchSplitAssert(strpos($repository, 'SELECT o.id,o.public_no,o.title') === false, '薄 Repository 不再承担订单列表大 SQL');
foreach (['OperationalOrderWorkbenchTaskSource','PlatformOrderWorkbenchTaskSource','OrderExceptionWorkbenchTaskSource'] as $source) {
    workbenchSplitAssert(strpos($repository, 'new ' . $source) === false, '薄 Repository 不直接实例化 ' . $source);
    workbenchSplitAssert(strpos($groups, 'new ' . $source) !== false, '任务聚合读取器拥有 ' . $source);
}

workbenchSplitAssert(strpos($groups, 'function groupsForOrderIds(array $orderIds)') !== false, '任务聚合读取器支持按当前页 ID 批量读取');
workbenchSplitAssert(strpos($lists, '$this->groups->groupsForOrderIds($pageIds)') !== false, '全部订单只为当前页批量补任务角标');
workbenchSplitAssert(strpos($lists, 'tasksForOrder(') === false, '订单卡片读取器禁止逐单任务查询形成 N+1');
workbenchSplitAssert(strpos($lists, "array_slice(\$tasks, 0, 3)") !== false, '全部订单卡片限制任务摘要数量');
workbenchSplitAssert(strpos($lists, "'task_overflow_count'") !== false, '任务摘要保留溢出计数');
workbenchSplitAssert(substr_count($lists, ' FROM orders o') >= 1, '订单列表 SQL 归属于专用读取器');

$component = (string)file_get_contents($root . '/miniprogram/components/admin-order-operations/index.wxml');
$page = (string)file_get_contents($root . '/miniprogram/pages/adminWorkbench/detail.wxml');
$pageJson = (string)file_get_contents($root . '/miniprogram/pages/adminWorkbench/detail.json');
$indexJs = (string)file_get_contents($root . '/miniprogram/pages/adminWorkbench/index.js');
$indexWxml = (string)file_get_contents($root . '/miniprogram/pages/adminWorkbench/index.wxml');
workbenchSplitAssert(strpos($pageJson, 'admin-order-operations') !== false, '订单运营详情注册业务组件');
workbenchSplitAssert(strpos($page, '<admin-order-operations') !== false, '页面壳通过业务组件组合管理员操作');
workbenchSplitAssert(strpos($page, 'bindpricing="openPricingReview"') !== false && strpos($page, 'bindmore="openOrderOperations"') !== false, '页面壳只接收业务组件事件');
workbenchSplitAssert(strpos($component, 'actions.can_review_platform_pricing') !== false && strpos($component, 'actions.can_admin_invite_worker') !== false, '业务组件只消费服务端能力投影');
workbenchSplitAssert(strpos($component, 'OrderApi') === false && strpos($component, 'request(') === false, '运营操作组件不自行调用 API 或复制业务流程');

workbenchSplitAssert(strpos($indexJs, 'returningFromOrderDetail') !== false, '工作台记录从订单详情返回状态');
workbenchSplitAssert(strpos($indexJs, 'refreshReturnedOrder') !== false, '工作台返回后恢复原订单位置');
workbenchSplitAssert(strpos($indexJs, "patch['items[' + index + ']'] = updated") !== false, '工作台返回刷新不先清空已有列表');
workbenchSplitAssert(strpos($indexWxml, 'id="admin-order-{{item.public_no}}"') !== false, '工作台订单卡片提供稳定返回锚点');

// 管理员邀请师傅组件与工作台属于同一 UI/能力投影边界，不再单独维护一个小 regression 文件。
$assignmentJs = (string)file_get_contents($root . '/miniprogram/components/admin-worker-assignment/index.js');
$assignmentWxml = (string)file_get_contents($root . '/miniprogram/components/admin-worker-assignment/index.wxml');
$detailJs = (string)file_get_contents($root . '/miniprogram/pages/adminWorkbench/detail.js');
$userOrderWxml = (string)file_get_contents($root . '/miniprogram/pages/userOrder/detail.wxml');
$userOrderJson = (string)file_get_contents($root . '/miniprogram/pages/userOrder/detail.json');
workbenchSplitAssert(strpos($assignmentJs, "OrderApi.request('admin.order.share'") !== false, '邀请组件复用既有 admin.order.share 服务端入口');
workbenchSplitAssert(strpos($assignmentJs, "share_type: 'worker_invite'") !== false, '邀请组件固定使用 worker_invite 业务类型');
workbenchSplitAssert(strpos($assignmentJs, 'can_admin_invite_worker') !== false, '邀请组件只在服务端能力允许时提交');
workbenchSplitAssert(strpos($assignmentJs, 'quotes.switch') === false && strpos($assignmentJs, 'assignments') === false, '邀请组件不冒充用户换报价或直接操作 assignment');
workbenchSplitAssert(strpos($assignmentWxml, 'show-menu-by-longpress="true"') !== false, '生成后小程序码支持长按转发或保存');
workbenchSplitAssert(strpos($pageJson, 'admin-worker-assignment') !== false, '工作台注册统一邀请组件');
workbenchSplitAssert(strpos($page, 'id="workbenchAssignment"') !== false && strpos($page, 'bindinvite="openAssignment"') !== false, '工作台复用统一邀请组件');
workbenchSplitAssert(strpos($detailJs, "if (action === 'create_worker_invite') return this.openAssignment()") !== false, '待安排师傅任务直接打开组件');
workbenchSplitAssert(strpos($userOrderJson, 'admin-worker-assignment') !== false, '订单详情注册同一邀请组件');
workbenchSplitAssert(strpos($userOrderWxml, '<admin-worker-assignment') !== false && strpos($userOrderWxml, 'variant="footer"') !== false, '订单详情底栏复用同一邀请组件');
workbenchSplitAssert(strpos($userOrderWxml, 'data-type="worker_invite" bindtap="openAdminShare"') === false, '订单详情不保留旧 worker_invite 活跃入口');
workbenchSplitAssert(strpos($userOrderWxml, 'data-type="ordinary" bindtap="openAdminShare"') !== false, '普通订单分享继续保留原有能力');

echo "\nV2.5.0 admin workbench read split regression: OK\n";

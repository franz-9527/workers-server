<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function reliabilitySource(string $relative): string
{
    global $root;
    $value = file_get_contents($root . '/' . $relative);
    if (!is_string($value)) {
        fwrite(STDERR, '[FAIL] 无法读取 ' . $relative . "\n");
        exit(1);
    }
    return $value;
}

function reliabilityAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $label . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

$orderSubmission = reliabilitySource('server/app/Application/Order/OrderSubmission.php');
$mediaTask = reliabilitySource('server/app/Infrastructure/Scheduling/MediaPromotionTask.php');
$schedulerGateway = reliabilitySource('server/app/Infrastructure/Scheduling/LegacySchedulerGateway.php');
$schedulerAdmin = reliabilitySource('server/app/Http/Admin/Modules/SchedulerAdminModule.php');
$schedulerConsole = reliabilitySource('server/app/Application/Scheduling/SchedulerAdminConsole.php');
$schedulerRepository = reliabilitySource('server/app/Infrastructure/Persistence/DatabaseSchedulerAdminRepository.php');
$database = reliabilitySource('server/app/Core/Database.php');
$orderDeal = reliabilitySource('server/app/Infrastructure/Persistence/OrderDealAssignmentOperations.php');
$quoteSelection = reliabilitySource('server/app/Infrastructure/Persistence/DatabaseOrderQuoteSelectionRepository.php');
$nearbySms = reliabilitySource('server/app/Infrastructure/Persistence/DatabaseOrderNearbyWorkerRepository.php');
$paidSms = reliabilitySource('server/app/Infrastructure/Messaging/DatabasePaidSmsDeliveryRepository.php');
$orderSubmissionRepository = reliabilitySource('server/app/Infrastructure/Persistence/DatabaseOrderSubmissionRepository.php');

reliabilityAssert(strpos($orderSubmission, 'promotePendingMedia(') === false, '订单提交请求不再同步执行七牛迁移');
reliabilityAssert(strpos($orderSubmission, 'media_promote') !== false, '订单提交代码明确记录后台媒体迁移边界');
reliabilityAssert(strpos($mediaTask, "storage_disk='private_local'") !== false, '媒体迁移只扫描仍在本地私有盘的对象');
reliabilityAssert(strpos($mediaTask, "payment_status IN ('awaiting_payment','paid')") !== false, '草稿阶段图片不会被后台提前迁移');
reliabilityAssert(strpos($mediaTask, '$this->storage->store(') !== false, '七牛上传只在后台媒体任务执行');
reliabilityAssert(strpos($mediaTask, 'scheduled_job_failures') !== false, '媒体迁移失败进入统一失败台账');
reliabilityAssert(strpos($mediaTask, 'GET_LOCK') !== false && strpos($mediaTask, 'RELEASE_LOCK') !== false, '媒体迁移独立任务具备 MySQL 互斥锁');
reliabilityAssert(substr_count($schedulerGateway, 'new MediaPromotionTask(') >= 2, '媒体迁移同时接入单任务与 all 调度入口');

reliabilityAssert(strpos($schedulerAdmin, "['tasks','tasks.run','tasks.failure.retry']") !== false, '计划任务列表、执行和失败重试统一由新模块接管');
reliabilityAssert(strpos($schedulerAdmin, 'SELECT ') === false && strpos($schedulerAdmin, 'UPDATE ') === false, '计划任务 Http 模块保持无 SQL');
reliabilityAssert(strpos($schedulerAdmin, 'WorkerAdmin\\Infrastructure\\') === false, '计划任务 Http 模块不直接依赖 Infrastructure');
reliabilityAssert(strpos($schedulerConsole, "'media_promote'=>['label'=>'订单图片云端迁移'") !== false, '后台计划任务显示媒体迁移中文入口');
reliabilityAssert(strpos($schedulerConsole, "'platform_auto_publish'=>['label'=>'平台单自动发布'") !== false, '后台计划任务不再漏掉平台单自动发布');
reliabilityAssert(strpos($schedulerRepository, 'scheduled_job_failures') !== false, '计划任务后台查询与失败重试 SQL 已下沉 Infrastructure');

reliabilityAssert(strpos($database, '$transactionDepth') !== false, '数据库层记录共享连接事务深度');
reliabilityAssert(strpos($database, '$transactionRollbackOnly') !== false, '数据库层支持 rollback-only 语义');
reliabilityAssert(strpos($database, '$outermost = $this->transactionDepth === 0') !== false, '只有最外层事务拥有 begin/commit');
reliabilityAssert(substr_count($database, 'beginTransaction()') === 1, '数据库封装不存在内层重复 beginTransaction 路径');
reliabilityAssert(strpos($database, '禁止提交部分业务结果') !== false, '内层失败即使被上层捕获也不能提交部分结果');

$grabStart = strpos($orderDeal, 'private function grabOrder(');
$grabEnd = strpos($orderDeal, 'private function grabPlatformOrder(', $grabStart === false ? 0 : $grabStart);
$grabBlock = ($grabStart !== false && $grabEnd !== false) ? substr($orderDeal, $grabStart, $grabEnd - $grabStart) : '';
reliabilityAssert($grabBlock !== '' && strpos($grabBlock, "SELECT * FROM orders WHERE id=:id FOR UPDATE") !== false, '普通抢单在事务内先锁订单再复核资格');
$orderLock = strpos($quoteSelection, "SELECT * FROM orders WHERE id=:id FOR UPDATE");
$quoteLock = strpos($quoteSelection, "q.order_id=:order_id LIMIT 1 FOR UPDATE");
reliabilityAssert($orderLock !== false && $quoteLock !== false && $orderLock < $quoteLock, '选报价统一先锁订单再锁报价版本');
reliabilityAssert(strpos($quoteSelection, 'SELECT order_id FROM quotes WHERE BINARY public_id=BINARY :quote_id LIMIT 1') !== false, '选报价只用非锁定映射定位订单后再进入统一锁顺序');
reliabilityAssert(strpos($nearbySms, "SELECT * FROM orders WHERE id=:id FOR UPDATE") !== false, '付费短信创建在事务内锁定订单后再次筛选候选');
reliabilityAssert(strpos($nearbySms, 'client_request_id') !== false && strpos($nearbySms, 'request_fingerprint') !== false, '付费短信批次具备请求幂等与冲突校验');
reliabilityAssert(strpos($orderSubmissionRepository, "action='orders.create'") !== false && strpos($orderSubmissionRepository, 'idempotency_keys') !== false, '下单创建继续使用数据库幂等键');
reliabilityAssert(strpos($paidSms, "delivery_status='uncertain'") !== false, '收费短信发送结果不确定时禁止盲目重发');
reliabilityAssert(strpos($paidSms, "delivery_status='provider_accepted'") !== false, '收费短信区分供应商已受理状态');

echo "V2.5.0 异步与事务边界回归通过。\n";

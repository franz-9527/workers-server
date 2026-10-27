<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/server/app/Domain/Scheduling/SchedulerTaskCatalog.php';

use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;

function schedulerCatalogContract(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $label . "\n");
        exit(1);
    }
    fwrite(STDOUT, '[PASS] ' . $label . "\n");
}

schedulerCatalogContract(
    SchedulerTaskCatalog::normalize('auto-accept') === 'auto_accept',
    '短横线别名统一规范化'
);
schedulerCatalogContract(
    SchedulerTaskCatalog::normalize('media-promote') === 'media_promote',
    '媒体后台迁移别名由统一目录识别'
);
schedulerCatalogContract(
    SchedulerTaskCatalog::normalize('platform-auto-publish') === 'platform_auto_publish',
    '平台自动发布别名由统一目录识别'
);
schedulerCatalogContract(
    in_array('media_promote', SchedulerTaskCatalog::supported(), true),
    '公开任务目录包含媒体后台迁移'
);
schedulerCatalogContract(
    in_array('platform_auto_publish', SchedulerTaskCatalog::supported(), true),
    '公开任务目录包含平台自动发布'
);
schedulerCatalogContract(
    !in_array('media_promote', SchedulerTaskCatalog::legacySupported(), true),
    '旧调度器目录排除媒体后台迁移'
);
schedulerCatalogContract(
    !in_array('platform_auto_publish', SchedulerTaskCatalog::legacySupported(), true),
    '旧调度器目录排除平台自动发布'
);
schedulerCatalogContract(
    SchedulerTaskCatalog::isGatewayOnly('media_promote'),
    '媒体后台迁移明确标记为网关独立任务'
);
schedulerCatalogContract(
    SchedulerTaskCatalog::isGatewayOnly('platform_auto_publish'),
    '平台自动发布明确标记为网关独立任务'
);
schedulerCatalogContract(
    !SchedulerTaskCatalog::isGatewayOnly('auto_accept'),
    '历史任务仍由旧调度器执行'
);

$all = SchedulerTaskCatalog::allSequence();
$legacyAll = SchedulerTaskCatalog::legacyAllSequence();
schedulerCatalogContract(
    array_search('media_promote', $all, true) < array_search('media_cleanup', $all, true),
    '完整 all 顺序先迁移订单媒体再清理无引用媒体'
);
schedulerCatalogContract(
    end($all) === 'platform_auto_publish',
    '完整 all 顺序最后由网关追加平台自动发布'
);
schedulerCatalogContract(
    $legacyAll === array_values(array_filter($all, static function (string $task): bool {
        return !in_array($task, ['media_promote','platform_auto_publish'], true);
    })),
    '旧调度器 all 顺序与统一目录保持一致'
);

$service = file_get_contents($root . '/server/app/Services/SchedulerService.php');
$gateway = file_get_contents($root . '/server/app/Infrastructure/Scheduling/LegacySchedulerGateway.php');
schedulerCatalogContract(is_string($service), '可读取旧调度器源码');
schedulerCatalogContract(is_string($gateway), '可读取防腐网关源码');

$matched = preg_match(
    '/\$tasks\s*=\s*\$task\s*===\s*\'all\'\s*\?\s*\[(.*?)\]\s*:\s*\[\$task\];/s',
    (string)$service,
    $sequenceMatch
);
schedulerCatalogContract($matched === 1, '识别旧调度器 all 执行序列');
preg_match_all("/'([^']+)'/", (string)($sequenceMatch[1] ?? ''), $taskMatches);
$serviceAll = $taskMatches[1] ?? [];
schedulerCatalogContract(
    $serviceAll === SchedulerTaskCatalog::legacyAllSequence(),
    '旧调度器 all 执行顺序未与统一目录漂移'
);

$matched = preg_match(
    '/public function supportedTasks\(\)\s*\{\s*return\s*\[(.*?)\];\s*\}/s',
    (string)$service,
    $supportedMatch
);
schedulerCatalogContract($matched === 1, '识别旧调度器支持任务集合');
preg_match_all("/'([^']+)'/", (string)($supportedMatch[1] ?? ''), $supportedMatches);
$serviceSupported = $supportedMatches[1] ?? [];
$catalogSupported = SchedulerTaskCatalog::legacySupported();
sort($serviceSupported, SORT_STRING);
sort($catalogSupported, SORT_STRING);
schedulerCatalogContract(
    $serviceSupported === $catalogSupported,
    '旧调度器支持任务集合未与统一目录漂移'
);

schedulerCatalogContract(
    strpos((string)$gateway, 'assertLegacyCatalogCompatible') !== false
        && strpos((string)$gateway, 'SchedulerTaskCatalog::legacySupported()') !== false,
    '防腐网关启动时校验旧任务目录'
);
schedulerCatalogContract(
    substr_count((string)$gateway, 'new MediaPromotionTask(') >= 2,
    '媒体后台迁移已接入单任务和 all 两条网关路径'
);
$mediaBeforeLegacy = strpos((string)$gateway, '$mediaRun = (new MediaPromotionTask(');
$legacyAllRun = strpos((string)$gateway, '$result = $this->scheduler->run($task, $force);');
schedulerCatalogContract(
    $mediaBeforeLegacy !== false && $legacyAllRun !== false && $mediaBeforeLegacy < $legacyAllRun,
    'all 真实执行先迁移订单媒体，再进入包含 media_cleanup 的旧调度序列'
);
schedulerCatalogContract(
    strpos((string)$gateway, 'array_splice($result[\'runs\'], $insertAt, 0, [$mediaRun]);') !== false,
    'all 返回的运行记录也把 media_promote 插入 media_cleanup 前'
);

// 配置化自动验收/评价期限与调度目录同属调度契约，合并维护，避免独立小 contract 文件继续增长。
$deadlineBridge = (string)file_get_contents($root . '/server/app/Infrastructure/Scheduling/ConfiguredOrderDeadlineBridge.php');
$schedulerCli = (string)file_get_contents($root . '/server/bin/scheduler.php');
schedulerCatalogContract(strpos($deadlineBridge, "setting('order.auto_accept_hours', 72)") !== false, '自动验收期限读取后台设置');
schedulerCatalogContract(strpos($deadlineBridge, "setting('order.auto_review_days', 5)") !== false, '自动评价期限读取后台设置');
schedulerCatalogContract(strpos($deadlineBridge, 'auto_accept_at=DATE_ADD(completion_submitted_at,INTERVAL {$hours} HOUR)') !== false, '缺失自动验收期限按配置补齐');
schedulerCatalogContract(strpos($deadlineBridge, 'auto_review_at=DATE_ADD(accepted_at,INTERVAL {$days} DAY)') !== false, '缺失自动评价期限按配置补齐');
schedulerCatalogContract(strpos($deadlineBridge, "deadline_type IN ('auto_accept','auto_accept_completion')") !== false, '验收期限与 deadline 表同步');
schedulerCatalogContract(strpos($deadlineBridge, "deadline_type IN ('auto_review','auto_review_order')") !== false, '评价期限与 deadline 表同步');
schedulerCatalogContract(strpos($deadlineBridge, '订单达到自动验收期限，系统已自动确认验收。') !== false, '自动验收事件不写死小时数');
schedulerCatalogContract(strpos((string)$gateway, 'ConfiguredOrderDeadlineBridge') !== false, '旧调度器通过防腐层接入配置化期限');
schedulerCatalogContract(strpos((string)$gateway, '$this->orderDeadlines->prepare($task);') !== false, '计划任务执行前补齐真实期限');
schedulerCatalogContract(strpos((string)$gateway, '$this->orderDeadlines->after($task, $startedAtUtc);') !== false, '自动验收后校准评价期限');
schedulerCatalogContract(strpos($schedulerCli, '后台设置自动验收期限') !== false, 'CLI 自动验收说明与配置一致');
schedulerCatalogContract(strpos($schedulerCli, '后台设置自动评价期限') !== false, 'CLI 自动评价说明与配置一致');

echo "\nV2.5.0 scheduler catalog contract: OK\n";

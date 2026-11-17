<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use LogicException;
use WorkerAdmin\Contracts\SchedulerGateway;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;
use WorkerAdmin\Services\SchedulerService;

/**
 * 旧调度器防腐适配器。保留其 MySQL GET_LOCK、scheduler_runs 审计及逐笔失败记录语义。
 * V2.5.0 新增的平台承接单自动发布、测量冻结释放与订单媒体后台迁移以独立任务接入；订单自动验收/评价期限则先通过
 * ConfiguredOrderDeadlineBridge 收敛为明确时间事实，避免继续把配置逻辑堆进旧 SchedulerService。
 */
final class LegacySchedulerGateway implements SchedulerGateway
{
    private SchedulerService $scheduler;
    private Database $db;
    private int $limit;
    private ConfiguredOrderDeadlineBridge $orderDeadlines;
    private ApprovedOrderRefundRetryBridge $approvedRefunds;
    private SchedulerTaskFailureBackoff $taskFailureBackoff;

    public function __construct(Database $db, int $limit = 100)
    {
        $this->db = $db;
        $this->limit = max(1, min(500, $limit));
        $this->scheduler = new SchedulerService($db, $this->limit);
        $this->taskFailureBackoff = new SchedulerTaskFailureBackoff($db);
        $this->assertLegacyCatalogCompatible();
        $this->orderDeadlines = new ConfiguredOrderDeadlineBridge($db);
        $this->approvedRefunds = new ApprovedOrderRefundRetryBridge($db, $this->limit);
    }

    public function run(string $task, bool $force = false): array
    {
        // 与旧 SchedulerService 保持一致：手动强制执行单任务可绕过退避，
        // 但“执行全部”不强制绕过任何子任务的退避。
        $taskForce = $force && $task !== 'all';
        if ($task === 'media_promote') {
            $retryAt = $this->taskFailureBackoff->retryAt($task, $taskForce);
            $run = $retryAt !== null
                ? $this->taskFailureBackoff->recordSkippedRun($task, $retryAt)
                : (new MediaPromotionTask($this->db, $this->limit))->run();
            return [
                'status'=>$run['status'],
                'task'=>'media_promote',
                'message'=>$run['message'] ?? ($run['status'] === 'success'
                    ? '订单媒体后台迁移任务执行完成。'
                    : '订单媒体后台迁移任务存在异常，请查看异常待办。'),
                'runs'=>[$run],
                'skipped_count'=>($run['status'] ?? '') === 'skipped_backoff' ? 1 : 0,
                'skipped_tasks'=>($run['status'] ?? '') === 'skipped_backoff' ? [$task] : [],
            ];
        }

        if ($task === 'platform_auto_publish') {
            $retryAt = $this->taskFailureBackoff->retryAt($task, $taskForce);
            $run = $retryAt !== null
                ? $this->taskFailureBackoff->recordSkippedRun($task, $retryAt)
                : (new PlatformOrderAutoPublishTask($this->db, $this->limit))->run();
            return [
                'status'=>$run['status'],
                'task'=>'platform_auto_publish',
                'message'=>$run['message'] ?? ($run['status'] === 'success'
                    ? '平台承接单自动发布任务执行完成。'
                    : '平台承接单自动发布任务存在异常，请查看异常待办。'),
                'runs'=>[$run],
                'skipped_count'=>($run['status'] ?? '') === 'skipped_backoff' ? 1 : 0,
                'skipped_tasks'=>($run['status'] ?? '') === 'skipped_backoff' ? [$task] : [],
            ];
        }

        // 单独执行测量冻结释放时直接走新扫描器：LIMIT 之前排除阻断项，避免队头饥饿。
        if ($task === 'measurement_release') {
            $retryAt = $this->taskFailureBackoff->retryAt($task, $taskForce);
            $run = $retryAt !== null
                ? $this->taskFailureBackoff->recordSkippedRun($task, $retryAt)
                : (new MeasurementFrozenBalanceReleaseTask($this->db, $this->limit))->run();
            return [
                'status'=>$run['status'],
                'task'=>'measurement_release',
                'message'=>$run['message'] ?? ($run['status'] === 'success'
                    ? '测量冻结余额释放任务执行完成。'
                    : '测量冻结余额释放任务存在异常，请查看异常待办。'),
                'runs'=>[$run],
                'skipped_count'=>($run['status'] ?? '') === 'skipped_backoff' ? 1 : 0,
                'skipped_tasks'=>($run['status'] ?? '') === 'skipped_backoff' ? [$task] : [],
            ];
        }

        // all 的公开目录要求 media_promote 位于 media_cleanup 前。媒体迁移有独立 GET_LOCK，
        // 不占用旧 SchedulerService 的全局互斥；即使七牛失败也继续执行后续订单/资金/消息任务。
        $mediaRun = null;
        if ($task === 'all') {
            $retryAt = $this->taskFailureBackoff->retryAt('media_promote', $taskForce);
            $mediaRun = $retryAt !== null
                ? $this->taskFailureBackoff->recordSkippedRun('media_promote', $retryAt)
                : (new MediaPromotionTask($this->db, $this->limit))->run();
        }

        // 新订单正常会在履约写入时得到明确期限；这里主要为历史/异常缺失数据兜底，
        // 并在旧自动验收写入后把本次评价期限按后台配置校准。
        $startedAtUtc = gmdate('Y-m-d H:i:s');
        $this->orderDeadlines->prepare($task);
        $result = $this->scheduler->run($task, $force);
        $this->orderDeadlines->after($task, $startedAtUtc);

        if ($task === 'all' && is_array($mediaRun)) {
            if (!isset($result['runs']) || !is_array($result['runs'])) $result['runs'] = [];
            $insertAt = count($result['runs']);
            foreach ($result['runs'] as $index => $legacyRun) {
                if ((string)($legacyRun['task'] ?? '') === 'media_cleanup') {
                    $insertAt = (int)$index;
                    break;
                }
            }
            array_splice($result['runs'], $insertAt, 0, [$mediaRun]);
            if ($mediaRun['status'] === 'failed') {
                $result['status'] = 'failed';
                $result['message'] = '部分计划任务执行失败，请查看运行日志。';
            }
        }

        // legacy all 仍保留旧任务目录以兼容现有 catalog；随后再运行一次无队头饥饿的扫描器。
        // 已由旧任务成功释放的记录不再满足 frozen 条件，因此新扫描天然幂等，只补齐被阻断队头挡住的后续订单。
        if ($task === 'all') {
            // 旧 SchedulerService 已执行过 measurement_release；若它因退避而跳过，
            // 不再为同一轮 all 追加第二条相同的跳过运行记录。
            $measurementAlreadyDeferred = false;
            foreach ((array)($result['runs'] ?? []) as $legacyRun) {
                if ((string)($legacyRun['task'] ?? '') === 'measurement_release'
                    && (string)($legacyRun['status'] ?? '') === 'skipped_backoff') {
                    $measurementAlreadyDeferred = true;
                    break;
                }
            }

            if (!$measurementAlreadyDeferred) {
                $retryAt = $this->taskFailureBackoff->retryAt('measurement_release', $taskForce);
                $measurementRun = $retryAt !== null
                    ? $this->taskFailureBackoff->recordSkippedRun('measurement_release', $retryAt)
                    : (new MeasurementFrozenBalanceReleaseTask($this->db, $this->limit))->run();
                if (!isset($result['runs']) || !is_array($result['runs'])) $result['runs'] = [];
                $result['runs'][] = $measurementRun;
                if ($measurementRun['status'] === 'failed') {
                    $result['status'] = 'failed';
                    $result['message'] = '部分计划任务执行失败，请查看运行日志。';
                }
            }
        }

        // 已批准取消退款、Web 最终争议退款、双方协议完成财务核账后的退款，都由同一可靠桥在事务外提交微信。
        // 继续挂在现有 auto_cancel / all 上：生产部署推荐每分钟运行 all，无需再创建第二条宝塔退款计划任务。
        if ($task === 'auto_cancel' || $task === 'all') {
            $refundRun = $this->approvedRefunds->run();
            if (!isset($result['runs']) || !is_array($result['runs'])) $result['runs'] = [];
            $result['runs'][] = $refundRun;
            if ($refundRun['status'] !== 'success') {
                $result['status'] = 'failed';
                $result['message'] = '部分计划任务执行失败，请查看运行日志。';
            }
        }

        if ($task === 'all') {
            $chatRun=(new \WorkerAdmin\Infrastructure\Persistence\DatabaseChatPolicyMaintenance($this->db))->refresh($this->limit);
            if($chatRun['scanned']>0){
                $result['runs'][]=$chatRun;
                if($chatRun['status']==='failed'){$result['status']='failed';$result['message']='部分计划任务执行失败，请查看运行日志。';}
            }
            // 平台单自动发布保持在公开目录最后；独立失败不会破坏前序任务自己的事务与审计。
            $retryAt = $this->taskFailureBackoff->retryAt('platform_auto_publish', $taskForce);
            $run = $retryAt !== null
                ? $this->taskFailureBackoff->recordSkippedRun('platform_auto_publish', $retryAt)
                : (new PlatformOrderAutoPublishTask($this->db, $this->limit))->run();
            if (!isset($result['runs']) || !is_array($result['runs'])) $result['runs'] = [];
            $result['runs'][] = $run;
            if ($run['status'] === 'failed') {
                $result['status'] = 'failed';
                $result['message'] = '部分计划任务执行失败，请查看运行日志。';
            }
        }
        return $this->summarize($result);
    }

    /**
     * 汇总旧调度器与 Gateway 独立任务的失败/退避状态。
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function summarize(array $result): array
    {
        $runs = isset($result['runs']) && is_array($result['runs']) ? $result['runs'] : [];
        $failed = false;
        $skipped = [];
        foreach ($runs as $run) {
            $status = (string)($run['status'] ?? '');
            if ($status === 'failed') $failed = true;
            if ($status === 'skipped_backoff') {
                $task = trim((string)($run['task'] ?? ''));
                if ($task !== '') $skipped[] = $task;
            }
        }
        $skipped = array_values(array_unique($skipped));
        $result['skipped_count'] = count($skipped);
        $result['skipped_tasks'] = $skipped;

        if ($failed || (string)($result['status'] ?? '') === 'failed') {
            $result['status'] = 'failed';
            $result['message'] = '部分计划任务执行失败，请查看运行日志。';
        } elseif ($skipped && strpos((string)($result['status'] ?? ''), 'skipped_') !== 0) {
            $result['status'] = 'success';
            $result['message'] = '计划任务执行完成，以下任务仍处于失败退避期，已暂缓自动执行：' . implode('、', $skipped) . '。';
        }

        return $result;
    }

    /**
     * 顶层 SchedulerTaskCatalog 是 CLI / 后台唯一公开目录。
     * 旧 SchedulerService 只允许少掉明确的 gateway-only 任务；一旦新增任务忘记接线，启动即暴露问题，
     * 不再等到线上手工执行才发现“页面有任务、旧调度器不认识”。
     */
    private function assertLegacyCatalogCompatible(): void
    {
        $actual = array_values(array_map('strval', $this->scheduler->supportedTasks()));
        $expected = SchedulerTaskCatalog::legacySupported();
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new LogicException('旧计划任务目录与统一 SchedulerTaskCatalog 不一致，请同步调度接线。');
        }
    }
}

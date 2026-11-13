<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Scheduling;

use RuntimeException;
use WorkerAdmin\Contracts\SchedulerAdminRepository;
use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;

/** 后台计划任务查询、手工执行与失败重试的应用用例。 */
final class SchedulerAdminConsole
{
    private SchedulerAdminRepository $repository;

    public function __construct(SchedulerAdminRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $runs = $this->repository->recentRuns(300);
        $jobAliases = [
            'auto_accept_completion'=>'auto_accept',
            'generate_commission_receivable'=>'commission',
            'auto_review_order'=>'auto_review',
            'auto_cancel_unaccepted'=>'auto_cancel',
        ];
        $runGroups = [];
        foreach ($runs as $row) {
            $rawName = (string)($row['job_name'] ?? '');
            $groupName = $jobAliases[$rawName] ?? $rawName;
            if (!isset($runGroups[$groupName])) {
                $runGroups[$groupName] = [
                    'job_name'=>$groupName,
                    'latest'=>$row,
                    'history'=>[],
                    'record_count'=>1,
                ];
                continue;
            }
            $runGroups[$groupName]['record_count']++;
            $runGroups[$groupName]['history'][] = $row;
        }
        $runGroups = array_values($runGroups);
        usort($runGroups, static function (array $a, array $b): int {
            return (int)($b['latest']['id'] ?? 0) <=> (int)($a['latest']['id'] ?? 0);
        });

        return [
            'runGroups'=>$runGroups,
            'runRecordCount'=>count($runs),
            'failures'=>$this->repository->unresolvedFailures(100),
            'failureCount'=>$this->repository->unresolvedFailureCount(),
            'taskCatalog'=>$this->taskMetadata(),
        ];
    }

    /** @return array<string,mixed> */
    public function run(SchedulerRunner $runner, string $task, bool $force): array
    {
        $task = trim($task);
        if (!in_array($task, SchedulerTaskCatalog::supported(), true)) {
            throw new RuntimeException('请选择有效的计划任务。');
        }
        return $runner->run($task, $force);
    }

    /** @return array{failure:array<string,mixed>,result:array<string,mixed>} */
    public function retryFailure(SchedulerRunner $runner, int $id): array
    {
        $row = $this->repository->failure($id);
        if (!$row) throw new RuntimeException('计划任务失败记录不存在。');
        if (!empty($row['resolved_at'])) throw new RuntimeException('该失败已经解决，无需再次重试。');

        $task = (string)($row['job_name'] ?? '');
        if ($task === 'all' || !in_array($task, SchedulerTaskCatalog::supported(), true)) {
            throw new RuntimeException('该失败对应的任务不支持后台直接重试。');
        }
        if (!$this->repository->makeFailureRetryable((int)$row['id'])) {
            throw new RuntimeException('失败记录状态已变化，请刷新后重试。');
        }
        return [
            'failure'=>$row,
            'result'=>$runner->run($task, true),
        ];
    }

    /** @return array<string,array{label:string,note:string}> */
    public function taskMetadata(): array
    {
        $metadata = [
            'assignment_timeout'=>['label'=>'派单超时','note'=>'处理已超过接单/派单时限的记录'],
            'supplement_timeout'=>['label'=>'补资料超时','note'=>'处理已超过补资料期限的订单'],
            'repair_escalation'=>['label'=>'售后平台介入提醒','note'=>'检查超过 48 小时的维修提醒'],
            'auto_accept'=>['label'=>'自动验收','note'=>'处理已到自动验收时间的订单'],
            'commission'=>['label'=>'平台服务费','note'=>'生成或推进到期服务费事项'],
            'auto_review'=>['label'=>'自动评价','note'=>'处理已达到自动评价条件的订单'],
            'auto_cancel'=>['label'=>'订单自动取消','note'=>'处理已达到自动取消条件的订单'],
            'measurement_accept'=>['label'=>'测量单自动确认','note'=>'处理已达到自动确认时间的测量成果'],
            'measurement_release'=>['label'=>'测量费冻结解冻','note'=>'处理已到释放时间的测量费冻结'],
            'platform_balance_release'=>['label'=>'平台单工费解冻','note'=>'冻结期到期且无未决争议时转入师傅可用余额'],
            'draft_cleanup'=>['label'=>'过期草稿清理','note'=>'归档过期未付款草稿'],
            'media_promote'=>['label'=>'订单图片云端迁移','note'=>'把已提交订单的本地临时图片异步迁移到七牛对象存储'],
            'media_cleanup'=>['label'=>'无引用图片清理','note'=>'重试到期的孤儿图片并显示具体失败原因'],
            'sms_dispatch'=>['label'=>'短信 / 企业微信发送','note'=>'发送当前待投递消息'],
            'paid_sms_dispatch'=>['label'=>'付费短信发送','note'=>'发送已预占余额的附近师傅短信'],
            'paid_sms_receipt'=>['label'=>'付费短信回执结算','note'=>'查询短信送达并完成扣费或释放预占'],
            'wallet_reconcile'=>['label'=>'用户钱包台账对账','note'=>'退款兜底每次执行；完整钱包台账最多每小时对账一次'],
            'platform_auto_publish'=>['label'=>'平台单自动发布','note'=>'自动发布已满足支付和业务条件的平台承接单'],
        ];
        $ordered = [];
        foreach (SchedulerTaskCatalog::allSequence() as $task) {
            $ordered[$task] = $metadata[$task] ?? ['label'=>$task,'note'=>'按当前业务条件执行该计划任务'];
        }
        return $ordered;
    }
}

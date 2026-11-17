<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use WorkerAdmin\Core\Database;

/**
 * 统一处理 scheduled_job_failures 中 business_key=task:<job> 的任务级异常退避。
 *
 * 逐笔业务失败仍由各任务自己的候选查询负责；这里仅阻止任务级依赖异常每分钟重复触发。
 */
final class SchedulerTaskFailureBackoff
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 返回当前任务级失败的最晚未来重试时间；手动强制执行时不应用任务级退避。
     */
    public function retryAt(string $jobName, bool $force = false): ?string
    {
        if ($force) {
            return null;
        }

        $jobName = trim($jobName);
        if ($jobName === '' || strlen($jobName) > 64) {
            return null;
        }

        $value = $this->db->fetchValue(
            'SELECT MAX(next_retry_at) FROM scheduled_job_failures '
            . 'WHERE job_name=:job_name AND business_key=:business_key AND business_version=1 '
            . 'AND resolved_at IS NULL AND next_retry_at>UTC_TIMESTAMP()',
            [
                'job_name' => $jobName,
                'business_key' => 'task:' . $jobName,
            ]
        );

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Gateway 独立任务在退避期内不进入真实任务类，但仍记录一条有界的跳过摘要。
     *
     * @return array<string,mixed>
     */
    public function recordSkippedRun(string $jobName, string $retryAt): array
    {
        $runUuid = $this->uuidV4();
        $runId = $this->db->insert(
            "INSERT INTO scheduler_runs(
                run_uuid,job_name,status,host_name,process_id,
                scanned_count,success_count,failure_count,started_at
             ) VALUES(:uuid,:job,'running',:host,:pid,0,0,0,UTC_TIMESTAMP())",
            [
                'uuid' => $runUuid,
                'job' => $jobName,
                'host' => substr((string)gethostname(), 0, 128),
                'pid' => function_exists('getmypid') ? (int)getmypid() : null,
            ]
        );

        $message = $this->message($retryAt);
        $this->db->execute(
            "UPDATE scheduler_runs
             SET status='skipped_backoff',finished_at=UTC_TIMESTAMP(),error_message=:error
             WHERE id=:id",
            ['error' => $message, 'id' => $runId]
        );
        $this->db->execute(
            "DELETE FROM scheduler_runs
             WHERE job_name=:job AND status='skipped_backoff' AND id<>:id",
            ['job' => $jobName, 'id' => $runId]
        );

        return [
            'run_id' => $runId,
            'run_uuid' => $runUuid,
            'task' => $jobName,
            'status' => 'skipped_backoff',
            'scanned' => 0,
            'success' => 0,
            'failure' => 0,
            'duration_ms' => 0,
            'error' => $message,
            'message' => $message,
            'next_retry_at' => $retryAt,
        ];
    }

    public function message(string $retryAt): string
    {
        return '任务级失败仍处于退避期，自动执行已暂缓；下次重试时间：' . $retryAt . ' UTC。';
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

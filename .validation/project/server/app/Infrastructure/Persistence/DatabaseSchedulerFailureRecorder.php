<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;

/**
 * One unresolved failure row per scheduler business identity.
 *
 * A MySQL advisory lock serializes the read/merge/write sequence across all
 * scheduler entry points without adding a schema migration or weakening history.
 */
final class DatabaseSchedulerFailureRecorder
{
    private const LOCK_WAIT_SECONDS = 3;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function record(
        ?int $runId,
        string $jobName,
        string $businessKey,
        int $businessVersion,
        string $errorClass,
        string $errorMessage,
        int $initialDelayMinutes = 1
    ): int {
        $jobName = substr(trim($jobName), 0, 64);
        $businessKey = substr(trim($businessKey), 0, 128);
        $businessVersion = max(1, $businessVersion);
        $initialDelayMinutes = max(1, min(1440, $initialDelayMinutes));
        if ($jobName === '' || $businessKey === '') {
            throw new RuntimeException('计划任务失败记录缺少任务名或业务键。');
        }

        $lockName = 'worker_sched_failure_' . substr(
            hash('sha256', $jobName . "\0" . $businessKey . "\0" . $businessVersion),
            0,
            40
        );
        $locked = (int)$this->db->fetchValue(
            'SELECT GET_LOCK(:lock_name,' . self::LOCK_WAIT_SECONDS . ')',
            ['lock_name'=>$lockName]
        );
        if ($locked !== 1) {
            throw new RuntimeException('计划任务失败记录并发锁暂不可用。');
        }

        try {
            return $this->db->transaction(function (Database $db) use (
                $runId,
                $jobName,
                $businessKey,
                $businessVersion,
                $errorClass,
                $errorMessage,
                $initialDelayMinutes
            ): int {
                // Backoff is scoped to the current unresolved incident. A resolved
                // failure followed by a new incident must start again at attempt 1.
                $attempt = max(1, (int)$db->fetchValue(
                    "SELECT COALESCE(MAX(attempt_no),0)+1
                     FROM scheduled_job_failures
                     WHERE job_name=:job AND business_key=:key AND business_version=:version
                       AND resolved_at IS NULL",
                    ['job'=>$jobName,'key'=>$businessKey,'version'=>$businessVersion]
                ));
                $minutes = min(1440, $initialDelayMinutes * (1 << min(10, max(0, $attempt - 1))));
                $latest = $db->fetch(
                    "SELECT id
                     FROM scheduled_job_failures
                     WHERE job_name=:job AND business_key=:key AND business_version=:version
                       AND resolved_at IS NULL
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE",
                    ['job'=>$jobName,'key'=>$businessKey,'version'=>$businessVersion]
                );

                $params = [
                    'job'=>$jobName,
                    'key'=>$businessKey,
                    'version'=>$businessVersion,
                    'run_id'=>($runId !== null && $runId > 0) ? $runId : null,
                    'attempt'=>$attempt,
                    'error_class'=>substr(trim($errorClass) !== '' ? $errorClass : 'RuntimeException', 0, 191),
                    'error_message'=>function_exists('mb_substr')
                        ? mb_substr($errorMessage, 0, 4000, 'UTF-8')
                        : substr($errorMessage, 0, 4000),
                ];

                if ($latest) {
                    $keepId = (int)$latest['id'];
                    $db->execute(
                        "UPDATE scheduled_job_failures
                         SET resolved_at=UTC_TIMESTAMP()
                         WHERE job_name=:job AND business_key=:key AND business_version=:version
                           AND resolved_at IS NULL AND id<>:keep_id",
                        [
                            'job'=>$jobName,
                            'key'=>$businessKey,
                            'version'=>$businessVersion,
                            'keep_id'=>$keepId,
                        ]
                    );
                    $db->execute(
                        "UPDATE scheduled_job_failures
                         SET scheduler_run_id=:run_id,
                             attempt_no=:attempt,
                             error_class=:error_class,
                             error_message=:error_message,
                             next_retry_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$minutes} MINUTE),
                             created_at=UTC_TIMESTAMP()
                         WHERE id=:id AND resolved_at IS NULL",
                        $params + ['id'=>$keepId]
                    );
                    return $keepId;
                }

                return $db->insert(
                    "INSERT INTO scheduled_job_failures(
                        job_name,business_key,business_version,scheduler_run_id,attempt_no,
                        error_class,error_message,next_retry_at,created_at
                     ) VALUES(
                        :job,:key,:version,:run_id,:attempt,:error_class,:error_message,
                        DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$minutes} MINUTE),UTC_TIMESTAMP()
                     )",
                    $params
                );
            });
        } finally {
            try {
                $this->db->fetchValue('SELECT RELEASE_LOCK(:lock_name)', ['lock_name'=>$lockName]);
            } catch (Throwable $ignored) {
            }
        }
    }
}

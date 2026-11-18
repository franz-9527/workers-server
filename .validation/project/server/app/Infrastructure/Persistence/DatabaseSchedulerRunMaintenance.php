<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;

/**
 * scheduler_runs is an operational summary, not a business ledger.
 * Deleting old finished summaries never deletes failure facts or idempotency claims.
 */
final class DatabaseSchedulerRunMaintenance
{
    private const RETENTION_DAYS = [7, 15, 30, 60, 90, 180];
    private const BATCH_SIZE = 500;
    private const MAX_DELETE_PER_REQUEST = 10000;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function dashboard(): array
    {
        if (!$this->db->tableExists('scheduler_runs')) {
            return ['available'=>false];
        }

        $summary = $this->db->fetch(
            "SELECT COUNT(*) AS total_count,
                    SUM(CASE WHEN status='running' OR finished_at IS NULL THEN 1 ELSE 0 END) AS running_count,
                    SUM(CASE WHEN status<>'running' AND finished_at IS NOT NULL THEN 1 ELSE 0 END) AS completed_count,
                    MIN(started_at) AS oldest_at,
                    MAX(started_at) AS latest_at
             FROM scheduler_runs"
        ) ?: [];
        $jobs = $this->db->fetchAll(
            "SELECT job_name,COUNT(*) AS record_count,MIN(started_at) AS oldest_at,MAX(started_at) AS latest_at
             FROM scheduler_runs
             GROUP BY job_name"
        );
        foreach ($jobs as &$job) {
            $jobName = (string)($job['job_name'] ?? '');
            $job['job_label'] = SchedulerTaskCatalog::label($jobName);
        }
        unset($job);
        usort($jobs, static function (array $left, array $right): int {
            return strcmp((string)$left['job_label'], (string)$right['job_label'])
                ?: strcmp((string)$left['job_name'], (string)$right['job_name']);
        });

        $unresolvedFailures = $this->db->tableExists('scheduled_job_failures')
            ? (int)$this->db->fetchValue("SELECT COUNT(*) FROM scheduled_job_failures WHERE resolved_at IS NULL")
            : 0;
        $claimCount = $this->db->tableExists('scheduled_job_claims')
            ? (int)$this->db->fetchValue("SELECT COUNT(*) FROM scheduled_job_claims")
            : 0;

        return [
            'available'=>true,
            'total_count'=>(int)($summary['total_count'] ?? 0),
            'running_count'=>(int)($summary['running_count'] ?? 0),
            'completed_count'=>(int)($summary['completed_count'] ?? 0),
            'oldest_at'=>$summary['oldest_at'] ?? null,
            'latest_at'=>$summary['latest_at'] ?? null,
            'jobs'=>$jobs,
            'unresolved_failure_count'=>$unresolvedFailures,
            'claim_count'=>$claimCount,
        ];
    }

    public function purgeOlderThan(int $days, string $jobName = ''): array
    {
        if (!in_array($days, self::RETENTION_DAYS, true)) {
            throw new RuntimeException('请选择有效的数据库运行摘要保留时间。');
        }
        if ($jobName !== '' && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $jobName) !== 1) {
            throw new RuntimeException('计划任务类型无效。');
        }
        if (!$this->db->tableExists('scheduler_runs')) {
            throw new RuntimeException('数据库计划任务运行记录表不存在。');
        }

        $claimsExist = $this->db->tableExists('scheduled_job_claims');
        $failuresExist = $this->db->tableExists('scheduled_job_failures');
        $claimGuard = $claimsExist
            ? " AND NOT EXISTS (
                    SELECT 1 FROM scheduled_job_claims c
                    WHERE c.scheduler_run_id=scheduler_runs.id
                      AND c.status='claimed'
                      AND c.completed_at IS NULL
                )"
            : '';
        $jobs = $jobName !== ''
            ? [$jobName]
            : array_map(static function (array $row): string {
                return (string)$row['job_name'];
            }, $this->db->fetchAll("SELECT DISTINCT job_name FROM scheduler_runs ORDER BY job_name"));

        $deleted = 0;
        foreach ($jobs as $job) {
            do {
                $batchLimit = min(self::BATCH_SIZE, self::MAX_DELETE_PER_REQUEST - $deleted);
                if ($batchLimit < 1) break 2;
                // Lock each candidate and detach diagnostic pointers in the same
                // transaction as deletion. A failed DELETE must not partially erase links.
                $batch = $this->db->transaction(function (Database $db) use (
                    $job, $days, $batchLimit, $claimGuard, $claimsExist, $failuresExist
                ): int {
                    $ids = array_map('intval', array_column($db->fetchAll(
                        "SELECT id FROM scheduler_runs
                         WHERE job_name=:job
                           AND status<>'running'
                           AND finished_at IS NOT NULL
                           AND finished_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)
                           {$claimGuard}
                         ORDER BY id ASC
                         LIMIT {$batchLimit}
                         FOR UPDATE",
                        ['job'=>$job]
                    ), 'id'));
                    if (!$ids) return 0;
                    $idList = implode(',', $ids);
                    if ($failuresExist) {
                        $db->execute("UPDATE scheduled_job_failures SET scheduler_run_id=NULL WHERE scheduler_run_id IN ({$idList})");
                    }
                    if ($claimsExist) {
                        // The candidate query already excludes active claims; detach every
                        // remaining claim, including failed/incomplete historical states.
                        $db->execute("UPDATE scheduled_job_claims SET scheduler_run_id=NULL WHERE scheduler_run_id IN ({$idList})");
                    }
                    return $db->execute("DELETE FROM scheduler_runs WHERE id IN ({$idList})");
                });
                $deleted += $batch;
            } while ($batch === $batchLimit && $deleted < self::MAX_DELETE_PER_REQUEST);
            if ($deleted >= self::MAX_DELETE_PER_REQUEST) break;
        }

        $where = "status<>'running' AND finished_at IS NOT NULL AND finished_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)";
        if ($claimsExist) {
            $where .= " AND NOT EXISTS (
                SELECT 1 FROM scheduled_job_claims c
                WHERE c.scheduler_run_id=scheduler_runs.id
                  AND c.status='claimed'
                  AND c.completed_at IS NULL
            )";
        }
        $params = [];
        if ($jobName !== '') {
            $where .= ' AND job_name=:job';
            $params['job'] = $jobName;
        }
        $remaining = (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM scheduler_runs WHERE ' . $where,
            $params
        );

        return [
            'count'=>$deleted,
            'days'=>$days,
            'job_name'=>$jobName,
            'remaining_count'=>$remaining,
        ];
    }
}

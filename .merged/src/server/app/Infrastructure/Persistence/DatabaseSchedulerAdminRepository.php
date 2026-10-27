<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\SchedulerAdminRepository;
use WorkerAdmin\Core\Database;

final class DatabaseSchedulerAdminRepository implements SchedulerAdminRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function recentRuns(int $limit = 300): array
    {
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAll(
            'SELECT id,run_uuid,job_name,status,host_name,process_id,scanned_count,success_count,failure_count,started_at,finished_at,error_message '
            . 'FROM scheduler_runs ORDER BY id DESC LIMIT ' . $limit
        );
    }

    public function unresolvedFailures(int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        return $this->db->fetchAll(
            'SELECT id,job_name,business_key,business_version,scheduler_run_id,attempt_no,error_class,error_message,next_retry_at,created_at '
            . 'FROM scheduled_job_failures WHERE resolved_at IS NULL ORDER BY id DESC LIMIT ' . $limit
        );
    }

    public function unresolvedFailureCount(): int
    {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM scheduled_job_failures WHERE resolved_at IS NULL'
        );
    }

    public function failure(int $id): ?array
    {
        if ($id < 1) return null;
        return $this->db->fetch(
            'SELECT id,job_name,business_key,business_version,error_message,next_retry_at,resolved_at '
            . 'FROM scheduled_job_failures WHERE id=:id LIMIT 1',
            ['id'=>$id]
        );
    }

    public function makeFailureRetryable(int $id): bool
    {
        if ($id < 1) return false;
        return $this->db->execute(
            'UPDATE scheduled_job_failures SET next_retry_at=UTC_TIMESTAMP() WHERE id=:id AND resolved_at IS NULL',
            ['id'=>$id]
        ) === 1;
    }
}

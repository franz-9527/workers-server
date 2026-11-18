<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;

/**
 * 计划任务唯一执行门禁。
 *
 * 升级器先取得 wall_worker_admin_upgrade，再创建 maintenance.lock；
 * 调度器按相同顺序取得升级锁和全局调度锁，确保检查与业务执行之间不存在升级竞态。
 */
final class SchedulerExecutionGuard
{
    private const SCHEDULER_LOCK = 'worker_order_scheduler_v1';
    private const UPGRADE_LOCK = 'wall_worker_admin_upgrade';

    private Database $db;
    private bool $schedulerLockAcquired = false;
    private bool $upgradeGuardAcquired = false;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param callable $operation 在两把命名锁保护范围内执行的完整调度编排
     * @return array<string,mixed>
     */
    public function run(string $task, callable $operation): array
    {
        if ($this->maintenanceActive()) {
            return $this->skippedResult($task, 'skipped_maintenance', '系统处于升级维护状态，本次计划任务已安全跳过。');
        }
        if (!$this->acquireLock()) {
            return $this->skippedResult($task, 'skipped_locked', '已有升级或计划任务实例正在运行，本次安全跳过。');
        }

        try {
            // 取得升级互斥锁后再次检查维护文件，关闭检查与执行之间的竞态窗口。
            if ($this->maintenanceActive()) {
                return $this->skippedResult($task, 'skipped_maintenance', '系统处于升级维护状态，本次计划任务已安全跳过。');
            }
            $this->assertSchemaReady();
            $this->closeStaleRuns();
            return $operation();
        } finally {
            $this->releaseLock();
        }
    }

    private function maintenanceActive(): bool
    {
        $root = defined('WORKER_BASE_PATH') ? WORKER_BASE_PATH : dirname(__DIR__, 3);
        return is_file(rtrim($root, '/\\') . '/storage/maintenance.lock');
    }

    private function skippedResult(string $task, string $status, string $message): array
    {
        \write_log('scheduler', '[' . date('c') . '] task=' . $task . ' status=' . $status . "\n");
        return ['status'=>$status,'task'=>$task,'message'=>$message,'runs'=>[]];
    }

    private function assertSchemaReady(): void
    {
        foreach (['orders','scheduler_runs','scheduled_job_claims','scheduled_job_failures','order_events','message_outbox','user_wallets','user_wallet_ledger','user_wallet_reservations','order_worker_sms_batches','order_worker_sms_invites'] as $table) {
            if (!$this->db->tableExists($table)) {
                throw new RuntimeException('计划任务依赖数据表缺失：' . $table);
            }
        }
    }

    private function closeStaleRuns(): void
    {
        $this->db->execute(
            "UPDATE scheduler_runs SET status='failed',finished_at=UTC_TIMESTAMP(),error_message='上次进程异常退出或运行超时。' WHERE status='running' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR)"
        );
    }

    private function acquireLock(): bool
    {
        $statement = $this->db->pdo()->prepare('SELECT GET_LOCK(:lock_name,0)');
        $statement->execute(['lock_name'=>self::UPGRADE_LOCK]);
        $this->upgradeGuardAcquired = (int)$statement->fetchColumn() === 1;
        if (!$this->upgradeGuardAcquired) {
            return false;
        }

        $statement = $this->db->pdo()->prepare('SELECT GET_LOCK(:lock_name,0)');
        $statement->execute(['lock_name'=>self::SCHEDULER_LOCK]);
        $this->schedulerLockAcquired = (int)$statement->fetchColumn() === 1;
        if (!$this->schedulerLockAcquired) {
            $this->releaseNamedLock(self::UPGRADE_LOCK);
            $this->upgradeGuardAcquired = false;
            return false;
        }
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->schedulerLockAcquired) {
            $this->releaseNamedLock(self::SCHEDULER_LOCK);
            $this->schedulerLockAcquired = false;
        }
        if ($this->upgradeGuardAcquired) {
            $this->releaseNamedLock(self::UPGRADE_LOCK);
            $this->upgradeGuardAcquired = false;
        }
    }

    private function releaseNamedLock(string $name): void
    {
        try {
            $statement = $this->db->pdo()->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name'=>$name]);
        } catch (Throwable $exception) {
            \write_log('scheduler-error', '[' . date('c') . '] release_lock ' . $exception->getMessage() . "\n");
        }
    }
}

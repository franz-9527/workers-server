<?php

namespace WorkerAdmin\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Scheduling\AssignmentTimeoutProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoAcceptanceProcessor;
use WorkerAdmin\Infrastructure\Scheduling\MeasurementAutoAcceptanceProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoReviewProcessor;
use WorkerAdmin\Infrastructure\Scheduling\AutoCancelProcessor;
use WorkerAdmin\Infrastructure\Scheduling\RepairEscalationReminderTask;
use WorkerAdmin\Infrastructure\Messaging\LegacyOrderNotificationAdapter;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderSettlementWriter;
use WorkerAdmin\Infrastructure\Persistence\PlatformOrderFrozenBalanceReleaser;

class SchedulerService
{
    private $db;
    private $lockName = 'worker_order_scheduler_v1';
    private $upgradeLockName = 'wall_worker_admin_upgrade';
    private $schedulerLockAcquired = false;
    private $upgradeGuardAcquired = false;
    private $limit;

    public function __construct(Database $db, $limit = 100)
    {
        $this->db = $db;
        $this->limit = max(1, min(500, (int) $limit));
    }

    // 2026-08-14：返回 CLI 可使用的标准任务名，all 会依次执行全部任务。
    public function supportedTasks()
    {
        return ['auto_accept', 'assignment_timeout', 'supplement_timeout', 'repair_escalation', 'commission', 'auto_review', 'auto_cancel', 'draft_cleanup', 'media_cleanup', 'sms_dispatch', 'paid_sms_dispatch', 'paid_sms_receipt', 'wallet_reconcile', 'measurement_accept', 'measurement_release', 'platform_balance_release', 'all'];
    }

    // 2026-08-14：统一任务别名，便于宝塔中使用短横线或下划线参数。
    public function normalizeTask($task)
    {
        $task = strtolower(trim((string) $task));
        $aliases = [
            'accept' => 'auto_accept',
            'auto-accept' => 'auto_accept',
            'auto_accept' => 'auto_accept',
            'commission' => 'commission',
            'commissions' => 'commission',
            'review' => 'auto_review',
            'auto-review' => 'auto_review',
            'auto_review' => 'auto_review',
            'cancel' => 'auto_cancel',
            'auto-cancel' => 'auto_cancel',
            'auto_cancel' => 'auto_cancel',
            'draft-cleanup' => 'draft_cleanup',
            'draft_cleanup' => 'draft_cleanup',
            'media-cleanup' => 'media_cleanup',
            'media_cleanup' => 'media_cleanup',
            'assignment-timeout' => 'assignment_timeout',
            'assignment_timeout' => 'assignment_timeout',
            'assignments-timeout' => 'assignment_timeout',
            'supplement-timeout' => 'supplement_timeout',
            'supplement_timeout' => 'supplement_timeout',
            'repair-escalation' => 'repair_escalation',
            'repair_escalation' => 'repair_escalation',
            'sms' => 'sms_dispatch',
            'sms-dispatch' => 'sms_dispatch',
            'sms_dispatch' => 'sms_dispatch',
            'paid-sms-dispatch' => 'paid_sms_dispatch',
            'paid_sms_dispatch' => 'paid_sms_dispatch',
            'paid-sms-receipt' => 'paid_sms_receipt',
            'paid_sms_receipt' => 'paid_sms_receipt',
            'wallet-reconcile' => 'wallet_reconcile',
            'wallet_reconcile' => 'wallet_reconcile',
            // V2.2.9：测量成果自动确认与冻结释放都走统一调度。
            'measurement-accept' => 'measurement_accept',
            'measurement_accept' => 'measurement_accept',
            'measurement-release' => 'measurement_release',
            'measurement_release' => 'measurement_release',
            'platform-balance-release' => 'platform_balance_release',
            'platform_balance_release' => 'platform_balance_release',
            'all' => 'all',
        ];
        if (!isset($aliases[$task])) {
            throw new InvalidArgumentException('未知计划任务：' . $task);
        }
        return $aliases[$task];
    }

    // 2026-08-14：使用 MySQL GET_LOCK 保证同一数据库同一时刻只有一个调度实例。
    public function run($task, $force = false)
    {
        $task = $this->normalizeTask($task);
        $force = (bool) $force;
        if ($this->maintenanceActive()) {
            return $this->skippedResult($task, 'skipped_maintenance', '系统处于升级维护状态，本次计划任务已安全跳过。');
        }
        if (!$this->acquireLock()) {
            return $this->skippedResult($task, 'skipped_locked', '已有升级或计划任务实例正在运行，本次安全跳过。');
        }

        try {
            // 2026-08-14：取得升级互斥锁后再次检查文件锁，关闭检查与执行之间的竞态窗口。
            if ($this->maintenanceActive()) {
                return $this->skippedResult($task, 'skipped_maintenance', '系统处于升级维护状态，本次计划任务已安全跳过。');
            }
            $this->assertSchemaReady();
            $this->closeStaleRuns();
            $tasks = $task === 'all'
                ? ['assignment_timeout', 'supplement_timeout', 'repair_escalation', 'auto_accept', 'commission', 'auto_review', 'auto_cancel', 'measurement_accept', 'measurement_release', 'platform_balance_release', 'draft_cleanup', 'media_cleanup', 'sms_dispatch', 'paid_sms_dispatch', 'paid_sms_receipt', 'wallet_reconcile']
                : [$task];
            $runs = [];
            $hasFailure = false;
            foreach ($tasks as $name) {
                $result = $this->runOne($name, $force && $task !== 'all');
                $runs[] = $result;
                if ($result['status'] !== 'success') {
                    $hasFailure = true;
                }
            }
            return [
                'status' => $hasFailure ? 'failed' : 'success',
                'task' => $task,
                'message' => $hasFailure ? '部分计划任务执行失败，请查看运行日志。' : '计划任务执行完成。',
                'runs' => $runs,
            ];
        } finally {
            $this->releaseLock();
        }
    }

    // 2026-08-14：每类任务单独写 scheduler_runs，便于后台按任务定位失败原因。
    private function runOne($task, $force = false)
    {
        $runUuid = $this->uuidV4();
        $runId = $this->db->insert(
            "INSERT INTO scheduler_runs(run_uuid,job_name,status,host_name,process_id,scanned_count,success_count,failure_count,started_at) VALUES (:uuid,:job,'running',:host,:pid,0,0,0,UTC_TIMESTAMP())",
            [
                'uuid' => $runUuid,
                'job' => $task,
                'host' => substr((string) gethostname(), 0, 128),
                'pid' => function_exists('getmypid') ? (int) getmypid() : null,
            ]
        );
        $startedAt = microtime(true);
        $stats = ['scanned' => 0, 'success' => 0, 'failure' => 0];
        $error = null;

        try {
            if ($task === 'auto_accept') {
                $stats = $this->runAutoAccept($runId);
            } elseif ($task === 'assignment_timeout') {
                $stats = $this->runAssignmentTimeout($runId);
            } elseif ($task === 'supplement_timeout') {
                $stats = $this->runSupplementTimeout($runId);
            } elseif ($task === 'repair_escalation') {
                $stats = $this->runRepairEscalation($runId);
            } elseif ($task === 'commission') {
                $stats = $this->runCommission($runId);
            } elseif ($task === 'auto_review') {
                $stats = $this->runAutoReview($runId);
            } elseif ($task === 'auto_cancel') {
                $stats = $this->runAutoCancel($runId);
            } elseif ($task === 'draft_cleanup') {
                $stats = $this->runDraftCleanup($runId);
            } elseif ($task === 'media_cleanup') {
                $stats = $this->runMediaCleanup($runId, $force);
            } elseif ($task === 'sms_dispatch') {
                $stats = $this->runSmsDispatch();
            } elseif ($task === 'paid_sms_dispatch') {
                $stats = (new \WorkerAdmin\Foundation\MessagingApplicationContainer($this->db))->paidSms()->dispatchPending($this->limit);
            } elseif ($task === 'paid_sms_receipt') {
                $stats = (new \WorkerAdmin\Foundation\MessagingApplicationContainer($this->db))->paidSms()->processReceipts($this->limit);
            } elseif ($task === 'wallet_reconcile') {
                $stats = $this->runWalletReconcile($runId, $force);
            } elseif ($task === 'measurement_accept') {
                $stats = $this->runUnifiedMeasurementAutoAccept($runId);
            } elseif ($task === 'measurement_release') {
                $stats = $this->runUnifiedMeasurementRelease($runId);
            } elseif ($task === 'platform_balance_release') {
                $stats = (new PlatformOrderFrozenBalanceReleaser())->run(
                    $this->db, $this->limit,
                    function (int $orderId, int $version, Throwable $error) use ($runId): void {
                        $this->recordFailure($runId, 'platform_balance_release', 'order:'.$orderId, $version, $error);
                    }
                );
            } else {
                throw new RuntimeException('未实现的计划任务：' . $task);
            }
        } catch (Throwable $exception) {
            $stats['failure']++;
            $error = $exception->getMessage();
            $this->recordFailure($runId, $task, 'task:' . $task, 1, $exception);
        }

        if ($error === null && !empty($stats['error'])) {
            $error = mb_substr((string) $stats['error'], 0, 4000);
        }
        if ($error === null && $stats['failure'] > 0) {
            $error = '本批次有 ' . (int) $stats['failure'] . ' 笔业务处理失败，请查看 scheduled_job_failures。';
        }
        $status = $error === null && $stats['failure'] === 0 ? 'success' : 'failed';

        // 任务级异常（例如 SQL/配置错误）在下一次完整成功后自动关闭；
        // 逐笔业务失败仍由各任务自己的成功条件单独关闭，避免误把真实业务异常消掉。
        if ($status === 'success') {
            $this->db->execute(
                "UPDATE scheduled_job_failures
                 SET resolved_at=UTC_TIMESTAMP()
                 WHERE job_name=:job_name
                   AND business_key=:business_key
                   AND resolved_at IS NULL",
                ['job_name'=>$task,'business_key'=>'task:'.$task]
            );
        }

        $this->db->execute(
            "UPDATE scheduler_runs SET status=:status,scanned_count=:scanned,success_count=:success,failure_count=:failure,finished_at=UTC_TIMESTAMP(),error_message=:error WHERE id=:id",
            [
                'status' => $status,
                'scanned' => $stats['scanned'],
                'success' => $stats['success'],
                'failure' => $stats['failure'],
                'error' => $error,
                'id' => $runId,
            ]
        );

        // 2026-09-29：scheduler_runs 是运行诊断日志，不是订单/资金业务台账。
        // “扫描到了候选但没有真正处理”仍属于空心跳；同一任务只保留最新一条。
        // 历史 wallet_reconcile / repair_escalation 曾把“巡检通过/重复提醒”误记为 success_count，
        // 这里一并收敛；失败运行每个任务只保留最近 100 条，具体失败事实仍在 scheduled_job_failures。
        $this->pruneSchedulerRunHistory($task, $runId, $status, $stats);

        $result = [
            'run_id' => $runId,
            'run_uuid' => $runUuid,
            'task' => $task,
            'status' => $status,
            'scanned' => $stats['scanned'],
            'success' => $stats['success'],
            'failure' => $stats['failure'],
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $error,
        ];
        $this->writeRunLog($result);
        return $result;
    }

    /** V2.2.5：维修提交即自动进入 processing；48 小时未完成只提醒用户可申请平台介入，不再由系统替任何一方自动升级。 */
    private function runRepairEscalation($runId)
    {
        $task = new RepairEscalationReminderTask($this->db, new LegacyOrderNotificationAdapter($this->db), $this->limit);
        return $task->run(function (int $id,int $version,Throwable $exception) use ($runId):void {
            $this->recordFailure($runId,'repair_escalation','after_sale:'.$id,$version,$exception);
        });
    }

    private function runSmsDispatch()
    {
        return (new \WorkerAdmin\Foundation\MessagingApplicationContainer($this->db))->dispatcher()->dispatch($this->limit);
    }

    /** 2026-09-15：7 天未付且从未建立支付单的订单草稿归档，并解除其商品图/现场图引用。 */
    private function runDraftCleanup($runId)
    {
        $rows = $this->db->fetchAll("SELECT o.id,o.version FROM orders o WHERE COALESCE(o.is_demo,0)=0 AND o.audit_status='draft' AND o.lifecycle_status='active' AND o.payment_status='unpaid' AND o.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND NOT EXISTS (SELECT 1 FROM payment_orders p WHERE p.order_id=o.id) ORDER BY o.id LIMIT " . $this->limit);
        $stats = ['scanned' => count($rows), 'success' => 0, 'failure' => 0];
        foreach ($rows as $row) {
            try {
                $archived = $this->db->transaction(function (Database $db) use ($row): bool {
                    $order = $db->fetch("SELECT id,public_no,version FROM orders WHERE id=:id AND COALESCE(is_demo,0)=0 AND audit_status='draft' AND lifecycle_status='active' AND payment_status='unpaid' AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) FOR UPDATE", ['id' => $row['id']]);
                    if (!$order || $db->fetchValue('SELECT id FROM payment_orders WHERE order_id=:id LIMIT 1', ['id' => $row['id']])) return false;
                    $media = $db->fetchAll("SELECT media_file_id,COUNT(*) link_count FROM media_links WHERE entity_type='order' AND entity_id=:id AND purpose IN ('product','wall') GROUP BY media_file_id", ['id' => $row['id']]);
                    $db->execute("DELETE FROM media_links WHERE entity_type='order' AND entity_id=:id AND purpose IN ('product','wall')", ['id' => $row['id']]);
                    foreach ($media as $item) {
                        $count = (int) $item['link_count'];
                        $db->execute("UPDATE media_files SET status=IF(reference_count<=:pending_count,'pending_delete',status),pending_delete_at=IF(reference_count<=:delete_count,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),pending_delete_at),reference_count=IF(reference_count>=:subtract_count,reference_count-:subtract_again,0) WHERE id=:media_id", ['pending_count' => $count, 'delete_count' => $count, 'subtract_count' => $count, 'subtract_again' => $count, 'media_id' => $item['media_file_id']]);
                    }
                    $db->execute("UPDATE orders SET lifecycle_status='archived',audit_status='rejected',audit_note='未付款草稿超过 7 天，系统已自动归档',publish_status='hidden',quote_status='cancelled',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['id' => $row['id']]);
                    $this->recordOrderEvent($db, (int) $row['id'], 'draft_archived', 'draft_cleanup', (int) $order['version'], ['message' => '未付款订单草稿超过 7 天，系统已自动归档并释放未使用图片。']);
                    return true;
                });
                if ($archived) $stats['success']++;
            } catch (Throwable $exception) {
                $stats['failure']++;
                $this->recordFailure($runId, 'draft_cleanup', 'order:' . (int) $row['id'], (int) $row['version'], $exception);
            }
        }
        return $stats;
    }

    /** 2026-09-15：清理至少等待 24 小时、零引用且没有 media_links 的本站/七牛媒体。 */
    private function runMediaCleanup($runId, $force = false)
    {
        $this->resolveStaleMediaCleanupFailures();
        $rows = $this->db->fetchAll(
            "SELECT m.id,m.public_id,m.storage_disk,m.storage_key
             FROM media_files m
             WHERE m.reference_count=0
               AND NOT EXISTS (SELECT 1 FROM media_links l WHERE l.media_file_id=m.id)
               AND ((m.status='pending_delete' AND m.pending_delete_at<=UTC_TIMESTAMP())
                    OR (m.status='active' AND m.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)))
               " . ($force ? "" : "AND NOT EXISTS (
                   SELECT 1 FROM scheduled_job_failures f
                   WHERE f.job_name='media_cleanup'
                     AND f.business_key=CONCAT('media:',m.id)
                     AND f.business_version=1
                     AND f.resolved_at IS NULL
                     AND f.next_retry_at>UTC_TIMESTAMP()
               )") . "
             ORDER BY m.id
             LIMIT " . $this->limit
        );
        $stats = ['scanned' => count($rows), 'success' => 0, 'failure' => 0, 'error' => null];
        foreach ($rows as $row) {
            $claimed = false;
            try {
                $claimed = $this->db->transaction(function (Database $db) use ($row): bool {
                    $media = $db->fetch("SELECT id FROM media_files WHERE id=:id AND reference_count=0 AND NOT EXISTS (SELECT 1 FROM media_links l WHERE l.media_file_id=media_files.id) AND ((status='pending_delete' AND pending_delete_at<=UTC_TIMESTAMP()) OR (status='active' AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY))) FOR UPDATE", ['id' => $row['id']]);
                    if (!$media) return false;
                    return $db->execute("UPDATE media_files SET status='deleting' WHERE id=:id AND reference_count=0", ['id' => $row['id']]) === 1;
                });
                if (!$claimed) continue;

                if ((string) $row['storage_disk'] === 'qiniu') {
                    (new QiniuStorageService())->delete((string) $row['storage_key']);
                } elseif ((string) $row['storage_disk'] === 'private_local') {
                    $this->deletePrivateMediaFile((string) $row['storage_key']);
                } else {
                    throw new RuntimeException('不支持的图片存储类型，已保留数据库记录供管理员检查。');
                }

                $deleted = $this->db->execute("UPDATE media_files SET status='deleted',deleted_at=UTC_TIMESTAMP(),pending_delete_at=NULL WHERE id=:id AND status='deleting' AND reference_count=0 AND NOT EXISTS (SELECT 1 FROM media_links l WHERE l.media_file_id=media_files.id)", ['id' => $row['id']]);
                if ($deleted === 1) {
                    $stats['success']++;
                    $this->db->execute(
                        "UPDATE scheduled_job_failures
                         SET resolved_at=UTC_TIMESTAMP()
                         WHERE job_name='media_cleanup'
                           AND business_key=:business_key
                           AND business_version=1
                           AND resolved_at IS NULL",
                        ['business_key' => 'media:' . (int) $row['id']]
                    );
                }
            } catch (Throwable $exception) {
                if ($claimed) $this->db->execute("UPDATE media_files SET status='pending_delete',pending_delete_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=:id AND status='deleting' AND reference_count=0", ['id' => $row['id']]);
                $stats['failure']++;
                $stats['error'] = '媒体 ' . (string) $row['public_id'] . '：' . $exception->getMessage();
                $businessKey = 'media:' . (int) $row['id'];
                // 同一媒体只保留一条“当前未解决”失败；历史尝试保留为已解决记录供追溯。
                $this->db->execute(
                    "UPDATE scheduled_job_failures
                     SET resolved_at=UTC_TIMESTAMP()
                     WHERE job_name='media_cleanup'
                       AND business_key=:business_key
                       AND business_version=1
                       AND resolved_at IS NULL",
                    ['business_key' => $businessKey]
                );
                $this->recordFailure($runId, 'media_cleanup', $businessKey, 1, $exception);
                \write_log('media-cleanup', '[' . date('c') . '] media=' . (string) $row['public_id'] . ' ' . $exception->getMessage() . "\n");
            }
        }
        return $stats;
    }

    /** 2026-09-15：本站私有图片物理删除前解析真实路径并确认目标仍位于 storage/private 内。 */
    private function deletePrivateMediaFile($storageKey)
    {
        $key = str_replace('\\', '/', trim((string) $storageKey));
        if ($key === '' || strpos($key, "\0") !== false || $key[0] === '/' || preg_match('/^[A-Za-z]:\//', $key) === 1 || preg_match('#(^|/)\.\.(/|$)#', $key) === 1) throw new RuntimeException('本站图片路径无效，已停止删除。');
        if (strpos($key, 'storage/private/') === 0) $key = substr($key, 16);
        $root = realpath(\base_path('storage/private'));
        if ($root === false) throw new RuntimeException('本站私有图片目录不可读取，已暂停清理。');
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key));
        if ($path === false) return; // 文件已不存在，按幂等清理成功处理。
        $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        if (strpos($path, $prefix) !== 0 || !is_file($path)) throw new RuntimeException('解析后的图片不在本站私有目录，已停止删除。');
        if (!@unlink($path) && is_file($path)) throw new RuntimeException('本站图片删除失败，请检查 storage/private 目录权限。');
    }

    // 2026-09-14：派单 24 小时内必须接受或拒绝；逾期指派进入 expired，订单释放回未派单状态。
    private function runAssignmentTimeout($runId)
    {
        $rows = $this->db->fetchAll(
            "SELECT a.id,a.order_id,o.version
             FROM assignments a
             JOIN orders o ON o.id=a.order_id
             WHERE COALESCE(o.is_demo,0)=0
               AND a.status='offered'
               AND COALESCE(a.accept_deadline_at,DATE_ADD(a.offered_at,INTERVAL 24 HOUR))<=UTC_TIMESTAMP()
             ORDER BY a.id ASC
             LIMIT " . $this->limit
        );
        $stats = ['scanned' => count($rows), 'success' => 0, 'failure' => 0];
        $processor = new AssignmentTimeoutProcessor($this->db);
        foreach ($rows as $row) {
            try {
                if ($processor->expireProduction((int)$row['id'])) $stats['success']++;
            } catch (Throwable $exception) {
                $stats['failure']++;
                $this->recordFailure($runId, 'assignment_timeout', 'assignment:' . (int) $row['id'], (int) $row['version'], $exception);
            }
        }
        return $stats;
    }

    // 2026-09-14：待补资料不设审核时限，但设补资料处理时限；临近到期提醒，超时自动取消并进入退款流程。
    private function runSupplementTimeout($runId)
    {
        $reminderHours = max(1, min(168, (int) \setting('order.supplement_reminder_hours', 24)));
        $rows = $this->db->fetchAll(
            "SELECT d.id,d.order_id,d.version,d.due_at,d.reminded_at,o.public_no,o.user_id,o.version order_version FROM order_deadlines d JOIN orders o ON o.id=d.order_id WHERE COALESCE(o.is_demo,0)=0 AND d.deadline_type='audit_supplement_timeout' AND d.status='pending' AND o.audit_status='supplement_required' AND o.lifecycle_status='active' AND d.due_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL " . $reminderHours . " HOUR) ORDER BY d.due_at,d.id LIMIT " . $this->limit
        );
        $stats = ['scanned' => count($rows), 'success' => 0, 'failure' => 0];
        foreach ($rows as $row) {
            try {
                $dueTimestamp = (new \DateTimeImmutable((string) $row['due_at'], new \DateTimeZone('UTC')))->getTimestamp();
                if ($dueTimestamp > time()) {
                    $reminded = $this->db->execute("UPDATE order_deadlines SET reminded_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending' AND reminded_at IS NULL", ['id' => $row['id']]);
                    if ($reminded === 1) {
                        $this->db->transaction(function (Database $db) use ($row): void {
                            $this->recordOrderEvent($db, (int) $row['order_id'], 'supplement_deadline_reminder', 'supplement_timeout', (int) $row['order_version'], ['message' => '订单补资料期限即将到期，请尽快补充资料，否则订单将取消并退款。', 'due_at' => $row['due_at']]);
                        });
                    }
                    continue;
                }
                $changed = $this->db->transaction(function (Database $db) use ($row): bool {
                    $deadline = $db->fetch("SELECT * FROM order_deadlines WHERE id=:id AND status='pending' AND due_at<=UTC_TIMESTAMP() FOR UPDATE", ['id' => $row['id']]);
                    $order = $db->fetch("SELECT * FROM orders WHERE id=:id AND COALESCE(is_demo,0)=0 FOR UPDATE", ['id' => $row['order_id']]);
                    if (!$deadline || !$order || $order['lifecycle_status'] !== 'active' || $order['audit_status'] !== 'supplement_required') return false;
                    $payment = $db->fetch("SELECT id,amount_cents FROM payment_orders WHERE order_id=:id AND business_type IN ('order_booking','booking_fee') AND status='paid' ORDER BY id DESC LIMIT 1 FOR UPDATE", ['id' => $order['id']]);
                    $refundAmount = $payment ? (int) $payment['amount_cents'] : 0;
                    if ($payment) {
                        $allocated = (int) $db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE payment_order_id=:id AND status NOT IN ('failed','closed','cancelled','rejected')", ['id' => $payment['id']]);
                        $refundAmount = max(0, $refundAmount - $allocated);
                    }
                    $db->execute("UPDATE orders SET audit_status='rejected',audit_note='补充资料超时，订单自动取消并退款',supplement_due_at=NULL,lifecycle_status=IF(:refund_lifecycle>0,'cancel_approved_pending_refund','cancelled'),publish_status='hidden',quote_status='cancelled',assignment_status='cancelled',fulfillment_status='cancelled',refund_status=IF(:refund_status>0,'processing','none'),cancelled_at=UTC_TIMESTAMP(),closed_at=IF(:refund_close>0,closed_at,COALESCE(closed_at,UTC_TIMESTAMP())),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['refund_lifecycle' => $refundAmount, 'refund_status' => $refundAmount, 'refund_close' => $refundAmount, 'id' => $order['id']]);
                    $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id' => $order['id']]);
                    $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id' => $order['id']]);
                    $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id' => $order['id']]);
                    $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'", ['id' => $order['id']]);
                    $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id' => $order['id']]);
                    $requestId = $db->insert("INSERT INTO order_cancel_requests(public_id,order_id,applicant_type,applicant_id,request_type,order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,requested_at,closed_at,created_at,updated_at) VALUES(:public_id,:order_id,'system',NULL,'supplement_timeout',:snapshot,'supplement_timeout','补充资料超时，系统自动取消','approved',:refund,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", ['public_id' => \public_id(), 'order_id' => $order['id'], 'snapshot' => json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'refund' => $refundAmount]);
                    $refundId = null;
                    if ($payment && $refundAmount > 0) {
                        $refundId = $db->insert("INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_at,created_at,updated_at) VALUES(:public_id,:payment_id,:order_id,:request_id,:amount,'补充资料超时自动退款','pending_review',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())", ['public_id' => \business_no('RF'), 'payment_id' => $payment['id'], 'order_id' => $order['id'], 'request_id' => $requestId, 'amount' => $refundAmount]);
                    }
                    $db->insert("INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,reason,refund_amount_cents,run_empty_fee_cents,refund_id,decided_at,created_at) VALUES(:request_id,:order_id,'approved','platform','补充资料超时，系统自动取消',:refund,0,:refund_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())", ['request_id' => $requestId, 'order_id' => $order['id'], 'refund' => $refundAmount, 'refund_id' => $refundId]);
                    $this->recordOrderEvent($db, (int) $order['id'], 'supplement_timeout_refunded', 'supplement_timeout', (int) $order['version'], ['refund_amount_cents' => $refundAmount, 'message' => '补充资料超时，订单已自动取消并进入退款流程。']);
                    return true;
                });
                if ($changed) $stats['success']++;
            } catch (Throwable $exception) {
                $stats['failure']++;
                $this->recordFailure($runId, 'supplement_timeout', 'order:' . (int) $row['order_id'], (int) $row['order_version'], $exception);
            }
        }
        $this->submitAutomaticRefunds();
        $this->syncProcessingRefunds();
        return $stats;
    }

    // 2026-08-14：完工提交满 72 小时且无未决阻断项时自动验收。
    private function runAutoAccept($runId)
    {
        $rows = $this->db->fetchAll(
            "SELECT o.id,o.version
             FROM orders o
             WHERE o.lifecycle_status='active'
               AND o.completion_submitted_at IS NOT NULL
               AND o.accepted_at IS NULL
               AND COALESCE(o.auto_accept_at,DATE_ADD(o.completion_submitted_at,INTERVAL 72 HOUR))<=UTC_TIMESTAMP()
               AND EXISTS(
                   SELECT 1 FROM media_links ml
                   JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
                   WHERE ml.entity_type='order' AND ml.entity_id=o.id AND ml.purpose='collection_receipt'
               )
               AND NOT EXISTS(
                   SELECT 1 FROM scheduled_job_failures f
                   WHERE f.job_name='auto_accept_completion'
                     AND f.business_key=CONCAT('order:',o.id)
                     AND f.business_version=o.version
                     AND f.resolved_at IS NULL
                     AND f.next_retry_at>UTC_TIMESTAMP()
               )"
                . $this->demoOrderFilterSql() . " ORDER BY o.id ASC LIMIT " . $this->limit
        );
        $processor=new AutoAcceptanceProcessor($this->db);
        return $this->processRows($runId,'auto_accept_completion',$rows,function($orderId,$candidateVersion)use($processor,$runId){
            return $processor->acceptProduction(
                (int)$orderId,
                (int)$candidateVersion,
                (int)$runId,
                function(Database $db,int $id):void{$this->finalizeCommissionReceivable($db,$id);}
            );
        });
    }

    // 2026-08-14：扫描手动或自动验收但尚未生成服务费应收的订单。
    private function runCommission($runId)
    {
        // 2026-09-09：逐笔原子标记新逾期服务费，并只为首次转逾期的账单生成一条管理员企业微信提醒。
        $overdueRows=$this->db->fetchAll(
            "SELECT c.id,c.order_id,c.amount_due_cents,c.amount_paid_cents,c.due_at,o.public_no,w.display_name worker_name FROM commission_receivables c JOIN orders o ON o.id=c.order_id LEFT JOIN worker_profiles w ON w.id=c.worker_id WHERE c.status='pending' AND c.amount_paid_cents<c.amount_due_cents AND c.due_at<UTC_TIMESTAMP()"
                . $this->demoOrderFilterSql() . " ORDER BY c.due_at,c.id LIMIT " . $this->limit
        );
        foreach($overdueRows as $overdue){
            $this->db->transaction(function(Database $db)use($overdue){
                $changed=$db->execute("UPDATE commission_receivables SET status='overdue',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending' AND amount_paid_cents<amount_due_cents AND due_at<UTC_TIMESTAMP()",['id'=>$overdue['id']]);
                if($changed<1)return;
                $unpaid=max(0,(int)$overdue['amount_due_cents']-(int)$overdue['amount_paid_cents']);
                OrderNotificationService::queueAdminTask($db,'commission-overdue:'.$overdue['id'],'commission_overdue','平台服务费已逾期',['订单号'=>(string)$overdue['public_no'],'师傅'=>(string)($overdue['worker_name']??''),'未缴金额'=>number_format($unpaid/100,2,'.','').'元','到期时间'=>(string)$overdue['due_at']]);
            });
        }
        $rows = $this->db->fetchAll(
            "SELECT o.id,o.version FROM orders o WHERE o.accepted_at IS NOT NULL AND o.assigned_worker_id IS NOT NULL AND o.final_amount_cents>0 AND COALESCE(o.order_source_type,'consumer')<>'platform' AND (NOT EXISTS(SELECT 1 FROM commission_receivables c WHERE c.order_id=o.id) OR EXISTS(SELECT 1 FROM commission_receivables c2 WHERE c2.order_id=o.id AND c2.is_provisional=1)) AND NOT EXISTS(SELECT 1 FROM scheduled_job_failures f WHERE f.job_name='generate_commission_receivable' AND f.business_key=CONCAT('order:',o.id) AND f.business_version=o.version AND f.resolved_at IS NULL AND f.next_retry_at>UTC_TIMESTAMP())"
                . $this->demoOrderFilterSql() . " ORDER BY o.id ASC LIMIT " . $this->limit
        );
        return $this->processRows($runId, 'generate_commission_receivable', $rows, function ($orderId, $candidateVersion) use ($runId) {
            return $this->db->transaction(function (Database $db) use ($orderId, $candidateVersion, $runId) {
                $order = $db->fetch(
                    "SELECT * FROM orders WHERE id=:id AND COALESCE(is_demo,0)=0 AND accepted_at IS NOT NULL AND assigned_worker_id IS NOT NULL AND final_amount_cents>0 AND COALESCE(order_source_type,'consumer')<>'platform' FOR UPDATE",
                    ['id' => $orderId]
                );
                if (!$order || (int) $order['version'] !== (int) $candidateVersion) {
                    return false;
                }
                $businessVersion = max(1, (int) $order['version']);
                if (!$this->claim($db, $runId, 'generate_commission_receivable', $orderId, $businessVersion)) {
                    return false;
                }
                $receivable = $db->fetch('SELECT is_provisional FROM commission_receivables WHERE order_id=:id FOR UPDATE', ['id' => $orderId]);
                $created = $receivable ? $this->finalizeCommissionReceivable($db, $orderId) : $this->createCommissionReceivable($db, $orderId);
                if (!$created && !$receivable) {
                    throw new RuntimeException('订单缺少已接受指派或有效结算金额，无法生成平台服务费应收。');
                }
                $this->completeClaim($db, 'generate_commission_receivable', $orderId, $businessVersion, ['created' => true, 'finalized' => (bool) $receivable]);
                return true;
            });
        });
    }

    // 2026-08-14：验收满 5 天仍无评价且无未决阻断项时生成系统五星评价。
    private function runAutoReview($runId)
    {
        $rows=$this->db->fetchAll(
            "SELECT o.id,o.version
             FROM orders o
             WHERE o.accepted_at IS NOT NULL
               AND o.assigned_worker_id IS NOT NULL
               AND COALESCE(o.auto_review_at,DATE_ADD(o.accepted_at,INTERVAL 5 DAY))<=UTC_TIMESTAMP()
               AND NOT EXISTS(SELECT 1 FROM reviews r WHERE r.order_id=o.id)
               AND NOT EXISTS(
                 SELECT 1 FROM scheduled_job_failures f
                 WHERE f.job_name='auto_review_order'
                   AND f.business_key=CONCAT('order:',o.id)
                   AND f.business_version=o.version
                   AND f.resolved_at IS NULL
                   AND f.next_retry_at>UTC_TIMESTAMP()
               )"
               .$this->demoOrderFilterSql()
               ." ORDER BY o.id ASC LIMIT ".$this->limit
        );
        $processor=new AutoReviewProcessor($this->db);
        return$this->processRows(
            $runId,
            'auto_review_order',
            $rows,
            static function($orderId,$candidateVersion)use($processor,$runId){
                return$processor->reviewProduction((int)$orderId,(int)$candidateVersion,(int)$runId);
            }
        );
    }

    // 2026-08-14：公开满 7 天仍未接受有效报价的订单自动取消，实收预约费只进入退款审核。
    private function runAutoCancel($runId)
    {
        // 2026-08-23：每分钟先释放超过 30 分钟且未实收的报价预占，使订单重新回到大厅。
        $releasedCount=$this->releaseExpiredQuoteSelections();
        $rows=$this->db->fetchAll(
            "SELECT o.id,o.version
             FROM orders o
             WHERE o.lifecycle_status='active'
               AND o.published_at IS NOT NULL
               AND COALESCE(o.quote_deadline_at,DATE_ADD(o.published_at,INTERVAL 7 DAY))<=UTC_TIMESTAMP()
               AND NOT EXISTS(
                 SELECT 1 FROM scheduled_job_failures f
                 WHERE f.job_name='auto_cancel_unaccepted'
                   AND f.business_key=CONCAT('order:',o.id)
                   AND f.business_version=o.version
                   AND f.resolved_at IS NULL
                   AND f.next_retry_at>UTC_TIMESTAMP()
               )"
               .$this->demoOrderFilterSql()
               ." ORDER BY o.id ASC LIMIT ".$this->limit
        );
        $processor=new AutoCancelProcessor($this->db);
        $stats=$this->processRows(
            $runId,
            'auto_cancel_unaccepted',
            $rows,
            static function($orderId,$candidateVersion)use($processor,$runId){
                return$processor->cancelProduction((int)$orderId,(int)$candidateVersion,(int)$runId);
            }
        );
        // 系统超时取消生成的正式支付退款仍由全局生产调度提交微信；测试中心 scoped 动作不会走这里。
        $this->submitAutomaticRefunds();
        $this->syncProcessingRefunds();
        $stats['scanned']+=$releasedCount;
        $stats['success']+=$releasedCount;
        return$stats;
    }

    private function releaseExpiredQuoteSelections()
    {
        $rows = $this->db->fetchAll("SELECT s.id,s.order_id,o.workflow_profile FROM quote_selections s INNER JOIN orders o ON o.id=s.order_id WHERE COALESCE(o.is_demo,0)=0 AND s.status='pending_payment' AND s.payment_expires_at<=UTC_TIMESTAMP() ORDER BY s.id LIMIT " . $this->limit);
        $released = 0;
        foreach ($rows as $row) {
            try {
                $changed = $this->db->transaction(function (Database $db) use ($row) {
                    $selection = $db->fetch("SELECT s.*,o.workflow_profile,o.audit_status,o.payment_status FROM quote_selections s INNER JOIN orders o ON o.id=s.order_id WHERE s.id=:id AND COALESCE(o.is_demo,0)=0 AND s.status='pending_payment' AND s.payment_expires_at<=UTC_TIMESTAMP() FOR UPDATE", ['id' => $row['id']]);
                    if (!$selection) return false;
                    $isMeasurement=(string)($selection['workflow_profile']??'construction')==='measurement';
                    $paymentType=$isMeasurement?'measurement_balance':'booking_fee';
                    if ($db->fetchValue("SELECT id FROM payment_orders WHERE business_type=:business_type AND business_id=:id AND status='paid' LIMIT 1", ['business_type'=>$paymentType,'id'=>$selection['id']])) return false;
                    $db->execute("UPDATE payment_orders SET status='closed',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE business_type=:business_type AND business_id=:id AND status IN ('created','pending')",['business_type'=>$paymentType,'id'=>$selection['id']]);
                    $db->execute("UPDATE quote_selections SET status='released',released_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_payment'", ['id' => $selection['id']]);
                    if($isMeasurement){
                        // 测量单首笔预约费早已支付，二次补款超时只释放报价预占，绝不能回滚预约费。
                        $db->execute("UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$selection['order_id']]);
                        $db->execute("UPDATE order_measurement_states SET service_payment_status='unpaid',balance_payment_order_id=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:id",['id'=>$selection['order_id']]);
                        $message='测量师傅补款超时，已释放报价预占；下单预约费保持已支付状态。';
                    }else{
                        $db->execute("UPDATE orders SET selected_quote_id=NULL,final_amount_cents=0,quote_status='quoting',payment_status='unpaid',publish_status=IF(audit_status='approved','published','hidden'),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND payment_status<>'paid'", ['id' => $selection['order_id']]);
                        $message='报价付款预占已超时释放。';
                    }
                    $this->recordOrderEvent($db,(int)$selection['order_id'],'payment_reservation_released','quote_payment_expiry',(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$selection['order_id']]),['message'=>$message,'workflow_profile'=>$isMeasurement?'measurement':'construction']);
                    return true;
                });
                if ($changed) $released++;
            } catch (Throwable $exception) { $this->writeErrorLog('quote_payment_expiry', (int) $row['order_id'], $exception); }
        }
        return $released;
    }

    private function submitAutomaticRefunds()
    {
        if (!(new WeChatPayService())->enabled()) return;
        // 系统取消退款沿用 pending_review；测量补款/加费的迟到支付退款直接以 created 进入自动提交，不应把整单标记为退款中。
        $rows = $this->db->fetchAll("SELECT r.id,r.public_no,r.amount_cents,r.reason,r.order_id,r.cancel_request_id,r.status refund_status,p.public_no payment_no,p.amount_cents total_cents,p.api_version,p.business_type payment_business_type,p.business_id payment_business_id FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id LEFT JOIN order_cancel_requests c ON c.id=r.cancel_request_id LEFT JOIN orders o ON o.id=r.order_id WHERE COALESCE(o.is_demo,0)=0 AND ((r.status='pending_review' AND c.id IS NOT NULL AND (c.applicant_type='system' OR c.reason_code='audit_rejected')) OR (r.status='created' AND p.business_type IN ('measurement_balance','measurement_change')) OR (r.status='approved' AND p.business_type='wallet_recharge')) ORDER BY r.id LIMIT " . $this->limit);
        foreach ($rows as $row) {
            try {
                $response = (new WeChatPayService((string) ($row['api_version'] ?? 'v3')))->createRefund((string) $row['payment_no'], (string) $row['public_no'], (int) $row['amount_cents'], (int) $row['total_cents'], (string) $row['reason']);
                $this->db->transaction(function (Database $db) use ($row, $response) {
                    $db->execute("UPDATE refunds SET status='processing',wechat_refund_id=:wechat_id,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status=:expected_status", ['wechat_id' => $response['refund_id'] ?? null, 'id' => $row['id'], 'expected_status'=>$row['refund_status']]);
                    if (!$row['order_id']) return;
                    if ((string)$row['payment_business_type']==='measurement_change') {
                        $db->execute("UPDATE change_requests SET payment_status='refund_pending',updated_at=UTC_TIMESTAMP() WHERE id=:id AND payment_status<>'refunded'",['id'=>$row['payment_business_id']]);
                        return;
                    }
                    if ((string)$row['payment_business_type']==='measurement_balance') return;
                    $db->execute("UPDATE orders SET refund_status='processing',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['id' => $row['order_id']]);
                });
            } catch (Throwable $exception) { $this->writeErrorLog('automatic_refund_submit', (int) $row['order_id'], $exception); }
        }
    }

    // 2026-08-23：宝塔任务对微信处理中退款做兜底查单，不根据本地按钮或前端结果伪造成功。
    private function syncProcessingRefunds()
    {
        $pay=new WeChatPayService();
        if(!$pay->enabled())return;
        $rows=$this->db->fetchAll("SELECT r.id,r.public_no,r.order_id,r.cancel_request_id,r.payment_order_id,r.amount_cents,p.public_no payment_no,p.amount_cents payment_total_cents,p.mch_id payment_mch_id,p.api_version,p.business_type payment_business_type,p.business_id payment_business_id FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id LEFT JOIN orders o ON o.id=r.order_id WHERE r.status='processing' AND COALESCE(o.is_demo,0)=0 ORDER BY r.updated_at,r.id LIMIT ".$this->limit);
        foreach($rows as $row){
            try{
                $resource=(new WeChatPayService((string)($row['api_version']??'v3')))->queryRefund((string)$row['public_no']);
                $status=(string)($resource['status']??$resource['refund_status']??'');
                if($status==='SUCCESS'){
                    $amount=is_array($resource['amount']??null)?$resource['amount']:[];
                    if((string)($resource['mchid']??'')!==(string)$row['payment_mch_id']
                        ||(string)($resource['out_trade_no']??'')!==(string)$row['payment_no']
                        ||(int)($amount['refund']??-1)!==(int)$row['amount_cents']
                        ||(int)($amount['total']??-1)!==(int)$row['payment_total_cents'])throw new RuntimeException('微信退款查单的商户、原支付单或金额不一致。');
                    $this->db->transaction(function(Database $db)use($row,$resource):void{
                        $refund=$db->fetch("SELECT * FROM refunds WHERE id=:id AND status='processing' FOR UPDATE",['id'=>$row['id']]);
                        if(!$refund)return;
                        $db->execute("UPDATE refunds SET status='succeeded',wechat_refund_id=:wechat_id,succeeded_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",['wechat_id'=>(string)($resource['refund_id']??''),'id'=>$refund['id']]);
                        $paymentFullyRefunded=$this->syncRefundedPaymentState($db,(int)$refund['payment_order_id']);
                        if($paymentFullyRefunded&&(string)$row['payment_business_type']==='measurement_change'&&(int)$row['payment_business_id']>0){
                            $db->execute("UPDATE change_requests SET payment_status='refunded',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$row['payment_business_id']]);
                        }
                        if((string)$row['payment_business_type']==='wallet_recharge'){
                            $rechargeId=(int)$row['payment_business_id'];
                            if($rechargeId<1)throw new RuntimeException('充值纠错退款缺少充值业务单');
                            (new UserWalletService($db))->consumeRechargeRefundHoldLocked(
                                $db,(int)$refund['id'],(string)$refund['public_no'],$rechargeId
                            );
                            return;
                        }
                        if(!empty($refund['cancel_request_id'])){
                            $this->finalizeCancelledOrderRefunds($db,(int)$refund['cancel_request_id'],(int)$refund['order_id']);
                            return;
                        }
                        if(!$refund['order_id'])return;
                        if((string)$row['payment_business_type']==='measurement_balance'){
                            $this->recordOrderEvent($db,(int)$refund['order_id'],'measurement_late_balance_refunded','refund_query',(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$refund['order_id']]),['refund_no'=>$refund['public_no'],'message'=>'迟到或失效的测量补款已原路退款，不取消有效测量订单。']);
                        }elseif((string)$row['payment_business_type']==='measurement_change'){
                            $this->recordOrderEvent($db,(int)$refund['order_id'],'measurement_change_refunded','refund_query',(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$refund['order_id']]),['refund_no'=>$refund['public_no'],'message'=>'迟到或失效的测量加费已原路退款，原成交总价保持不变。']);
                        }else{
                            $db->execute("UPDATE orders SET refund_status='succeeded',lifecycle_status='cancelled',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$refund['order_id']]);
                            $version=(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$refund['order_id']]);
                            $this->recordOrderEvent($db,(int)$refund['order_id'],'refund_succeeded','refund_query',$version,['refund_no'=>$refund['public_no'],'message'=>'微信退款主动查单确认成功。']);
                        }
                    });
                }elseif(in_array($status,['ABNORMAL','CLOSED'],true)){
                    $this->db->transaction(function(Database $db)use($row,$status):void{
                        $refund=$db->fetch("SELECT * FROM refunds WHERE id=:id AND status='processing' FOR UPDATE",['id'=>$row['id']]);
                        if(!$refund)return;
                        $db->execute("UPDATE refunds SET status='failed',failure_code=:code,failure_message=:message,updated_at=UTC_TIMESTAMP() WHERE id=:id",['code'=>$status,'message'=>'微信退款查单返回异常状态，请管理员处理','id'=>$row['id']]);
                        if((string)$row['payment_business_type']==='wallet_recharge'){
                            $rechargeId=(int)$row['payment_business_id'];
                            if($rechargeId>0){
                                (new UserWalletService($db))->releaseRechargeRefundHoldLocked(
                                    $db,(int)$refund['id'],(string)$refund['public_no'],$rechargeId,'微信退款查单确认失败/关闭，释放充值纠错冻结'
                                );
                            }
                            return;
                        }
                        if(!empty($refund['cancel_request_id'])&&$refund['order_id']){
                            $db->execute("UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$refund['order_id']]);
                        }elseif($row['order_id']&&!in_array((string)$row['payment_business_type'],['measurement_balance','measurement_change'],true)){
                            $db->execute("UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$row['order_id']]);
                        }
                    });
                }
            }catch(Throwable $exception){
                $this->writeErrorLog('refund_status_query',(int)($row['order_id']?:0),$exception);
            }
        }
    }

    private function syncRefundedPaymentState(Database $db,$paymentId)
    {
        $payment=$db->fetch('SELECT id,amount_cents FROM payment_orders WHERE id=:id FOR UPDATE',['id'=>$paymentId]);
        if(!$payment)return false;
        $succeeded=(int)$db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE payment_order_id=:id AND status='succeeded'",['id'=>$paymentId]);
        $full=$succeeded>=(int)$payment['amount_cents'];
        if($full)$db->execute("UPDATE payment_orders SET status='refunded',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$paymentId]);
        return $full;
    }

    private function finalizeCancelledOrderRefunds(Database $db,$cancelRequestId,$orderId)
    {
        $request=$db->fetch('SELECT * FROM order_cancel_requests WHERE id=:id FOR UPDATE',['id'=>$cancelRequestId]);
        if(!$request)return false;
        $stats=$db->fetch("SELECT COUNT(*) total,SUM(status='succeeded') succeeded,SUM(status='failed') failed,COALESCE(SUM(CASE WHEN status='succeeded' THEN amount_cents ELSE 0 END),0) succeeded_cents FROM refunds WHERE cancel_request_id=:id",['id'=>$cancelRequestId]);
        $total=(int)($stats['total']??0);$succeeded=(int)($stats['succeeded']??0);$failed=(int)($stats['failed']??0);
        if($total<1)return false;
        if($failed>0){$db->execute("UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$orderId]);return false;}
        if($succeeded<$total){$db->execute("UPDATE orders SET refund_status='processing',updated_at=UTC_TIMESTAMP() WHERE id=:id AND refund_status<>'processing'",['id'=>$orderId]);return false;}
        if($request['status']==='approved'&&!empty($request['closed_at']))return true;
        $db->execute("UPDATE order_cancel_requests SET status='approved',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$cancelRequestId]);
        $db->execute("UPDATE orders SET refund_status='succeeded',lifecycle_status='cancelled',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$orderId]);
        $version=(int)$db->fetchValue('SELECT version FROM orders WHERE id=:id',['id'=>$orderId]);
        $this->recordOrderEvent($db,(int)$orderId,'refund_succeeded','refund_query',$version,['amount_cents'=>(int)($stats['succeeded_cents']??0),'refund_count'=>$total,'message'=>'取消订单的 '.$total.' 笔原路退款均已由微信主动查单确认成功。']);
        return true;
    }

    private function runUnifiedMeasurementAutoAccept($runId)
    {
        $rows=$this->db->fetchAll(
            "SELECT o.id,o.version
             FROM orders o
             JOIN order_measurement_states ms ON ms.order_id=o.id
             WHERE o.workflow_profile='measurement'
               AND COALESCE(o.is_demo,0)=0
               AND o.lifecycle_status='active'
               AND o.fulfillment_status='submitted'
               AND ms.result_status='submitted'
               AND ms.auto_accept_at IS NOT NULL
               AND ms.auto_accept_at<=UTC_TIMESTAMP()
             ORDER BY o.id
             LIMIT ".$this->limit
        );
        $processor=new MeasurementAutoAcceptanceProcessor($this->db);
        return $this->processRows(
            $runId,
            'measurement_accept',
            $rows,
            static function($orderId,$candidateVersion)use($processor,$runId){
                return$processor->acceptProduction((int)$orderId,(int)$candidateVersion,(int)$runId);
            }
        );
    }

    private function runUnifiedMeasurementRelease($runId)
    {
        $rows=$this->db->fetchAll(
            "SELECT o.id,o.version FROM orders o JOIN order_measurement_states ms ON ms.order_id=o.id WHERE o.workflow_profile='measurement' AND COALESCE(o.is_demo,0)=0 AND ms.settlement_status='frozen' AND ms.hold_until IS NOT NULL AND ms.hold_until<=UTC_TIMESTAMP() ORDER BY ms.hold_until,o.id LIMIT ".$this->limit
        );
        return $this->processRows($runId,'measurement_release',$rows,function($orderId,$candidateVersion)use($runId){
            return $this->db->transaction(function(Database $db)use($orderId,$candidateVersion,$runId){
                $order=$db->fetch("SELECT * FROM orders WHERE id=:id AND workflow_profile='measurement' AND COALESCE(is_demo,0)=0 FOR UPDATE",['id'=>$orderId]);
                $state=$db->fetch("SELECT * FROM order_measurement_states WHERE order_id=:id FOR UPDATE",['id'=>$orderId]);
                if(!$order||!$state||(int)$order['version']!==(int)$candidateVersion||$state['settlement_status']!=='frozen'||empty($state['hold_until'])||strtotime((string)$state['hold_until'].' UTC')>time()||$this->hasBlockingBusiness($db,$order))return false;
                if(!$this->claim($db,$runId,'measurement_release',$orderId,max(1,(int)$order['version'])))return false;
                $assignment=$db->fetch("SELECT * FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('accepted','completed') ORDER BY id DESC LIMIT 1 FOR UPDATE",['order_id'=>$orderId,'worker_id'=>$order['assigned_worker_id']?:0]);
                if(!$assignment)throw new RuntimeException('测量订单缺少可释放资金的成交师傅');
                $net=max(0,(int)$state['worker_net_cents']);
                if($net>0)$db->execute("INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at) VALUES(:worker_id,'measurement_income_release','transfer',:amount,:available,:frozen,'order_measurement',:business_id,:key,'测量冻结期到期，净收入转入可用余额','effective',UTC_TIMESTAMP())",['worker_id'=>$assignment['worker_id'],'amount'=>$net,'available'=>$net,'frozen'=>-$net,'business_id'=>$orderId,'key'=>'order-measurement:'.$orderId.':released']);
                $db->execute("UPDATE order_measurement_states SET settlement_status='released',settled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND settlement_status='frozen'",['order_id'=>$orderId]);
                $db->execute("UPDATE order_measurement_hold_extensions SET status='expired',review_note=COALESCE(review_note,'冻结期已到期，未审批申请自动失效'),reviewed_at=COALESCE(reviewed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND status='pending'",['order_id'=>$orderId]);
                $this->recordOrderEvent($db,$orderId,'measurement_income_released','measurement_release',(int)$order['version'],['worker_net_cents'=>$net,'message'=>'测量冻结期已到期，师傅净收入已转入可用余额；已建立的施工关联保持不变。']);
                return true;
            });
        });
    }

    // 2026-08-14：逐条隔离业务失败，一条异常不会阻断同批次其他订单。
    private function processRows($runId, $jobName, array $rows, callable $callback)
    {
        $stats = ['scanned' => count($rows), 'success' => 0, 'failure' => 0];
        foreach ($rows as $row) {
            $orderId = (int) $row['id'];
            $version = max(1, (int) $row['version']);
            try {
                if ($callback($orderId, $version)) {
                    $stats['success']++;
                }
            } catch (Throwable $exception) {
                $stats['failure']++;
                $this->recordFailure($runId, $jobName, 'order:' . $orderId, $version, $exception);
                $this->writeErrorLog($jobName, $orderId, $exception);
            }
        }
        return $stats;
    }

    // 2026-08-14：未决争议、取消、退款或加价会暂停自动验收和自动评价。
    private function hasBlockingBusiness(Database $db, array $order)
    {
        if (!in_array((string) $order['dispute_status'], ['none', 'resolved'], true)) {
            return true;
        }
        if (!in_array((string) $order['refund_status'], ['none', 'failed'], true)) {
            return true;
        }
        $sql = "SELECT ("
            . "EXISTS(SELECT 1 FROM disputes WHERE order_id=:order_dispute AND status IN ('open','pending','processing')) "
            . "OR EXISTS(SELECT 1 FROM refunds WHERE order_id=:order_refund AND status IN ('created','requested','pending_review','approved','processing')) "
            . "OR EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:order_cancel AND status IN ('pending','processing')) "
            . "OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:order_change AND status IN ('pending','processing','negotiating')) "
            . "OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:order_after_sales AND status IN ('open','processing'))"
            . ") AS blocked";
        return (int) $db->fetchValue($sql, [
            'order_dispute' => $order['id'],
            'order_refund' => $order['id'],
            'order_cancel' => $order['id'],
            'order_change' => $order['id'],
            'order_after_sales' => $order['id'],
        ]) === 1;
    }

    // 2026-09-28：正式接单继续阻止七天自动取消；抢单模式在“已抢单、沟通报价中”的短暂独占期也要暂时阻止自动取消。
    // 普通 offered 派单不计入这里；single_grab offered 仅在报价截止时间仍有效时占用，超时后由 assignment_timeout 释放并重新公开。
    private function hasAcceptedQuoteOrWorker(Database $db, array $order)
    {
        $acceptedAssignment = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM assignments
             WHERE order_id=:id
               AND (
                 status IN ('accepted','completed')
                 OR (
                   assignment_type='single_grab'
                   AND status='offered'
                   AND (accept_deadline_at IS NULL OR accept_deadline_at>UTC_TIMESTAMP())
                 )
               )",
            ['id' => $order['id']]
        );
        return $acceptedAssignment > 0;
    }

    // 2026-09-14：完工提交只生成可逆的预计应收；用户验收或系统自动验收后才正式化。
    public function createCommissionEstimate(Database $db, $orderId)
    {
        return $this->createCommissionReceivableState($db, $orderId, true);
    }

    // 2026-09-14：兼容补偿任务和历史订单，正式应收只能在验收节点生成。
    public function createCommissionReceivable(Database $db, $orderId)
    {
        return $this->createCommissionReceivableState($db, $orderId, false);
    }

    private function commissionSnapshot(Database $db, $orderId)
    {
        $order = $db->fetch('SELECT * FROM orders WHERE id=:id', ['id' => $orderId]);
        if (!$order || (string) ($order['order_source_type'] ?? 'consumer') === 'platform' || empty($order['assigned_worker_id']) || (int) $order['final_amount_cents'] <= 0) return null;
        $assignment = !empty($order['current_assignment_id'])
            ? $db->fetch("SELECT * FROM assignments WHERE id=:id AND order_id=:order_id AND status IN ('accepted','completed')", ['id' => $order['current_assignment_id'], 'order_id' => $orderId])
            : null;
        if (!$assignment) $assignment = $db->fetch("SELECT * FROM assignments WHERE order_id=:id AND status IN ('accepted','completed') ORDER BY accepted_at DESC,id DESC LIMIT 1", ['id' => $orderId]);
        if (!$assignment) return null;
        $finalAmount = max(0, (int) $assignment['final_amount_cents_snapshot']) ?: max(0, (int) $order['final_amount_cents']);
        $rateBp = max(0, min(10000, (int) ($assignment['commission_rate_bp_snapshot'] ?? 1000)));
        $minFee = max(1, (int) ($assignment['commission_min_cents_snapshot'] ?? ($order['commission_min_cents'] ?? \setting('commission.minimum_cents', 5000))));
        $ruleId = (int) ($assignment['commission_rule_version_id'] ?: $order['commission_rule_version_id']);
        if ($finalAmount <= 0 || $ruleId <= 0) return null;
        $gross = min($finalAmount, max((int) round($finalAmount * $rateBp / 10000, 0, PHP_ROUND_HALF_UP), $minFee));
        $paidBooking = (int) $db->fetchValue("SELECT COALESCE(MAX(amount_cents),0) FROM payment_orders WHERE order_id=:id AND business_type IN ('order_booking','booking_fee') AND status='paid'", ['id' => $orderId]);
        $bookingExpected = max(0, (int) $assignment['booking_fee_cents_snapshot']);
        $verifiedBooking = $bookingExpected > 0 ? min(max(0, $paidBooking), $bookingExpected) : max(0, $paidBooking);
        $refunds = (int) $db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE order_id=:order_id AND status NOT IN ('failed','closed','cancelled','rejected')", ['order_id' => $orderId]);
        $runnerFees = (int) $db->fetchValue("SELECT COALESCE(SUM(l.amount_cents),0) FROM worker_balance_ledger l INNER JOIN disputes d ON d.id=l.business_id WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective' AND d.order_id=:order_id", ['order_id' => $orderId]);
        $offset = min($gross, max(0, $verifiedBooking - $refunds - $runnerFees));
        $due = max(0, $gross - $offset);
        return ['order' => $order, 'assignment' => $assignment, 'final_amount' => $finalAmount, 'rate_bp' => $rateBp, 'min_fee' => $minFee, 'rule_id' => $ruleId, 'gross' => $gross, 'verified_booking' => $verifiedBooking, 'offset' => $offset, 'due' => $due];
    }

    private function createCommissionReceivableState(Database $db, $orderId, $provisional)
    {
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM commission_receivables WHERE order_id=:id', ['id' => $orderId]) > 0) return false;
        $snapshot = $this->commissionSnapshot($db, $orderId);
        if (!$snapshot) return false;
        $dueDays = max(1, min(90, (int) \setting('commission.payment_due_days', 3)));
        $status = $provisional ? ($snapshot['due'] === 0 ? 'estimate_settled' : 'estimate') : ($snapshot['due'] === 0 ? 'settled' : 'pending');
        // 预约费高于服务费的差额：预计阶段冻结，历史/正式直入账阶段在验收后可用。
        $credit = max(0, $snapshot['verified_booking'] - $snapshot['gross']);
        $receivableId = $db->insert("INSERT INTO commission_receivables(public_no,order_id,worker_id,assignment_id,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,rate_bp_snapshot,min_fee_cents_snapshot,gross_commission_cents,booking_fee_offset_cents,amount_due_cents,amount_paid_cents,is_provisional,finalized_at,overpaid_cents,worker_credit_cents,status,due_at,settled_at,created_at,updated_at) VALUES(:public_no,:order_id,:worker_id,:assignment_id,:rule_id,:final_amount,:booking_fee,:rate_bp,:min_fee,:gross,:offset,:due,0,:provisional_value,IF(:provisional_finalize=0,UTC_TIMESTAMP(),NULL),0,:credit,:status,DATE_ADD(UTC_TIMESTAMP(),INTERVAL " . $dueDays . " DAY),IF(:settled_status IN ('settled','estimate_settled'),UTC_TIMESTAMP(),NULL),UTC_TIMESTAMP(),UTC_TIMESTAMP())", [
            'public_no' => \business_no('CM'), 'order_id' => $orderId, 'worker_id' => $snapshot['assignment']['worker_id'], 'assignment_id' => $snapshot['assignment']['id'], 'rule_id' => $snapshot['rule_id'], 'final_amount' => $snapshot['final_amount'], 'booking_fee' => $snapshot['verified_booking'], 'rate_bp' => $snapshot['rate_bp'], 'min_fee' => $snapshot['min_fee'], 'gross' => $snapshot['gross'], 'offset' => $snapshot['offset'], 'due' => $snapshot['due'], 'provisional_value' => $provisional ? 1 : 0, 'provisional_finalize' => $provisional ? 1 : 0, 'credit' => $credit, 'status' => $status, 'settled_status' => $status,
        ]);
        if ($credit > 0) {
            $entryType = $provisional ? 'commission_estimate' : 'commission_final_credit';
            $available = $provisional ? 0 : $credit;
            $frozen = $provisional ? $credit : 0;
            $description = $provisional ? '预约费高于预计服务费，验收前冻结待结算' : '验收后预约费高于正式服务费，差额进入师傅余额';
            $db->execute("INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at) VALUES(:worker_id,:entry_type,'credit',:amount_cents,:available,:frozen,'commission',:business_id,:key,:description,'effective',UTC_TIMESTAMP())", ['worker_id' => $snapshot['assignment']['worker_id'], 'entry_type' => $entryType, 'amount_cents' => $credit, 'available' => $available, 'frozen' => $frozen, 'business_id' => $receivableId, 'key' => ($provisional ? 'commission-estimate:' : 'commission-final:') . $receivableId . ':credit', 'description' => $description]);
        }
        $this->recordOrderEvent($db, $orderId, $provisional ? 'commission_estimate_created' : 'commission_receivable_created', 'commission_receivable', (int) $snapshot['order']['version'], ['is_provisional' => $provisional ? 1 : 0, 'rate_bp' => $snapshot['rate_bp'], 'min_fee_cents' => $snapshot['min_fee'], 'gross_commission_cents' => $snapshot['gross'], 'booking_fee_offset_cents' => $snapshot['offset'], 'amount_due_cents' => $snapshot['due'], 'worker_credit_cents' => $credit]);
        return true;
    }

    // 2026-09-14：正式化时释放验收前冻结余额；金额变化或师傅预缴超出最终应收均追加可审计调整流水。
    public function finalizeCommissionReceivable(Database $db, $orderId)
    {
        $receivable = $db->fetch('SELECT * FROM commission_receivables WHERE order_id=:id FOR UPDATE', ['id' => $orderId]);
        if (!$receivable) return $this->createCommissionReceivable($db, $orderId);
        if (!(int) $receivable['is_provisional']) return false;
        $snapshot = $this->commissionSnapshot($db, $orderId);
        if (!$snapshot) return false;
        $paid = max(0, (int) $receivable['amount_paid_cents']);
        $overpaid = max(0, $paid - $snapshot['due']);
        $status = $snapshot['due'] === 0 ? 'settled' : ($paid >= $snapshot['due'] ? 'paid' : 'pending');
        $oldCredit = max(0, (int) $receivable['worker_credit_cents']);
        $newCredit = max(0, $snapshot['verified_booking'] - $snapshot['gross']);
        $db->execute("UPDATE commission_receivables SET final_amount_cents_snapshot=:final_amount,booking_fee_cents_snapshot=:booking_fee,rate_bp_snapshot=:rate_bp,min_fee_cents_snapshot=:min_fee,gross_commission_cents=:gross,booking_fee_offset_cents=:offset,amount_due_cents=:due,is_provisional=0,finalized_at=UTC_TIMESTAMP(),overpaid_cents=:overpaid,worker_credit_cents=:credit,status=:status,settled_at=IF(:settled='settled',UTC_TIMESTAMP(),settled_at),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['final_amount' => $snapshot['final_amount'], 'booking_fee' => $snapshot['verified_booking'], 'rate_bp' => $snapshot['rate_bp'], 'min_fee' => $snapshot['min_fee'], 'gross' => $snapshot['gross'], 'offset' => $snapshot['offset'], 'due' => $snapshot['due'], 'overpaid' => $overpaid, 'credit' => $newCredit, 'status' => $status, 'settled' => $status, 'id' => $receivable['id']]);
        if ($oldCredit > 0) $db->execute("INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at) VALUES(:worker_id,'commission_estimate_release','credit',:amount,:available,:frozen,'commission',:business_id,:key,'验收完成，释放预计服务费差额冻结','effective',UTC_TIMESTAMP())", ['worker_id' => $receivable['worker_id'], 'amount' => $oldCredit, 'available' => $oldCredit, 'frozen' => -$oldCredit, 'business_id' => $receivable['id'], 'key' => 'commission:' . $receivable['id'] . ':provisional-release']);
        if ($newCredit !== $oldCredit) $db->execute("INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at) VALUES(:worker_id,'commission_final_adjustment',:direction,:amount,:available,0,'commission',:business_id,:key,:description,'effective',UTC_TIMESTAMP())", ['worker_id' => $receivable['worker_id'], 'direction' => $newCredit > $oldCredit ? 'credit' : 'debit', 'amount' => abs($newCredit - $oldCredit), 'available' => $newCredit - $oldCredit, 'business_id' => $receivable['id'], 'key' => 'commission:' . $receivable['id'] . ':final-credit-adjustment', 'description' => '验收后按正式服务费重算师傅余额差额']);
        if ($overpaid > 0) $db->execute("INSERT IGNORE INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,business_type,business_id,idempotency_key,description,status,created_at) VALUES(:worker_id,'commission_overpayment','credit',:amount_cents,:available_delta_cents,0,'commission',:business_id,:key,'预计服务费超出正式应收，差额退回师傅余额','effective',UTC_TIMESTAMP())", ['worker_id' => $receivable['worker_id'], 'amount_cents' => $overpaid, 'available_delta_cents' => $overpaid, 'business_id' => $receivable['id'], 'key' => 'commission:' . $receivable['id'] . ':overpayment']);
        $this->recordOrderEvent($db, $orderId, 'commission_receivable_finalized', 'commission_receivable', (int) $snapshot['order']['version'], ['gross_commission_cents' => $snapshot['gross'], 'amount_due_cents' => $snapshot['due'], 'overpaid_cents' => $overpaid, 'worker_credit_cents' => $newCredit, 'message' => '用户验收完成，预计服务费已转为正式应收。']);
        return true;
    }


    // 2026-08-14：验收后按订单事实重算师傅完工单量。
    private function refreshWorkerCompletedOrders(Database $db, $workerId)
    {
        if (empty($workerId)) {
            return;
        }
        $count = (int) $db->fetchValue(
            'SELECT COUNT(*) FROM orders WHERE assigned_worker_id=:worker_id AND accepted_at IS NOT NULL',
            ['worker_id' => $workerId]
        );
        $db->execute(
            'UPDATE worker_profiles SET completed_orders=:count,updated_at=UTC_TIMESTAMP() WHERE id=:id',
            ['count' => $count, 'id' => $workerId]
        );
    }

    // 2026-08-14：业务 claim 与状态变更在同一事务内写入，回滚时不会留下永久占位。
    private function claim(Database $db, $runId, $jobName, $orderId, $version)
    {
        $inserted = $db->execute(
            "INSERT IGNORE INTO scheduled_job_claims(job_name,business_key,business_version,scheduler_run_id,status,claimed_at) VALUES (:job,:business_key,:version,:run_id,'claimed',UTC_TIMESTAMP())",
            [
                'job' => $jobName,
                'business_key' => 'order:' . (int) $orderId,
                'version' => max(1, (int) $version),
                'run_id' => $runId,
            ]
        );
        return $inserted === 1;
    }

    // 2026-08-14：完成 claim 时保存精简结果，重复扫描会被唯一键拒绝。
    private function completeClaim(Database $db, $jobName, $orderId, $version, array $result)
    {
        $db->execute(
            "UPDATE scheduled_job_claims SET status='completed',completed_at=UTC_TIMESTAMP(),result_json=:result WHERE job_name=:job AND business_key=:business_key AND business_version=:version AND status='claimed'",
            [
                'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'job' => $jobName,
                'business_key' => 'order:' . (int) $orderId,
                'version' => max(1, (int) $version),
            ]
        );
        $db->execute(
            "UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name=:job AND business_key=:business_key AND business_version=:version AND resolved_at IS NULL",
            [
                'job' => $jobName,
                'business_key' => 'order:' . (int) $orderId,
                'version' => max(1, (int) $version),
            ]
        );
    }

    // 2026-08-14：订单事件使用确定性键防止同版本重复写入。
    private function recordOrderEvent(Database $db, $orderId, $eventType, $jobName, $version, array $payload)
    {
        $eventKey = 'scheduler:' . $jobName . ':order:' . (int) $orderId . ':v' . max(1, (int) $version);
        $db->execute(
            "INSERT IGNORE INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES (:order_id,:event_type,:event_key,'system',NULL,:payload,UTC_TIMESTAMP())",
            [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'event_key' => substr($eventKey, 0, 128),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
        // 2026-09-02：自动验收、自动退款等重要节点也进入短信发件箱。
        OrderNotificationService::queue($db, (int)$orderId, substr($eventKey, 0, 128), (string)$eventType, $payload);
    }

    // 2026-08-14：自动任务仅创建站内通知记录，不额外要求用户确认流程。
    private function createNotification(Database $db, $recipientType, $recipientId, $templateKey, $title, $content, $orderId)
    {
        if (empty($recipientId)) {
            return;
        }
        $db->execute(
            "INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at) VALUES (:public_id,:recipient_type,:recipient_id,'in_app',:template_key,:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id' => \public_id(),
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'template_key' => $templateKey,
                'title' => $title,
                'content' => $content,
                'data' => json_encode(['order_id' => (int) $orderId], JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    // 2026-08-14：单条业务失败写入可重试记录，claim 因事务回滚不会阻止下一次重试。
    private function runWalletReconcile($runId, $force = false)
    {
        if(!$this->db->tableExists('user_wallets')||!$this->db->tableExists('user_wallet_ledger')){
            return ['scanned'=>0,'success'=>0,'failure'=>0];
        }
        // 充值纠错退款提交/查单属于时效任务，仍随每分钟统一调度执行。
        $this->submitAutomaticRefunds();
        $this->syncProcessingRefunds();

        // 钱包余额与不可变台账的全量 SUM 对账不需要每分钟跑。
        // 只以“上一次真正扫描过钱包”的运行作为节流基准；期间的 0 扫描心跳不会把下一次对账无限推迟。
        $lastFullCheck = $this->db->fetchValue(
            "SELECT finished_at
             FROM scheduler_runs
             WHERE job_name='wallet_reconcile'
               AND id<>:run_id
               AND status IN ('success','failed')
               AND scanned_count>0
               AND finished_at IS NOT NULL
             ORDER BY id DESC
             LIMIT 1",
            ['run_id' => $runId]
        );
        if (!$force && $lastFullCheck) {
            $lastTimestamp = strtotime((string) $lastFullCheck . ' UTC');
            if ($lastTimestamp !== false && $lastTimestamp > time() - 3600) {
                return ['scanned'=>0,'success'=>0,'failure'=>0];
            }
        }

        $scanned=(int)$this->db->fetchValue('SELECT COUNT(*) FROM user_wallets');
        $rows=$this->db->fetchAll(
            "SELECT w.user_id,w.available_cents,w.reserved_cents,
                    COALESCE(SUM(CASE WHEN l.status='effective' THEN l.available_delta_cents ELSE 0 END),0) ledger_available_cents,
                    COALESCE(SUM(CASE WHEN l.status='effective' THEN l.reserved_delta_cents ELSE 0 END),0) ledger_reserved_cents
             FROM user_wallets w
             LEFT JOIN user_wallet_ledger l ON l.user_id=w.user_id
             GROUP BY w.id,w.user_id,w.available_cents,w.reserved_cents
             HAVING w.available_cents<>ledger_available_cents OR w.reserved_cents<>ledger_reserved_cents
             ORDER BY w.id"
        );

        $current=[];
        foreach($rows as $row)$current['wallet:user:'.(int)$row['user_id']]=$row;

        // 已恢复一致的钱包自动关闭旧告警；持续异常的钱包保留原未解决告警，不每轮重复插入。
        $openFailures=$this->db->fetchAll(
            "SELECT id,business_key FROM scheduled_job_failures
             WHERE job_name='wallet_reconcile' AND resolved_at IS NULL
             ORDER BY id DESC"
        );
        $openByKey=[];
        foreach($openFailures as $failure){
            $key=(string)$failure['business_key'];
            if(isset($current[$key])){
                // 历史版本若已为同一钱包积累多条未解决失败，只保留最新一条。
                if(isset($openByKey[$key])){
                    $this->db->execute(
                        "UPDATE scheduled_job_failures
                         SET resolved_at=UTC_TIMESTAMP()
                         WHERE id=:id AND resolved_at IS NULL",
                        ['id'=>$failure['id']]
                    );
                }else{
                    $openByKey[$key]=true;
                }
            }else{
                $this->db->execute(
                    "UPDATE scheduled_job_failures
                     SET resolved_at=UTC_TIMESTAMP()
                     WHERE id=:id AND resolved_at IS NULL",
                    ['id'=>$failure['id']]
                );
            }
        }

        $logged=0;
        foreach($current as $key=>$row){
            $message='用户钱包与不可变台账不一致：available='.(int)$row['available_cents']
                .' ledger_available='.(int)$row['ledger_available_cents']
                .' reserved='.(int)$row['reserved_cents']
                .' ledger_reserved='.(int)$row['ledger_reserved_cents'];
            if(empty($openByKey[$key])&&$logged<$this->limit){
                $this->recordFailure($runId,'wallet_reconcile',$key,1,new RuntimeException($message));
                $logged++;
            }
            \write_log('wallet-reconcile','['.date('c').'] '.$key.' '.$message."\n");
        }
        $failureCount=count($rows);
        // 一致的钱包只是“巡检通过”，不是成功处理了一笔业务，success_count 不再写钱包总数。
        return [
            'scanned'=>$scanned,
            'success'=>0,
            'failure'=>$failureCount,
            'error'=>$failureCount>0 ? ('发现 '.(int)$failureCount.' 个用户钱包与不可变台账不一致。') : null,
        ];
    }

    /**
     * 2026-09-29：清理已不再属于“待删除孤儿”的媒体失败，并避免后台长期挂着失效告警。
     */
    private function resolveStaleMediaCleanupFailures()
    {
        $this->db->execute(
            "UPDATE scheduled_job_failures f
             LEFT JOIN media_files m ON f.business_key=CONCAT('media:',m.id)
             SET f.resolved_at=UTC_TIMESTAMP()
             WHERE f.job_name='media_cleanup'
               AND f.resolved_at IS NULL
               AND (
                   m.id IS NULL
                   OR m.status='deleted'
                   OR m.reference_count>0
                   OR EXISTS (SELECT 1 FROM media_links l WHERE l.media_file_id=m.id)
               )"
        );

        // 历史版本每次重试都会新增一条 unresolved；同一媒体/版本只保留最新失败。
        $duplicates = $this->db->fetchAll(
            "SELECT business_key,business_version,MAX(id) keep_id,COUNT(*) duplicate_count
             FROM scheduled_job_failures
             WHERE job_name='media_cleanup' AND resolved_at IS NULL
             GROUP BY business_key,business_version
             HAVING COUNT(*)>1"
        );
        foreach ($duplicates as $duplicate) {
            $this->db->execute(
                "UPDATE scheduled_job_failures
                 SET resolved_at=UTC_TIMESTAMP()
                 WHERE job_name='media_cleanup'
                   AND business_key=:business_key
                   AND business_version=:business_version
                   AND resolved_at IS NULL
                   AND id<>:keep_id",
                [
                    'business_key'=>(string)$duplicate['business_key'],
                    'business_version'=>(int)$duplicate['business_version'],
                    'keep_id'=>(int)$duplicate['keep_id'],
                ]
            );
        }
    }

    /**
     * 2026-09-29：scheduler_runs 只承担近期运行诊断，业务事实由订单/通知/资金台账及 scheduled_job_failures 保存。
     * 扫描数量不代表发生业务动作；无处理无失败的运行只保留最新一条。
     */
    private function pruneSchedulerRunHistory($task, $keepId, $status, array $stats)
    {
        if ($status === 'success'
            && $task !== 'wallet_reconcile'
            && (int)$stats['success'] === 0
            && (int)$stats['failure'] === 0) {
            $this->db->execute(
                "DELETE FROM scheduler_runs
                 WHERE job_name=:job_name
                   AND id<>:keep_id
                   AND status='success'
                   AND success_count=0
                   AND failure_count=0",
                ['job_name' => $task, 'keep_id' => $keepId]
            );
        }

        // wallet_reconcile 需要保留“最近一次完整扫描”的时间作为每小时节流锚点；
        // 因此最多保留 1 条最近完整扫描 + 1 条当前心跳，其余历史成功记录全部收敛。
        if ($status === 'success' && $task === 'wallet_reconcile') {
            $lastFullCheckId = (int)$this->db->fetchValue(
                "SELECT id
                 FROM scheduler_runs
                 WHERE job_name='wallet_reconcile'
                   AND status='success'
                   AND failure_count=0
                   AND scanned_count>0
                 ORDER BY id DESC
                 LIMIT 1"
            );
            if ($lastFullCheckId > 0 && $lastFullCheckId !== (int)$keepId) {
                $this->db->execute(
                    "DELETE FROM scheduler_runs
                     WHERE job_name='wallet_reconcile'
                       AND status='success'
                       AND failure_count=0
                       AND id<>:keep_id
                       AND id<>:full_check_id",
                    ['keep_id' => $keepId, 'full_check_id' => $lastFullCheckId]
                );
            } else {
                $this->db->execute(
                    "DELETE FROM scheduler_runs
                     WHERE job_name='wallet_reconcile'
                       AND status='success'
                       AND failure_count=0
                       AND id<>:keep_id",
                    ['keep_id' => $keepId]
                );
            }
        }

        // 旧 repair_escalation 把重复幂等提醒误计为 success_count>0；新版只保留当前成功摘要。
        if ($status === 'success' && $task === 'repair_escalation') {
            $this->db->execute(
                "DELETE FROM scheduler_runs
                 WHERE job_name='repair_escalation'
                   AND id<>:keep_id
                   AND status='success'
                   AND failure_count=0",
                ['keep_id' => $keepId]
            );
        }

        // 失败详情在 scheduled_job_failures 中独立保留；scheduler_runs 只是运行摘要，
        // 每类仅保留最近 20 次失败，避免诊断日志再次长期膨胀。失败事实不会因此删除。
        $oldestKeptFailureId = (int)$this->db->fetchValue(
            "SELECT id
             FROM scheduler_runs
             WHERE job_name=:job_name AND status='failed'
             ORDER BY id DESC
             LIMIT 1 OFFSET 19",
            ['job_name' => $task]
        );
        if ($oldestKeptFailureId > 0) {
            $this->db->execute(
                "DELETE FROM scheduler_runs
                 WHERE job_name=:job_name
                   AND status='failed'
                   AND id<:oldest_keep_id",
                ['job_name' => $task, 'oldest_keep_id' => $oldestKeptFailureId]
            );
        }
    }

    private function recordFailure($runId, $jobName, $businessKey, $version, Throwable $exception)
    {
        try {
            $attempt = (int) $this->db->fetchValue(
                'SELECT COALESCE(MAX(attempt_no),0)+1 FROM scheduled_job_failures WHERE job_name=:job AND business_key=:business_key AND business_version=:version',
                ['job' => $jobName, 'business_key' => $businessKey, 'version' => max(1, (int) $version)]
            );
            $minutes = min(1440, max(1, 1 << min(10, max(0, $attempt - 1))));
            $this->db->execute(
                "INSERT INTO scheduled_job_failures(job_name,business_key,business_version,scheduler_run_id,attempt_no,error_class,error_message,next_retry_at,created_at) VALUES (:job,:business_key,:version,:run_id,:attempt,:error_class,:error_message,DATE_ADD(UTC_TIMESTAMP(),INTERVAL " . $minutes . " MINUTE),UTC_TIMESTAMP())",
                [
                    'job' => $jobName,
                    'business_key' => substr((string) $businessKey, 0, 128),
                    'version' => max(1, (int) $version),
                    'run_id' => $runId,
                    'attempt' => max(1, $attempt),
                    'error_class' => substr(get_class($exception), 0, 191),
                    'error_message' => substr($exception->getMessage(), 0, 4000),
                ]
            );
        } catch (Throwable $ignored) {
            $this->writeErrorLog($jobName, 0, $ignored);
        }
    }

    // 2026-08-14：启动前确认调度依赖表存在，避免半安装状态误改订单。
    private function assertSchemaReady()
    {
        foreach (['orders', 'scheduler_runs', 'scheduled_job_claims', 'scheduled_job_failures', 'order_events', 'message_outbox', 'user_wallets', 'user_wallet_ledger', 'user_wallet_reservations', 'order_worker_sms_batches', 'order_worker_sms_invites'] as $table) {
            if (!$this->db->tableExists($table)) {
                throw new RuntimeException('计划任务依赖数据表缺失：' . $table);
            }
        }
    }

    // 2026-08-14：异常退出遗留的运行中记录只改为 failed，不影响数据库锁自动释放。
    private function closeStaleRuns()
    {
        $this->db->execute(
            "UPDATE scheduler_runs SET status='failed',finished_at=UTC_TIMESTAMP(),error_message='上次进程异常退出或运行超时。' WHERE status='running' AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR)"
        );
    }

    // V2.2.7：演示订单永远不由生产计划任务推进；演示阶段只能由演示身份人工操作改变。
    private function demoOrderFilterSql()
    {
        return " AND COALESCE(o.is_demo,0)=0";
    }

    // 2026-08-14：文件维护锁是升级失败后的持久保护，调度器不得在人工恢复前继续读写业务表。
    private function maintenanceActive()
    {
        $root = defined('WORKER_BASE_PATH') ? WORKER_BASE_PATH : dirname(__DIR__, 2);
        return is_file(rtrim($root, '/\\') . '/storage/maintenance.lock');
    }

    // 2026-08-14：被互斥或维护状态跳过属于正常结果，写入简洁日志供宝塔核对。
    private function skippedResult($task, $status, $message)
    {
        \write_log('scheduler', '[' . date('c') . '] task=' . $task . ' status=' . $status . "\n");
        return ['status' => $status, 'task' => $task, 'message' => $message, 'runs' => []];
    }

    // 2026-08-14：先取得与升级器同名的互斥锁，再取得调度锁，防止 DDL 迁移与订单任务并发。
    private function acquireLock()
    {
        $statement = $this->db->pdo()->prepare('SELECT GET_LOCK(:lock_name,0)');
        $statement->execute(['lock_name' => $this->upgradeLockName]);
        $this->upgradeGuardAcquired = (int) $statement->fetchColumn() === 1;
        if (!$this->upgradeGuardAcquired) {
            return false;
        }

        $statement = $this->db->pdo()->prepare('SELECT GET_LOCK(:lock_name,0)');
        $statement->execute(['lock_name' => $this->lockName]);
        $this->schedulerLockAcquired = (int) $statement->fetchColumn() === 1;
        if (!$this->schedulerLockAcquired) {
            $this->releaseNamedLock($this->upgradeLockName);
            $this->upgradeGuardAcquired = false;
            return false;
        }
        return true;
    }

    // 2026-08-14：无论任务成功或失败都按相反顺序释放调度锁和升级保护锁。
    private function releaseLock()
    {
        if ($this->schedulerLockAcquired) {
            $this->releaseNamedLock($this->lockName);
            $this->schedulerLockAcquired = false;
        }
        if ($this->upgradeGuardAcquired) {
            $this->releaseNamedLock($this->upgradeLockName);
            $this->upgradeGuardAcquired = false;
        }
    }

    // 2026-08-14：命名锁释放失败只写日志，数据库连接关闭时 MySQL 仍会自动回收。
    private function releaseNamedLock($name)
    {
        try {
            $statement = $this->db->pdo()->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $name]);
        } catch (Throwable $exception) {
            $this->writeErrorLog('release_lock', 0, $exception);
        }
    }

    // 2026-08-14：运行摘要同时写文件日志，宝塔面板也可保存标准输出。
    private function writeRunLog(array $result)
    {
        $line = sprintf(
            "[%s] task=%s run=%s status=%s scanned=%d success=%d failure=%d duration_ms=%d%s\n",
            date('c'),
            $result['task'],
            $result['run_uuid'],
            $result['status'],
            $result['scanned'],
            $result['success'],
            $result['failure'],
            $result['duration_ms'],
            $result['error'] ? ' error=' . str_replace(["\r", "\n"], ' ', $result['error']) : ''
        );
        \write_log('scheduler', $line);
    }

    // 2026-08-14：错误日志隐藏连接配置，仅保留任务、订单和异常摘要。
    private function writeErrorLog($jobName, $orderId, Throwable $exception)
    {
        $line = sprintf(
            "[%s] task=%s order_id=%d error=%s\n",
            date('c'),
            $jobName,
            (int) $orderId,
            str_replace(["\r", "\n"], ' ', $exception->getMessage())
        );
        \write_log('scheduler-error', $line);
    }

    // 2026-08-14：生成 scheduler_runs 使用的标准 UUIDv4。
    private function uuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation {
    final class MessagingApplicationContainer
    {
        public function __construct($db) {}
        public function paidSms()
        {
            return new \SchedulerTaskBackoffFakePaidSms();
        }
    }
}

namespace WorkerAdmin\Infrastructure\Scheduling {
    final class ConfiguredOrderDeadlineBridge
    {
        private $db;
        public function __construct($db) { $this->db=$db; }
        public function prepare($task): void
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'deadline_prepare');
        }
        public function after($task, $startedAtUtc): void
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'deadline_after');
        }
    }

    final class ApprovedOrderRefundRetryBridge
    {
        private $db;
        public function __construct($db, $limit) { $this->db=$db; }
        public function run(): array
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'refund_retry');
            \SchedulerTaskBackoffFakeTasks::$refund++;
            return [
                'status'=>'success','task'=>'order_refund_retry',
                'scanned'=>0,'success'=>0,'failure'=>0,'error'=>null,
            ];
        }
    }

    final class MediaPromotionTask
    {
        private $db;
        public function __construct($db, $limit = 100) { $this->db=$db; }
        public function run(): array
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'media_promote');
            \SchedulerTaskBackoffFakeTasks::$media++;
            $this->resolve('media_promote');
            return \SchedulerTaskBackoffFakeTasks::success('media_promote');
        }
        private function resolve(string $job): void
        {
            $this->db->execute(
                'UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL',
                ['job'=>$job,'key'=>'task:'.$job]
            );
        }
    }

    final class PlatformOrderAutoPublishTask
    {
        private $db;
        public function __construct($db, $limit = 100) { $this->db=$db; }
        public function run(): array
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'platform_auto_publish');
            \SchedulerTaskBackoffFakeTasks::$platform++;
            $this->resolve('platform_auto_publish');
            return \SchedulerTaskBackoffFakeTasks::success('platform_auto_publish');
        }
        private function resolve(string $job): void
        {
            $this->db->execute(
                'UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL',
                ['job'=>$job,'key'=>'task:'.$job]
            );
        }
    }

    final class MeasurementFrozenBalanceReleaseTask
    {
        private $db;
        public function __construct($db, $limit = 100) { $this->db=$db; }
        public function run(): array
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'measurement_release');
            \SchedulerTaskBackoffFakeTasks::$measurement++;
            $this->resolve('measurement_release');
            return \SchedulerTaskBackoffFakeTasks::success('measurement_release');
        }
        private function resolve(string $job): void
        {
            $this->db->execute(
                'UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL',
                ['job'=>$job,'key'=>'task:'.$job]
            );
        }
    }
}

namespace WorkerAdmin\Infrastructure\Persistence {
    final class DatabaseChatPolicyMaintenance
    {
        private $db;
        public function __construct($db) { $this->db=$db; }
        public function refresh($limit): array
        {
            \SchedulerTaskBackoffFakeTasks::assertGuard($this->db,'chat_policy_maintenance');
            \SchedulerTaskBackoffFakeTasks::$chat++;
            return [
                'task'=>'chat_policy_maintenance','status'=>'success',
                'scanned'=>1,'success'=>1,'failure'=>0,'error'=>null,
            ];
        }
    }
}

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Infrastructure\Persistence\DatabaseSchedulerRunMaintenance;
    use WorkerAdmin\Services\SchedulerService;
    use WorkerAdmin\Infrastructure\Scheduling\SchedulerTaskFailureBackoff;
    use WorkerAdmin\Infrastructure\Scheduling\LegacySchedulerGateway;

    function write_log($channel, $line): void {}

    final class SchedulerTaskBackoffFakePaidSms
    {
        public static int $dispatchCalls = 0;
        public static int $receiptCalls = 0;
        public static bool $throwDispatch = false;

        public function dispatchPending(int $limit): array
        {
            self::$dispatchCalls++;
            if (self::$throwDispatch) throw new RuntimeException('synthetic dispatcher dependency failure');
            return ['scanned'=>0,'success'=>0,'failure'=>0];
        }

        public function processReceipts(int $limit): array
        {
            self::$receiptCalls++;
            return ['scanned'=>0,'success'=>0,'failure'=>0];
        }
    }

    final class SchedulerTaskBackoffFakeTasks
    {
        public static int $media = 0;
        public static int $platform = 0;
        public static int $measurement = 0;
        public static int $refund = 0;
        public static int $chat = 0;
        public static array $guardChecks = [];

        public static function assertGuard($db, string $label): void
        {
            $connection=(int)$db->fetchValue('SELECT CONNECTION_ID()');
            $upgrade=(int)$db->fetchValue("SELECT IS_USED_LOCK('wall_worker_admin_upgrade')");
            $scheduler=(int)$db->fetchValue("SELECT IS_USED_LOCK('worker_order_scheduler_v1')");
            if ($connection<1 || $upgrade!==$connection || $scheduler!==$connection) {
                throw new RuntimeException('[FAIL] '.$label.' did not run under both scheduler locks');
            }
            self::$guardChecks[]=$label;
        }

        public static function success(string $task): array
        {
            return [
                'run_id'=>null,'run_uuid'=>null,'task'=>$task,'status'=>'success',
                'scanned'=>0,'success'=>0,'failure'=>0,'duration_ms'=>0,'error'=>null,
            ];
        }
    }

    function schedulerBackoffSame($actual, $expected, string $label): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException(
                '[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)
            );
        }
        fwrite(STDOUT, '[PASS] '.$label."\n");
    }

    if (getenv('V260_SCHEDULER_TASK_BACKOFF_MYSQL_TEST') !== '1'
        || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('V250_DB_NAME') !== 'worker_test') {
        fwrite(STDERR, "This test is restricted to GitHub Actions worker_test.\n");
        exit(2);
    }

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseSchedulerFailureRecorder.php';
    require_once $root.'/server/app/Infrastructure/Persistence/DatabaseSchedulerRunMaintenance.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/SchedulerExecutionGuard.php';
    require_once $root.'/server/app/Contracts/SchedulerGateway.php';
    require_once $root.'/server/app/Domain/Scheduling/SchedulerTaskCatalog.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/SchedulerTaskFailureBackoff.php';
    require_once $root.'/server/app/Services/SchedulerService.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/LegacySchedulerGateway.php';

    $runtimeRoot=sys_get_temp_dir().'/workers-scheduler-maintenance-'.getmypid();
    $runtimeStorage=$runtimeRoot.'/storage';
    if (!is_dir($runtimeStorage) && !mkdir($runtimeStorage,0777,true) && !is_dir($runtimeStorage)) {
        throw new RuntimeException('Cannot create scheduler maintenance test root');
    }
    if (!defined('WORKER_BASE_PATH')) define('WORKER_BASE_PATH',$runtimeRoot);
    $maintenancePath=$runtimeStorage.'/maintenance.lock';

    $config=[
        'host'=>(string)(getenv('V250_DB_HOST')?:'127.0.0.1'),
        'port'=>(int)(getenv('V250_DB_PORT')?:3306),
        'database'=>'worker_test',
        'username'=>(string)(getenv('V250_DB_USER')?:'root'),
        'password'=>(string)(getenv('V250_DB_PASSWORD')?:'root'),
    ];
    $rootDb=new Database($config);
    $mysqlVersion=(string)$rootDb->fetchValue('SELECT VERSION()');
    if (strpos($mysqlVersion,'5.7.')!==0) {
        throw new RuntimeException('Expected MySQL 5.7, got '.$mysqlVersion);
    }

    $dbName='worker_v260_scheduler_task_backoff_ci';
    $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    $rootDb->pdo()->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config['database']=$dbName;
    $db=new Database($config);

    try {
        foreach ([
            "CREATE TABLE scheduler_runs(
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_uuid VARCHAR(64) NOT NULL,
                job_name VARCHAR(64) NOT NULL,
                status VARCHAR(24) NOT NULL,
                host_name VARCHAR(128) NULL,
                process_id INT NULL,
                scanned_count INT NOT NULL DEFAULT 0,
                success_count INT NOT NULL DEFAULT 0,
                failure_count INT NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                error_message TEXT NULL,
                KEY idx_scheduler_job_status(job_name,status)
            ) ENGINE=InnoDB",
            "CREATE TABLE scheduled_job_failures(
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(64) NOT NULL,
                business_key VARCHAR(128) NOT NULL,
                business_version INT NOT NULL DEFAULT 1,
                scheduler_run_id BIGINT UNSIGNED NULL,
                attempt_no INT NOT NULL DEFAULT 1,
                error_class VARCHAR(191) NULL,
                error_message TEXT NOT NULL,
                next_retry_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                KEY idx_scheduled_failure_business(job_name,business_key,business_version),
                KEY idx_scheduled_failure_retry(resolved_at,next_retry_at),
                KEY idx_scheduled_failure_run(scheduler_run_id),
                CONSTRAINT fk_scheduled_failure_run FOREIGN KEY(scheduler_run_id) REFERENCES scheduler_runs(id) ON DELETE SET NULL
            ) ENGINE=InnoDB",
            "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE scheduled_job_claims(
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(64) NOT NULL,
                business_key VARCHAR(128) NOT NULL,
                business_version INT NOT NULL DEFAULT 1,
                scheduler_run_id BIGINT UNSIGNED NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'claimed',
                claimed_at DATETIME NOT NULL,
                completed_at DATETIME NULL,
                result_json LONGTEXT NULL,
                UNIQUE KEY uq_scheduled_job_claims_business(job_name,business_key,business_version),
                KEY idx_scheduled_job_claims_run(scheduler_run_id),
                CONSTRAINT fk_scheduled_job_claims_run FOREIGN KEY(scheduler_run_id) REFERENCES scheduler_runs(id) ON DELETE SET NULL
            ) ENGINE=InnoDB",
            "CREATE TABLE order_events(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE message_outbox(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE user_wallets(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE user_wallet_ledger(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE user_wallet_reservations(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE order_worker_sms_batches(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE order_worker_sms_invites(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
        ] as $sql) {
            $db->pdo()->exec($sql);
        }

        $seedFailure = static function(string $job, string $businessKey, string $when) use ($db): int {
            return $db->insert(
                "INSERT INTO scheduled_job_failures(
                    job_name,business_key,business_version,attempt_no,error_class,error_message,
                    next_retry_at,created_at
                 ) VALUES(:job,:key,1,3,'RuntimeException','synthetic task-level failure',:retry,UTC_TIMESTAMP())",
                ['job'=>$job,'key'=>$businessKey,'retry'=>$when]
            );
        };
        $unresolved = static function(string $job, string $businessKey) use ($db): int {
            return (int)$db->fetchValue(
                'SELECT COUNT(*) FROM scheduled_job_failures WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL',
                ['job'=>$job,'key'=>$businessKey]
            );
        };

        $scheduler=new SchedulerService($db,10);
        $seedFailure('paid_sms_dispatch','task:paid_sms_dispatch','2035-01-01 00:00:00');
        $beforeDispatchRows=$unresolved('paid_sms_dispatch','task:paid_sms_dispatch');

        $dispatchSkipped=$scheduler->run('paid_sms_dispatch',false);
        schedulerBackoffSame($dispatchSkipped['status'],'success','scheduled task-level backoff is not a batch failure');
        schedulerBackoffSame($dispatchSkipped['runs'][0]['status'],'skipped_backoff','paid SMS dispatch is skipped while task-level retry is in the future');
        schedulerBackoffSame((int)$dispatchSkipped['skipped_count'],1,'scheduler reports one deferred task');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$dispatchCalls,0,'deferred paid SMS dispatch never reaches SendSms engine');
        schedulerBackoffSame($unresolved('paid_sms_dispatch','task:paid_sms_dispatch'),$beforeDispatchRows,'deferred task failure remains unresolved');

        $dispatchSkippedAgain=$scheduler->run('paid_sms_dispatch',false);
        schedulerBackoffSame($dispatchSkippedAgain['runs'][0]['status'],'skipped_backoff','repeated scheduler tick remains deferred');
        schedulerBackoffSame((int)$db->fetchValue("SELECT COUNT(*) FROM scheduler_runs WHERE job_name='paid_sms_dispatch' AND status='skipped_backoff'"),1,'only the latest skipped run summary is retained');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$dispatchCalls,0,'repeated deferred ticks still do not call dispatch');

        $dispatchForced=$scheduler->run('paid_sms_dispatch',true);
        schedulerBackoffSame($dispatchForced['status'],'success','manual force can execute during task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$dispatchCalls,1,'manual force reaches paid SMS dispatch exactly once');
        schedulerBackoffSame($unresolved('paid_sms_dispatch','task:paid_sms_dispatch'),0,'successful task execution resolves task-level failures');

        SchedulerTaskBackoffFakePaidSms::$throwDispatch = true;
        $firstTaskFailure = $scheduler->run('paid_sms_dispatch',false);
        SchedulerTaskBackoffFakePaidSms::$throwDispatch = false;
        schedulerBackoffSame($firstTaskFailure['status'],'failed','uncaught task dependency exception is recorded as task failure');
        schedulerBackoffSame($unresolved('paid_sms_dispatch','task:paid_sms_dispatch'),1,'task-level exception creates unresolved task failure record');
        schedulerBackoffSame((int)$db->fetchValue(
            "SELECT COUNT(*) FROM scheduled_job_failures WHERE job_name='paid_sms_dispatch' AND business_key='task:paid_sms_dispatch' AND resolved_at IS NULL AND next_retry_at>UTC_TIMESTAMP()"
        ),1,'task-level exception schedules a future retry');
        schedulerBackoffSame((int)$db->fetchValue(
            "SELECT attempt_no FROM scheduled_job_failures WHERE job_name='paid_sms_dispatch' AND business_key='task:paid_sms_dispatch' AND resolved_at IS NULL ORDER BY id DESC LIMIT 1"
        ),1,'a new failure episode resets its retry attempt after the previous episode was resolved');
        $callsAfterFailure = SchedulerTaskBackoffFakePaidSms::$dispatchCalls;
        $deferredAfterFailure = $scheduler->run('paid_sms_dispatch',false);
        schedulerBackoffSame($deferredAfterFailure['runs'][0]['status'],'skipped_backoff','newly recorded task failure is deferred on the next scheduler tick');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$dispatchCalls,$callsAfterFailure,'deferred retry does not call the failed dependency again');
        SchedulerTaskBackoffFakePaidSms::$throwDispatch = true;
        $repeatTaskFailure = $scheduler->run('paid_sms_dispatch',true);
        SchedulerTaskBackoffFakePaidSms::$throwDispatch = false;
        schedulerBackoffSame($repeatTaskFailure['status'],'failed','manual retry records another failure in the same incident');
        schedulerBackoffSame($unresolved('paid_sms_dispatch','task:paid_sms_dispatch'),1,'repeated failures consolidate into one unresolved row');
        schedulerBackoffSame((int)$db->fetchValue(
            "SELECT attempt_no FROM scheduled_job_failures WHERE job_name='paid_sms_dispatch' AND business_key='task:paid_sms_dispatch' AND resolved_at IS NULL ORDER BY id DESC LIMIT 1"
        ),2,'repeated failure increments the current incident attempt');
        $recoveredAfterFailure = $scheduler->run('paid_sms_dispatch',true);
        schedulerBackoffSame($recoveredAfterFailure['status'],'success','manual recovery bypasses the newly recorded backoff');
        schedulerBackoffSame($unresolved('paid_sms_dispatch','task:paid_sms_dispatch'),0,'manual recovery resolves the newly recorded task failure');

        $seedFailure('paid_sms_receipt','task:paid_sms_receipt','2000-01-01 00:00:00');
        $receiptDue=$scheduler->run('paid_sms_receipt',false);
        schedulerBackoffSame($receiptDue['runs'][0]['status'],'success','expired task-level retry is allowed to execute');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$receiptCalls,1,'due receipt retry reaches receipt engine');
        schedulerBackoffSame($unresolved('paid_sms_receipt','task:paid_sms_receipt'),0,'successful receipt run resolves task-level failure');

        $seedFailure('paid_sms_receipt','order:501','2035-01-01 00:00:00');
        $receiptBusinessFailure=$scheduler->run('paid_sms_receipt',false);
        schedulerBackoffSame($receiptBusinessFailure['runs'][0]['status'],'success','per-business failure does not pause the entire receipt task');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$receiptCalls,2,'receipt engine still processes other eligible business records');

        $seedFailure('paid_sms_receipt','task:paid_sms_receipt','2035-01-01 00:00:00');
        $receiptForced=$scheduler->run('paid_sms_receipt',true);
        schedulerBackoffSame($receiptForced['runs'][0]['status'],'success','manual receipt retry bypasses task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$receiptCalls,3,'manual receipt retry reaches receipt engine');

        // Old scheduler summaries are disposable diagnostics; failure facts and
        // idempotency claims remain. A failed delete must roll back pointer detachment.
        $oldRunId=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at)
            VALUES('cleanup-old','cleanup_test','failed',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY))");
        $recentRunId=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at)
            VALUES('cleanup-recent','cleanup_test','success',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $runningRunId=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at)
            VALUES('cleanup-running','cleanup_test','running',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),NULL)");
        $activeClaimRunId=$db->insert("INSERT INTO scheduler_runs(run_uuid,job_name,status,started_at,finished_at)
            VALUES('cleanup-active-claim','cleanup_test','success',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 40 DAY))");
        $failureHistoryId=$db->insert("INSERT INTO scheduled_job_failures(job_name,business_key,business_version,scheduler_run_id,attempt_no,error_class,error_message,next_retry_at,created_at)
            VALUES('cleanup_test','order:cleanup',1,:run,1,'RuntimeException','keep failure fact',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['run'=>$oldRunId]);
        $completedClaimId=$db->insert("INSERT INTO scheduled_job_claims(job_name,business_key,business_version,scheduler_run_id,status,claimed_at,completed_at)
            VALUES('cleanup_test','done:cleanup',1,:run,'completed',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['run'=>$oldRunId]);
        $activeClaimId=$db->insert("INSERT INTO scheduled_job_claims(job_name,business_key,business_version,scheduler_run_id,status,claimed_at,completed_at)
            VALUES('cleanup_test','active:cleanup',1,:run,'claimed',UTC_TIMESTAMP(),NULL)",['run'=>$activeClaimRunId]);
        $runMaintenance=new DatabaseSchedulerRunMaintenance($db);
        $db->pdo()->exec("CREATE TRIGGER scheduler_run_cleanup_fail BEFORE DELETE ON scheduler_runs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture scheduler cleanup failure'");
        $cleanupFailed=false;
        try { $runMaintenance->purgeOlderThan(7,'cleanup_test'); } catch (Throwable $error) { $cleanupFailed=true; }
        $db->pdo()->exec('DROP TRIGGER scheduler_run_cleanup_fail');
        schedulerBackoffSame($cleanupFailed,true,'failed summary deletion aborts the cleanup batch');
        schedulerBackoffSame((int)$db->fetchValue('SELECT scheduler_run_id FROM scheduled_job_failures WHERE id=:id',['id'=>$failureHistoryId]),$oldRunId,'failed cleanup rolls back failure pointer detachment');
        schedulerBackoffSame((int)$db->fetchValue('SELECT scheduler_run_id FROM scheduled_job_claims WHERE id=:id',['id'=>$completedClaimId]),$oldRunId,'failed cleanup rolls back completed claim pointer detachment');
        $cleanupResult=$runMaintenance->purgeOlderThan(7,'cleanup_test');
        schedulerBackoffSame((int)$cleanupResult['count'],1,'cleanup deletes only eligible old completed summary');
        schedulerBackoffSame((int)$cleanupResult['remaining_count'],0,'cleanup reports no remaining eligible summaries');
        schedulerBackoffSame((int)$db->fetchValue('SELECT COUNT(*) FROM scheduler_runs WHERE id=:id',['id'=>$oldRunId]),0,'old summary is deleted');
        schedulerBackoffSame((int)$db->fetchValue('SELECT COUNT(*) FROM scheduled_job_failures WHERE id=:id',['id'=>$failureHistoryId]),1,'business failure fact is retained');
        schedulerBackoffSame($db->fetchValue('SELECT scheduler_run_id FROM scheduled_job_failures WHERE id=:id',['id'=>$failureHistoryId]),null,'failure history is detached from deleted summary');
        schedulerBackoffSame($db->fetchValue('SELECT scheduler_run_id FROM scheduled_job_claims WHERE id=:id',['id'=>$completedClaimId]),null,'completed idempotency claim is retained and detached');
        schedulerBackoffSame((int)$db->fetchValue('SELECT COUNT(*) FROM scheduler_runs WHERE id=:id',['id'=>$recentRunId]),1,'recent summary is retained');
        schedulerBackoffSame((int)$db->fetchValue('SELECT COUNT(*) FROM scheduler_runs WHERE id=:id',['id'=>$runningRunId]),1,'running summary is retained');
        schedulerBackoffSame((int)$db->fetchValue('SELECT COUNT(*) FROM scheduler_runs WHERE id=:id',['id'=>$activeClaimRunId]),1,'summary with an active claim is retained');
        schedulerBackoffSame((int)$db->fetchValue('SELECT COUNT(*) FROM scheduled_job_claims WHERE id=:id',['id'=>$activeClaimId]),1,'active idempotency claim is retained');

        $gateway=new LegacySchedulerGateway($db,10);


        // 维护文件存在时，所有独立任务、legacy 任务和 all 都必须在任何业务动作前整体跳过。
        $beforeMaintenance=[
            SchedulerTaskBackoffFakeTasks::$media,
            SchedulerTaskBackoffFakeTasks::$platform,
            SchedulerTaskBackoffFakeTasks::$measurement,
            SchedulerTaskBackoffFakeTasks::$refund,
            SchedulerTaskBackoffFakeTasks::$chat,
        ];
        file_put_contents($maintenancePath,'maintenance test');
        $directDispatchCalls=SchedulerTaskBackoffFakePaidSms::$dispatchCalls;
        $directMaintenance=$scheduler->run('paid_sms_dispatch',false);
        schedulerBackoffSame($directMaintenance['status'],'skipped_maintenance','direct legacy SchedulerService run observes maintenance gate');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$dispatchCalls,$directDispatchCalls,'direct legacy SchedulerService run has no maintenance side effect');
        $maintenanceCallbacks=0;
        foreach (['media_promote','platform_auto_publish','measurement_release','auto_cancel','wallet_reconcile','all'] as $blockedTask) {
            $blocked=$gateway->run($blockedTask,false,function(array $result) use (&$maintenanceCallbacks): array {
                $maintenanceCallbacks++;
                return ['after_run_called'=>true];
            });
            schedulerBackoffSame($blocked['status'],'skipped_maintenance','maintenance file blocks '.$blockedTask.' before task execution');
        }
        @unlink($maintenancePath);
        schedulerBackoffSame($maintenanceCallbacks,0,'maintenance skip never invokes post-run wallet cleanup callback');
        schedulerBackoffSame([
            SchedulerTaskBackoffFakeTasks::$media,
            SchedulerTaskBackoffFakeTasks::$platform,
            SchedulerTaskBackoffFakeTasks::$measurement,
            SchedulerTaskBackoffFakeTasks::$refund,
            SchedulerTaskBackoffFakeTasks::$chat,
        ],$beforeMaintenance,'maintenance skip prevents all independent task side effects');
        schedulerBackoffSame($db->fetchValue("SELECT IS_USED_LOCK('wall_worker_admin_upgrade')"),null,'maintenance skip does not acquire upgrade lock');

        // 其他连接占用 scheduler 锁时，Gateway 必须释放已取得的 upgrade 锁并整体跳过。
        $lockDb=new Database($config);
        schedulerBackoffSame((int)$lockDb->fetchValue('SELECT CONNECTION_ID()') !== (int)$db->fetchValue('SELECT CONNECTION_ID()'),true,'lock contention uses a separate MySQL connection');
        schedulerBackoffSame((int)$lockDb->fetchValue("SELECT GET_LOCK('worker_order_scheduler_v1',0)"),1,'test connection acquires scheduler lock');
        $beforeLocked=[
            SchedulerTaskBackoffFakeTasks::$media,
            SchedulerTaskBackoffFakeTasks::$platform,
            SchedulerTaskBackoffFakeTasks::$measurement,
            SchedulerTaskBackoffFakeTasks::$refund,
            SchedulerTaskBackoffFakeTasks::$chat,
        ];
        $lockedCallbacks=0;
        foreach (['media_promote','platform_auto_publish','measurement_release','auto_cancel','wallet_reconcile','all'] as $blockedTask) {
            $locked=$gateway->run($blockedTask,false,function(array $result) use (&$lockedCallbacks): array {
                $lockedCallbacks++;
                return ['after_run_called'=>true];
            });
            schedulerBackoffSame($locked['status'],'skipped_locked','scheduler lock contention blocks '.$blockedTask);
        }
        $directCallsBeforeLock=SchedulerTaskBackoffFakePaidSms::$dispatchCalls;
        $directLocked=$scheduler->run('paid_sms_dispatch',false);
        schedulerBackoffSame($directLocked['status'],'skipped_locked','direct legacy SchedulerService run observes scheduler lock contention');
        schedulerBackoffSame(SchedulerTaskBackoffFakePaidSms::$dispatchCalls,$directCallsBeforeLock,'direct legacy SchedulerService run has no lock-contention side effect');
        schedulerBackoffSame($lockedCallbacks,0,'locked skip never invokes post-run callback');
        schedulerBackoffSame([
            SchedulerTaskBackoffFakeTasks::$media,
            SchedulerTaskBackoffFakeTasks::$platform,
            SchedulerTaskBackoffFakeTasks::$measurement,
            SchedulerTaskBackoffFakeTasks::$refund,
            SchedulerTaskBackoffFakeTasks::$chat,
        ],$beforeLocked,'lock contention prevents every independent task side effect');
        schedulerBackoffSame($db->fetchValue("SELECT IS_USED_LOCK('wall_worker_admin_upgrade')"),null,'failed second lock acquisition releases upgrade lock');
        $lockDb->fetchValue("SELECT RELEASE_LOCK('worker_order_scheduler_v1')");

        // 升级器先持有 upgrade 锁时，Gateway 不得执行任何任务。
        schedulerBackoffSame((int)$lockDb->fetchValue("SELECT GET_LOCK('wall_worker_admin_upgrade',0)"),1,'test connection acquires upgrade lock');
        $upgradeLocked=$gateway->run('media_promote',false);
        schedulerBackoffSame($upgradeLocked['status'],'skipped_locked','upgrade lock contention skips independent media task');
        $directUpgradeLocked=$scheduler->run('paid_sms_dispatch',false);
        schedulerBackoffSame($directUpgradeLocked['status'],'skipped_locked','direct legacy SchedulerService run observes upgrade lock contention');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$media,$beforeLocked[0],'upgrade lock contention never enters media task');
        $lockDb->fetchValue("SELECT RELEASE_LOCK('wall_worker_admin_upgrade')");

        $seedFailure('media_promote','task:media_promote','2035-01-01 00:00:00');
        $mediaSkipped=$gateway->run('media_promote',false);
        schedulerBackoffSame($mediaSkipped['status'],'skipped_backoff','gateway media promotion observes task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$media,0,'deferred media promotion never enters task');
        $mediaSkippedAgain=$gateway->run('media_promote',false);
        schedulerBackoffSame($mediaSkippedAgain['runs'][0]['status'],'skipped_backoff','repeated media promotion remains deferred');
        schedulerBackoffSame((int)$db->fetchValue("SELECT COUNT(*) FROM scheduler_runs WHERE job_name='media_promote' AND status='skipped_backoff'"),1,'gateway keeps only latest skipped media run');
        $mediaForced=$gateway->run('media_promote',true);
        schedulerBackoffSame($mediaForced['status'],'success','manual media promotion bypasses task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$media,1,'manual media promotion enters task exactly once');
        schedulerBackoffSame($unresolved('media_promote','task:media_promote'),0,'successful media promotion resolves task-level failure');

        $seedFailure('platform_auto_publish','task:platform_auto_publish','2035-01-01 00:00:00');
        $platformSkipped=$gateway->run('platform_auto_publish',false);
        schedulerBackoffSame($platformSkipped['status'],'skipped_backoff','gateway platform publish observes task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$platform,0,'deferred platform publish never enters task');
        $platformForced=$gateway->run('platform_auto_publish',true);
        schedulerBackoffSame($platformForced['status'],'success','manual platform publish bypasses task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$platform,1,'manual platform publish enters task exactly once');
        schedulerBackoffSame($unresolved('platform_auto_publish','task:platform_auto_publish'),0,'successful platform publish resolves task-level failure');

        $seedFailure('measurement_release','task:measurement_release','2035-01-01 00:00:00');
        $measurementSkipped=$gateway->run('measurement_release',false);
        schedulerBackoffSame($measurementSkipped['status'],'skipped_backoff','gateway measurement release observes task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$measurement,0,'deferred measurement release never enters task');
        $measurementForced=$gateway->run('measurement_release',true);
        schedulerBackoffSame($measurementForced['status'],'success','manual measurement release bypasses task-level backoff');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$measurement,1,'manual measurement release enters task exactly once');
        schedulerBackoffSame($unresolved('measurement_release','task:measurement_release'),0,'successful measurement release resolves task-level failure');


        // all 的独立任务、旧任务、退款桥、聊天维护及钱包充值清理回调均持有同一组全局锁。
        $allCallbacks=0;
        $allResult=$gateway->run('all',false,function(array $result) use ($db,&$allCallbacks): array {
            SchedulerTaskBackoffFakeTasks::assertGuard($db,'wallet_recharge_cleanup');
            $allCallbacks++;
            return ['wallet_recharge_cleanup'=>['scanned'=>1,'closed'=>1,'confirming'=>0,'deferred'=>0]];
        });
        schedulerBackoffSame($allCallbacks,1,'all invokes wallet recharge cleanup callback exactly once');
        schedulerBackoffSame($allResult['wallet_recharge_cleanup']['closed'],1,'post-run cleanup result is merged into scheduler result');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$refund,1,'approved refund bridge runs under global scheduler locks');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$chat,1,'chat policy maintenance runs under global scheduler locks');
        schedulerBackoffSame(SchedulerTaskBackoffFakeTasks::$guardChecks!==[],true,'independent task and deadline callbacks verified both global locks');

        $gatewaySource=(string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/LegacySchedulerGateway.php');
        $expectedForceGuard = "\$taskForce = \$force && \$task !== 'all';";
        schedulerBackoffSame(strpos($gatewaySource, $expectedForceGuard) !== false, true, 'gateway does not bypass task backoff for an all run');
        schedulerBackoffSame(strpos($gatewaySource, '$measurementAlreadyDeferred') !== false, true, 'all run does not write a duplicate measurement backoff heartbeat');

        fwrite(STDOUT,"Scheduler task-level failure backoff MySQL regression passed.\n");
    } finally {
        @unlink($maintenancePath);
        @rmdir($runtimeStorage);
        @rmdir($runtimeRoot);
        $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    }
}

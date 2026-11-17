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

namespace {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Services\SchedulerService;
    use WorkerAdmin\Infrastructure\Scheduling\SchedulerTaskFailureBackoff;

    function write_log($channel, $line): void {}

    final class SchedulerTaskBackoffFakePaidSms
    {
        public static int $dispatchCalls = 0;
        public static int $receiptCalls = 0;

        public function dispatchPending(int $limit): array
        {
            self::$dispatchCalls++;
            return ['scanned'=>0,'success'=>0,'failure'=>0];
        }

        public function processReceipts(int $limit): array
        {
            self::$receiptCalls++;
            return ['scanned'=>0,'success'=>0,'failure'=>0];
        }
    }

    function schedulerBackoffSame($actual, $expected, string $label): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException(
                '[FAIL] '.$label.' actual='.var_export($actual,true).' expected='.var_export($expected,true)
            );
        }
        fwrite(STDOUT, '[PASS] '.$label."\\n");
    }

    if (getenv('V260_SCHEDULER_TASK_BACKOFF_MYSQL_TEST') !== '1'
        || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('V250_DB_NAME') !== 'worker_test') {
        fwrite(STDERR, "This test is restricted to GitHub Actions worker_test.\\n");
        exit(2);
    }

    $root=dirname(__DIR__,2);
    require_once $root.'/server/app/Core/Database.php';
    require_once $root.'/server/app/Infrastructure/Scheduling/SchedulerTaskFailureBackoff.php';
    require_once $root.'/server/app/Services/SchedulerService.php';

    if (!defined('WORKER_BASE_PATH')) define('WORKER_BASE_PATH',$root);

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
                error_class VARCHAR(160) NULL,
                error_message TEXT NULL,
                next_retry_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                KEY idx_scheduled_failure_business(job_name,business_key,business_version),
                KEY idx_scheduled_failure_retry(resolved_at,next_retry_at)
            ) ENGINE=InnoDB",
            "CREATE TABLE orders(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
            "CREATE TABLE scheduled_job_claims(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
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

        $backoff=new SchedulerTaskFailureBackoff($db);
        $seedFailure('media_promote','task:media_promote','2035-01-01 00:00:00');
        $mediaRetryAt=$backoff->retryAt('media_promote',false);
        schedulerBackoffSame(is_string($mediaRetryAt),true,'gateway-only task exposes active task-level retry time');
        schedulerBackoffSame($backoff->retryAt('media_promote',true),null,'manual force bypasses only task-level backoff');
        $skip1=$backoff->recordSkippedRun('media_promote',(string)$mediaRetryAt);
        $skip2=$backoff->recordSkippedRun('media_promote',(string)$mediaRetryAt);
        schedulerBackoffSame($skip1['status'],'skipped_backoff','gateway skip summary uses explicit status');
        schedulerBackoffSame((int)$db->fetchValue("SELECT COUNT(*) FROM scheduler_runs WHERE job_name='media_promote' AND status='skipped_backoff'"),1,'gateway skip summaries are bounded to one per task');

        $gatewaySource=(string)file_get_contents($root.'/server/app/Infrastructure/Scheduling/LegacySchedulerGateway.php');
        foreach (['media_promote','platform_auto_publish','measurement_release'] as $job) {
            schedulerBackoffSame(strpos($gatewaySource,"retryAt('".$job."', $force)")!==false,true,'gateway wires task-level backoff for '.$job);
        }

        fwrite(STDOUT,"Scheduler task-level failure backoff MySQL regression passed.\\n");
    } finally {
        $rootDb->pdo()->exec('DROP DATABASE IF EXISTS '.$dbName);
    }
}

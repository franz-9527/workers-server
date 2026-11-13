<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Scheduling;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\QiniuObjectStorage;

/**
 * 已提交订单媒体后台迁移。
 *
 * Web 请求只保存本地私有临时文件和订单事实；七牛网络上传由本任务异步完成，避免第三方网络抖动
 * 阻塞下单/补资料。MySQL 仍是媒体元数据真源，失败进入 scheduled_job_failures 后按退避时间重试。
 */
final class MediaPromotionTask
{
    private const JOB = 'media_promote';
    private const LOCK = 'worker_media_promote_v1';

    private Database $db;
    private ObjectStorage $storage;
    private int $limit;

    public function __construct(Database $db, int $limit = 100, ?ObjectStorage $storage = null)
    {
        $this->db = $db;
        $this->limit = max(1, min(500, $limit));
        $this->storage = $storage ?: new QiniuObjectStorage();
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        if (!$this->acquireLock()) {
            return [
                'run_id'=>null,'run_uuid'=>null,'task'=>self::JOB,'status'=>'success',
                'scanned'=>0,'success'=>0,'failure'=>0,'duration_ms'=>0,'error'=>null,
                'message'=>'已有媒体迁移任务正在运行，本次安全跳过。',
            ];
        }

        try {
            return $this->runLocked();
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array<string,mixed> */
    private function runLocked(): array
    {
        $runUuid = $this->uuidV4();
        $runId = $this->db->insert(
            "INSERT INTO scheduler_runs(run_uuid,job_name,status,host_name,process_id,scanned_count,success_count,failure_count,started_at)
             VALUES(:uuid,:job,'running',:host,:pid,0,0,0,UTC_TIMESTAMP())",
            [
                'uuid'=>$runUuid,
                'job'=>self::JOB,
                'host'=>substr((string)gethostname(), 0, 128),
                'pid'=>function_exists('getmypid') ? (int)getmypid() : null,
            ]
        );
        $startedAt = microtime(true);
        $stats = ['scanned'=>0,'success'=>0,'failure'=>0];
        $error = null;

        try {
            if ($this->storage->enabled()) {
                $rows = $this->eligibleRows();
                $stats['scanned'] = count($rows);
                foreach ($rows as $row) {
                    try {
                        if ($this->promoteOne($row)) {
                            $stats['success']++;
                        }
                    } catch (Throwable $exception) {
                        $stats['failure']++;
                        $this->recordFailure($runId, (int)$row['id'], $exception);
                    }
                }
            }
        } catch (Throwable $exception) {
            $stats['failure']++;
            $error = mb_substr($exception->getMessage(), 0, 4000);
            $this->recordFailure($runId, 0, $exception, 'task:' . self::JOB);
        }

        if ($error === null && $stats['failure'] > 0) {
            $error = '本批次有 ' . $stats['failure'] . ' 个订单媒体迁移失败，请查看异常待办。';
        }
        $status = $stats['failure'] > 0 ? 'failed' : 'success';
        $this->db->execute(
            "UPDATE scheduler_runs
             SET status=:status,scanned_count=:scanned,success_count=:success,failure_count=:failure,
                 finished_at=UTC_TIMESTAMP(),error_message=:error
             WHERE id=:id",
            [
                'status'=>$status,'scanned'=>$stats['scanned'],'success'=>$stats['success'],
                'failure'=>$stats['failure'],'error'=>$error,'id'=>$runId,
            ]
        );

        if ($status === 'success') {
            $this->db->execute(
                "UPDATE scheduled_job_failures
                 SET resolved_at=UTC_TIMESTAMP()
                 WHERE job_name=:job AND business_key=:key AND resolved_at IS NULL",
                ['job'=>self::JOB,'key'=>'task:' . self::JOB]
            );
        }
        if ($stats['success'] === 0 && $stats['failure'] === 0) {
            $this->db->execute(
                "DELETE FROM scheduler_runs
                 WHERE job_name=:job AND id<>:id AND status='success'
                   AND success_count=0 AND failure_count=0",
                ['job'=>self::JOB,'id'=>$runId]
            );
        }

        return [
            'run_id'=>$runId,'run_uuid'=>$runUuid,'task'=>self::JOB,'status'=>$status,
            'scanned'=>$stats['scanned'],'success'=>$stats['success'],'failure'=>$stats['failure'],
            'duration_ms'=>(int)round((microtime(true) - $startedAt) * 1000),'error'=>$error,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function eligibleRows(): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT m.id,m.public_id,m.storage_key,m.mime_type
             FROM media_files m
             INNER JOIN media_links l ON l.media_file_id=m.id
             INNER JOIN orders o ON o.id=l.entity_id
             WHERE m.storage_disk='private_local'
               AND m.status='active'
               AND l.entity_type='order'
               AND l.purpose IN ('product','wall')
               AND COALESCE(o.is_demo,0)=0
               AND o.lifecycle_status='active'
               AND (
                    o.payment_status IN ('awaiting_payment','paid')
                    OR o.audit_status IN ('pending','approved','supplement_required')
               )
               AND NOT EXISTS(
                    SELECT 1 FROM scheduled_job_failures f
                    WHERE f.job_name='media_promote'
                      AND f.business_key=CONCAT('media:',m.id)
                      AND f.business_version=1
                      AND f.resolved_at IS NULL
                      AND f.next_retry_at>UTC_TIMESTAMP()
               )
             ORDER BY m.id
             LIMIT {$this->limit}"
        );
    }

    /** @param array<string,mixed> $row */
    private function promoteOne(array $row): bool
    {
        $mediaId = (int)$row['id'];
        if ($mediaId < 1) return false;

        // 网络调用前再确认媒体仍满足迁移条件，避免草稿回退/删除后的旧扫描结果继续上传。
        $current = $this->db->fetch(
            "SELECT m.id,m.storage_key,m.mime_type
             FROM media_files m
             WHERE m.id=:id AND m.storage_disk='private_local' AND m.status='active'
               AND EXISTS(
                    SELECT 1
                    FROM media_links l INNER JOIN orders o ON o.id=l.entity_id
                    WHERE l.media_file_id=m.id
                      AND l.entity_type='order'
                      AND l.purpose IN ('product','wall')
                      AND COALESCE(o.is_demo,0)=0
                      AND o.lifecycle_status='active'
                      AND (o.payment_status IN ('awaiting_payment','paid') OR o.audit_status IN ('pending','approved','supplement_required'))
               )
             LIMIT 1",
            ['id'=>$mediaId]
        );
        if (!$current) return false;

        $path = $this->privateMediaPath((string)$current['storage_key']);
        if ($path === null) {
            throw new RuntimeException('本地待迁移媒体文件不存在或路径无效。');
        }

        $stored = $this->storage->store(
            $path,
            (string)$current['storage_key'],
            'order',
            (string)($current['mime_type'] ?: 'application/octet-stream')
        );
        if (($stored['disk'] ?? '') !== 'qiniu' || empty($stored['key'])) {
            throw new RuntimeException('对象存储未返回有效的七牛对象键。');
        }

        $changed = $this->db->execute(
            "UPDATE media_files
             SET storage_disk='qiniu',storage_key=:storage_key
             WHERE id=:id AND storage_disk='private_local' AND status='active'",
            ['storage_key'=>(string)$stored['key'],'id'=>$mediaId]
        );
        if ($changed !== 1) return false;

        @unlink($path);
        $this->db->execute(
            "UPDATE scheduled_job_failures
             SET resolved_at=UTC_TIMESTAMP()
             WHERE job_name=:job AND business_key=:key AND business_version=1 AND resolved_at IS NULL",
            ['job'=>self::JOB,'key'=>'media:' . $mediaId]
        );
        return true;
    }

    private function privateMediaPath(string $storageKey): ?string
    {
        $key = str_replace('\\', '/', trim($storageKey));
        if ($key === '' || strpos($key, "\0") !== false || $key[0] === '/' || preg_match('/^[A-Za-z]:\//', $key) === 1 || preg_match('#(^|/)\.\.(/|$)#', $key) === 1) {
            return null;
        }
        if (strpos($key, 'storage/private/') === 0) $key = substr($key, 16);
        $root = realpath(\base_path('storage/private'));
        if ($root === false) return null;
        $path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key));
        if ($path === false || !is_file($path)) return null;
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($path, $prefix) !== 0) return null;
        return $path;
    }

    private function acquireLock(): bool
    {
        return (int)$this->db->fetchValue('SELECT GET_LOCK(:lock_name,0)', ['lock_name'=>self::LOCK]) === 1;
    }

    private function releaseLock(): void
    {
        try {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:lock_name)', ['lock_name'=>self::LOCK]);
        } catch (Throwable $ignored) {
        }
    }

    private function recordFailure(int $runId, int $mediaId, Throwable $exception, ?string $businessKey = null): void
    {
        try {
            $key = $businessKey ?? ('media:' . $mediaId);
            $attempt = (int)$this->db->fetchValue(
                "SELECT COALESCE(MAX(attempt_no),0)+1
                 FROM scheduled_job_failures
                 WHERE job_name=:job AND business_key=:key AND business_version=1",
                ['job'=>self::JOB,'key'=>$key]
            );
            $attempt = max(1, $attempt);
            $minutes = min(1440, max(1, 1 << min(10, max(0, $attempt - 1))));
            $this->db->execute(
                "INSERT INTO scheduled_job_failures(
                    job_name,business_key,business_version,scheduler_run_id,attempt_no,
                    error_class,error_message,next_retry_at,created_at
                 ) VALUES(
                    :job,:key,1,:run_id,:attempt,:error_class,:error_message,
                    DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$minutes} MINUTE),UTC_TIMESTAMP()
                 )",
                [
                    'job'=>self::JOB,'key'=>substr($key,0,128),'run_id'=>$runId,'attempt'=>$attempt,
                    'error_class'=>substr(get_class($exception),0,160),
                    'error_message'=>mb_substr($exception->getMessage(),0,2000),
                ]
            );
        } catch (Throwable $ignored) {
        }
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

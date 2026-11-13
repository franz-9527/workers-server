<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use Throwable;
use WorkerAdmin\Contracts\AdminOrderAssistanceMediaRepository;
use WorkerAdmin\Contracts\ImageOptimizer;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 压缩和对象存储 I/O 均在订单锁外，落库时再次验证指派、阶段与幂等键。 */
final class AdminOrderAssistanceMedia
{
    private AdminOrderAssistance $commands;
    private AdminOrderAssistanceMediaRepository $repository;
    private ImageOptimizer $optimizer;
    private ObjectStorage $storage;

    public function __construct(AdminOrderAssistance $commands, AdminOrderAssistanceMediaRepository $repository, ImageOptimizer $optimizer, ObjectStorage $storage)
    {
        $this->commands=$commands; $this->repository=$repository; $this->optimizer=$optimizer; $this->storage=$storage;
    }

    public function upload(array $operator, array $input, string $sourcePath): array
    {
        $command = $this->commands->mediaCommand($operator, $input);
        if (!is_file($sourcePath) || filesize($sourcePath) > 10*1024*1024) {
            throw new OrderOperationException('单张图片不能超过 10MB', 'FILE_TOO_LARGE', 422);
        }
        $image = @getimagesize($sourcePath);
        if (!$image || !in_array($image['mime'] ?? '', ['image/jpeg','image/png','image/webp'], true)) {
            throw new OrderOperationException('请上传真实的 JPG、PNG 或 WebP 图片', 'INVALID_IMAGE_TYPE', 422);
        }
        $command['file_sha256'] = hash_file('sha256', $sourcePath);
        $previous = $this->repository->prepareMedia($operator, $command);
        if ($previous !== null) return $previous;
        $optimized = $this->optimizer->optimize($sourcePath);
        try { $result = $this->repository->storeMedia($operator, $command, $optimized); }
        catch (Throwable $exception) { $this->removeLocal($optimized); throw $exception; }
        if (!empty($result['replayed'])) { $this->removeLocal($optimized); return $result; }
        // 本站私有文件已经落库；七牛不可用时保留副本，不回滚已成功的事实。
        $result['cloud_synced'] = false;
        if ($this->storage->enabled()) {
            try {
                $stored=$this->storage->store((string)$optimized['path'],(string)$optimized['storage_key'],'order',(string)$optimized['mime_type']);
                if (($stored['disk'] ?? '') === 'qiniu' && !empty($stored['key'])
                    && $this->repository->markMediaStored((string)$result['id'],(string)$stored['key'])) {
                    $result['cloud_synced']=true; $this->removeLocal($optimized);
                }
            } catch (Throwable $exception) {
                // 保留本站私有副本；客户端重试仍复用同一媒体，不重复落库。
            }
        }
        return $result;
    }

    private function removeLocal(array $optimized): void
    {
        $path=(string)($optimized['path'] ?? ''); if ($path !== '' && is_file($path)) @unlink($path);
    }
}

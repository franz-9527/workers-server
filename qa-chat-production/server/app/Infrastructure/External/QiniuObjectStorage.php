<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Services\QiniuStorageService;

final class QiniuObjectStorage implements ObjectStorage
{
    private QiniuStorageService $service;

    public function __construct(?QiniuStorageService $service = null)
    {
        $this->service = $service ?: new QiniuStorageService();
    }

    public function enabled(): bool { return $this->service->enabled(); }
    public function store(string $localPath, string $suggestedKey, string $businessType = 'general', string $mimeType = 'application/octet-stream'): array { return $this->service->store($localPath, $suggestedKey, $businessType, $mimeType); }
    public function downloadUrl(string $key, int $ttl = 900): string { return $this->service->downloadUrl($key, $ttl); }
    public function usesHttp(): bool { return $this->service->usesHttp(); }

    public function read(string $key, int $ttl = 900): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('服务器 PHP 未启用 cURL，无法读取七牛云图片。');
        $curl = curl_init($this->downloadUrl($key, $ttl));
        if ($curl === false) throw new RuntimeException('无法初始化七牛云图片读取请求。');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30]);
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new RuntimeException('七牛云 HTTP 回源失败' . ($error !== '' ? '：' . $error : '，HTTP ' . $status));
        }
        return $raw;
    }

    public function delete(string $key): void { $this->service->delete($key); }
}

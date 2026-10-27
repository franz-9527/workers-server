<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 对象存储端口描述对象生命周期与读取能力；HTTP header/echo 仍由 Http 层负责。 */
interface ObjectStorage
{
    public function enabled(): bool;
    public function store(string $localPath, string $suggestedKey, string $businessType = 'general', string $mimeType = 'application/octet-stream'): array;
    public function downloadUrl(string $key, int $ttl = 900): string;
    public function usesHttp(): bool;
    public function read(string $key, int $ttl = 900): string;
    public function delete(string $key): void;
}

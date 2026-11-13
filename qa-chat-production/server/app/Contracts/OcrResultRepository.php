<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/**
 * OCR 认证结果持久化端口。
 *
 * Application 只依赖该接口，不感知 SQL、表结构或全局数据库对象。
 */
interface OcrResultRepository
{
    public function findActorByTokenHash(string $tokenHash): ?array;

    public function touchSession(int $sessionId): void;

    public function findUsage(string $clientMsgId): ?array;

    public function countSuccessfulUsage(string $quotaDay): int;

    public function findRealNameForUpdate(int $workerId): ?array;

    public function createRealNameDraft(int $workerId): array;

    public function updateRealNameOcr(int $verificationId, string $ciphertext): void;

    public function findMerchantForUpdate(int $userId): ?array;

    public function createMerchantDraft(int $userId): array;

    /** @param array<string,mixed> $fields */
    public function updateMerchantOcr(int $merchantId, array $fields): void;

    /** @param array<string,mixed> $entry */
    public function appendUsage(array $entry): bool;
}

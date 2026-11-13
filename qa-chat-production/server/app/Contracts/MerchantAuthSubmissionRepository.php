<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MerchantAuthSubmissionRepository
{
    /** @return array<string,mixed>|null */
    public function findActorByTokenHash(string $tokenHash): ?array;
    public function touchSession(int $sessionId): void;

    /** @return array<string,mixed>|null */
    public function findProfileForUpdate(int $userId): ?array;
    public function markPending(int $merchantId, string $businessName, string $businessAddress): void;
}

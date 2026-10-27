<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface RealNameSubmissionRepository
{
    public function findActorByTokenHash(string $tokenHash): ?array;
    public function touchSession(int $sessionId): void;
    public function findVerificationForUpdate(int $workerId): ?array;
    public function hasCurrentIdMedia(int $verificationId): bool;
    public function saveDraftFields(int $workerId, string $realName, string $idNumber): void;
    public function markPending(int $verificationId, string $realName, string $idNumber): void;
}

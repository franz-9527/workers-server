<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WorkerCredentialAdminRepository
{
    /** @return array<string,mixed> */
    public function page(string $search, string $status, int $page, int $perPage): array;

    public function reviewRealName(int $workerId, string $decision, string $reason, int $adminId): void;

    public function updateRealName(int $workerId, string $realName, string $idNumber): void;

    public function reviewCollectionCode(int $id, string $decision, string $reason, int $adminId): void;
}

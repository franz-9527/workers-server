<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminAccountRepository
{
    public function passwordHash(int $adminId): ?string;

    public function replacePassword(int $adminId, string $passwordHash): void;
}

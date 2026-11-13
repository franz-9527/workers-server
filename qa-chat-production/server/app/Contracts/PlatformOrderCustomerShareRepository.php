<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PlatformOrderCustomerShareRepository
{
    /** @return array<string,mixed> */
    public function createShare(string $orderKey, int $adminUserId, int $validDays): array;

    /** @return array<string,mixed> */
    public function preview(string $token): array;

    /** @return array<string,mixed> */
    public function claim(string $token, int $userId): array;
}
<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PlatformOrderAdminRepository
{
    /** @return array<string,mixed> */
    public function dashboard(): array;

    /** @param array<string,mixed> $command */
    public function create(array $command, int $adminId): int;

    public function publish(int $orderId, int $adminId, int $quoteDays): void;

    public function selectQuote(int $orderId, int $quoteId, int $adminId): void;

    /** @return array<string,mixed> */
    public function createInvite(int $orderId, int $adminId, int $validDays): array;

    public function revokeInvite(int $orderId, int $inviteId, int $adminId): void;
}

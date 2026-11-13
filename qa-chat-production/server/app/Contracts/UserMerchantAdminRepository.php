<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface UserMerchantAdminRepository
{
    /** @return array<string,mixed> */
    public function usersPage(string $search, string $riskFilter, int $page, int $perPage, bool $includeDemo): array;

    /** @return array<string,mixed>|null */
    public function membershipTarget(int $userId, string $appId): ?array;

    public function saveMembershipGrant(int $userId, int $adminId, string $cardId, string $proofHmac, string $expiresAt, string $activationCode): int;

    public function revokeMembership(int $userId, int $adminId): int;

    /** @return array<string,mixed> */
    public function merchantsPage(string $search, string $status, int $page, int $perPage): array;

    public function reviewMerchant(int $merchantId, string $decision, string $reason, int $adminId): void;
}

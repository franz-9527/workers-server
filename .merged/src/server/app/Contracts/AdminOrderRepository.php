<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminOrderRepository
{
    public function index(array $filters): array;
    public function detail(int $orderId): array;
    /** @return array<string,mixed> */
    public function proxyCustomerActor(int $userId): array;
    public function internalIdByPublicNo(string $publicNo, int $userId): int;
    public function recordProxyCreated(int $orderId, int $adminId, string $adminName): void;
    public function create(array $input, int $adminId, string $adminName): int;
    public function update(int $orderId, array $input, int $adminId): array;
    public function audit(int $orderId, string $decision, string $reason, int $adminId): array;
    public function publish(int $orderId, bool $publish): void;
    public function cancel(int $orderId, string $reason, int $adminId): array;
    public function quote(int $orderId, array $input, int $adminId): int;
    public function assign(int $orderId, int $workerId, int $rateBp, string $reason, int $adminId): void;
    public function invite(int $orderId, array $input, int $adminId): array;
    public function revokeInvite(int $orderId, int $inviteId): void;
    public function releaseContact(int $orderId, int $adminId): void;
    public function uploadMedia(int $orderId, string $purpose, array $file, int $adminId): int;
    public function deleteMedia(int $orderId, int $mediaId): void;
    public function media(int $mediaId): array;
}

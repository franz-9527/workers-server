<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

interface OrderCancellationRiskRepository
{
    public function configuration(): array;
    public function saveConfiguration(array $rules): void;
    public function user(int $userId): ?array;
    public function state(int $userId): array;
    public function recordDial(array $actor, string $publicNo): void;
    public function reviewData(int $userId): array;
    public function restrictManually(int $userId, int $adminId, int $days, string $reason): void;
    public function release(int $userId, int $adminId, string $reason, array $excludedOrderIds = [], ?int $expectedId = null): void;
}

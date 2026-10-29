<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface SubscriptionRepository
{
    public function definitions(): array;
    public function preferences(int $userId): array;
    public function save(int $userId, string $key, bool $enabled, string $status): array;
    public function diagnose(int $userId): array;
}

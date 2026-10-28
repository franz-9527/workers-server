<?php
declare(strict_types=1);
namespace WorkerAdmin\Application;

use WorkerAdmin\Contracts\OrderCancellationRiskRepository;

final class OrderCancellationRisk
{
    private OrderCancellationRiskRepository $repository;
    public function __construct(OrderCancellationRiskRepository $repository) { $this->repository = $repository; }
    public function user(int $id): ?array { return $this->repository->user($id); }
    public function configuration(): array { return $this->repository->configuration(); }
    public function saveConfiguration(array $rules): void { $this->repository->saveConfiguration($rules); }
    public function state(int $id): array { return $this->repository->state($id); }
    public function recordDial(array $actor, string $no): void { $this->repository->recordDial($actor, $no); }
    public function reviewData(int $id): array { return $this->repository->reviewData($id); }
    public function restrictManually(int $id, int $admin, int $days, string $reason): void
    { $this->repository->restrictManually($id, $admin, $days, $reason); }
    public function release(int $id, int $admin, string $reason, array $orders, ?int $expectedId): void
    { $this->repository->release($id, $admin, $reason, $orders, $expectedId); }
}

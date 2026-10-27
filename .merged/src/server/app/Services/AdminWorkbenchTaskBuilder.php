<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Contracts\AdminWorkbenchTaskRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminWorkbenchTaskRepository;

/**
 * 旧调用兼容门面。
 *
 * 新 Http/Application 链路不再依赖 Services；订单详情等尚未迁完的旧 Infrastructure
 * 暂通过本门面复用同一个 DatabaseAdminWorkbenchTaskRepository，避免规则分叉。
 */
final class AdminWorkbenchTaskBuilder
{
    private AdminWorkbenchTaskRepository $repository;

    public function __construct(Database $db)
    {
        $this->repository = new DatabaseAdminWorkbenchTaskRepository($db);
    }

    public function summary(): array
    {
        return $this->repository->summary();
    }

    public function groupsForScope(string $scope): array
    {
        return $this->repository->groupsForScope($scope);
    }

    public function tasksForOrder(int $orderId): array
    {
        return $this->repository->tasksForOrder($orderId);
    }

    public function exceptionTasksForOrder(int $orderId): array
    {
        return $this->repository->exceptionTasksForOrder($orderId);
    }
}

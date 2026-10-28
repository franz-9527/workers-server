<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\AdminWorkbenchTaskRepository;

/** 管理员订单工作台只读用例；HTTP 不直接依赖旧 Services 或数据库实现。 */
final class AdminWorkbenchQuery
{
    private AdminWorkbenchTaskRepository $repository;

    public function __construct(AdminWorkbenchTaskRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        return $this->repository->summary();
    }

    /** @return array<int,array<string,mixed>> */
    public function groupsForScope(string $scope): array
    {
        return $this->repository->groupsForScope($scope);
    }

    /** @return array<int,array<string,mixed>> */
    public function tasksForOrder(int $orderId): array
    {
        return $this->repository->tasksForOrder($orderId);
    }

    /** @return array<int,array<string,mixed>> */
    public function exceptionTasksForOrder(int $orderId): array
    {
        return $this->repository->exceptionTasksForOrder($orderId);
    }

    /** @return array<string,mixed>|null */
    public function orderProjection(string $publicNo): ?array
    {
        return $this->repository->orderProjection($publicNo);
    }

    /** @return array<string,mixed>|null */
    public function orderProjectionById(int $orderId): ?array
    {
        return $this->repository->orderProjectionById($orderId);
    }

    /** @return array<string,mixed>|null */
    public function disputeProjectionById(int $disputeId): ?array
    {
        return $this->repository->disputeProjectionById($disputeId);
    }

    /** @return array<string,mixed>|null */
    public function contactRevealProjectionById(int $requestId): ?array
    {
        return $this->repository->contactRevealProjectionById($requestId);
    }

    /** @return array<string,mixed> */
    public function list(string $scope, int $page, int $pageSize, string $keyword): array
    {
        return $this->repository->list($scope, $page, $pageSize, $keyword);
    }

    /** @return array<string,mixed> */
    public function allOrders(int $page, int $pageSize, string $keyword): array
    {
        return $this->repository->allOrders($page, $pageSize, $keyword);
    }
}

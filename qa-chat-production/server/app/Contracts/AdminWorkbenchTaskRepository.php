<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 管理员订单工作台只读仓储；待办全部从现有业务事实实时派生。 */
interface AdminWorkbenchTaskRepository
{
    /** @return array<string,int> */
    public function summary(): array;

    /** @return array<int,array<string,mixed>> */
    public function groupsForScope(string $scope): array;

    /** @return array<int,array<string,mixed>> */
    public function tasksForOrder(int $orderId): array;

    /** @return array<int,array<string,mixed>> */
    public function exceptionTasksForOrder(int $orderId): array;

    /** @return array<string,mixed>|null */
    public function orderProjection(string $publicNo): ?array;

    /** @return array<string,mixed>|null */
    public function orderProjectionById(int $orderId): ?array;

    /** @return array<string,mixed>|null */
    public function disputeProjectionById(int $disputeId): ?array;

    /** @return array<string,mixed>|null */
    public function contactRevealProjectionById(int $requestId): ?array;

    /** @return array<string,mixed> */
    public function list(string $scope, int $page, int $pageSize, string $keyword): array;

    /** @return array<string,mixed> */
    public function allOrders(int $page, int $pageSize, string $keyword): array;
}

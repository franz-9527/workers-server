<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/**
 * 成交师傅电话公开审批的数据端口。
 */
interface OrderContactRevealRepository
{
    public function findRequestForUpdateById(int $id): ?array;

    public function findRequestForUpdateByPublicNo(string $publicNo): ?array;

    public function findOrderForUpdate(int $orderId): ?array;

    public function cancelRequest(int $requestId, ?int $reviewedBy, string $reviewNote): void;

    public function findRepair(int $afterSaleId, int $orderId): ?array;

    public function hasAppointmentResponse(
        int $orderId,
        int $workerId,
        string $contextType,
        ?int $afterSaleId
    ): bool;

    public function decideRequest(
        int $requestId,
        string $status,
        ?int $reviewedBy,
        ?string $reviewNote
    ): void;

    public function appendEvent(
        int $orderId,
        string $eventType,
        string $actorType,
        int $actorId,
        array $payload
    ): void;
}

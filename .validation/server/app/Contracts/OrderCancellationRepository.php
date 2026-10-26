<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderCancellationRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function request(array $actor, string $orderNo, string $reason, string $reasonCode): array;

    /**
     * @param array<string,mixed> $operator 带 channel 的管理员描述符：mini_admin 使用 users.id，仅写 typed event；web_admin 使用 admin_users.id，可写 decided_by/requested_by_admin_id。
     * @return array<string,mixed>
     */
    public function review(
        array $operator,
        string $orderNo,
        string $decision,
        string $workerArrived,
        string $responsibility,
        string $reason,
        int $refundCents
    ): array;
}
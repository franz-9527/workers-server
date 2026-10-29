<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderNotificationPort
{
    public function queue(
        int $orderId,
        string $idempotencyKey,
        string $eventType,
        array $payload = []
    ): void;
}

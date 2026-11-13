<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Contracts\OrderNotificationPort;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Messaging\OrderNotificationPublisher;

/**
 * 现有订单消息系统的适配器；业务用例不再直接依赖具体通知 Service。
 */
final class LegacyOrderNotificationAdapter implements OrderNotificationPort
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function queue(
        int $orderId,
        string $idempotencyKey,
        string $eventType,
        array $payload = []
    ): void {
        (new OrderNotificationPublisher(new DatabaseNotificationProducer($this->db)))->queue($orderId, $idempotencyKey, $eventType, $payload);
    }
}

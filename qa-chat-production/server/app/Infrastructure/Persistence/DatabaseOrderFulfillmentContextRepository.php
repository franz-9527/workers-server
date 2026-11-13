<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderFulfillmentContextRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class DatabaseOrderFulfillmentContextRepository implements OrderFulfillmentContextRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function order(string $publicNo): array
    {
        $publicNo = trim($publicNo);
        if ($publicNo === '') throw new OrderOperationException('订单编号缺失', 'ORDER_ID_REQUIRED', 422);
        $row = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :id LIMIT 1', ['id'=>$publicNo]);
        if (!$row) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        return $this->withPlatformPricingGate($row);
    }

    public function orderByChange(string $changePublicId): array
    {
        $row = $this->db->fetch(
            'SELECT o.* FROM change_requests c INNER JOIN orders o ON o.id=c.order_id WHERE BINARY c.public_id=BINARY :id LIMIT 1',
            ['id'=>$changePublicId]
        );
        if (!$row) throw new OrderOperationException('加价申请不存在', 'CHANGE_NOT_FOUND', 404);
        return $this->withPlatformPricingGate($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withPlatformPricingGate(array $row): array
    {
        $row['platform_pricing_reviewed'] = false;
        $row['platform_pricing_confirmed'] = false;
        $row['platform_pricing_confirmation_pending'] = false;
        if ((string)($row['order_source_type'] ?? '') !== 'platform'
            || (string)($row['workflow_profile'] ?? '') !== 'construction') {
            return $row;
        }
        $reviewed = (bool)$this->db->fetchValue(
            "SELECT id FROM order_events WHERE order_id=:order_id AND event_type='platform_measurement_pricing_reviewed' ORDER BY id DESC LIMIT 1",
            ['order_id'=>$row['id']]
        );
        if (!$reviewed) return $row;
        $confirmed = (bool)$this->db->fetchValue(
            "SELECT id FROM order_events WHERE order_id=:order_id AND event_type='platform_measurement_pricing_confirmed' ORDER BY id DESC LIMIT 1",
            ['order_id'=>$row['id']]
        );
        $row['platform_pricing_reviewed'] = true;
        $row['platform_pricing_confirmed'] = $confirmed;
        $row['platform_pricing_confirmation_pending'] = !$confirmed;
        return $row;
    }
}

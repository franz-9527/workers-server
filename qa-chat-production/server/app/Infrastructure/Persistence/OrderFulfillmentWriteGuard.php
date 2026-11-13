<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 调用方先锁 orders；页面 actions 不能代替事务内的未决事项校验。 */
final class OrderFulfillmentWriteGuard
{
    public static function assertAllowed(Database $db, array $order): void
    {
        $orderId = (int)$order['id'];
        if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none', 'failed'], true)
            || $db->fetchValue("SELECT id FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing','approved') AND closed_at IS NULL LIMIT 1 FOR UPDATE", ['id'=>$orderId])
            || $db->fetchValue("SELECT id FROM refunds WHERE order_id=:id AND status NOT IN ('failed','closed','cancelled','rejected','succeeded') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('退款或取消协商尚未结束，请先处理方案后再继续履约', 'ORDER_REFUND_PENDING', 409);
        }
        if (!in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none', 'resolved'], true)
            || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:id AND status IN ('open','pending','processing','negotiating') LIMIT 1 FOR UPDATE", ['id'=>$orderId])) {
            throw new OrderOperationException('订单异议或争议尚未结束，请先完成协商处理', 'DISPUTE_IN_PROGRESS', 409);
        }
    }
}

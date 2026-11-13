<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 平台托管单外部已收预付款维护。
 *
 * 为减少无意义类文件拆分，沿用历史类名；运行时语义已经从普通“预约费”改为平台专属
 * `order_platform_terms.booking_paid_cents`。绝不创建/修改微信支付单，也不写 orders.booking_fee_cents。
 */
final class DatabasePlatformOrderBookingFeeManager
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array{before:int,after:int} */
    public function update(int $orderId, int $prepaymentCents, int $adminId): array
    {
        if ($orderId < 1) {
            throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
        }
        if ($prepaymentCents < 0 || $prepaymentCents > 100000000) {
            throw new OrderOperationException('外部已收预付款金额无效。', 'PLATFORM_BOOKING_AMOUNT_INVALID', 422);
        }

        return $this->db->transaction(function (Database $db) use ($orderId, $prepaymentCents, $adminId): array {
            $order = $db->fetch(
                "SELECT o.id,o.order_source_type,o.lifecycle_status,o.booking_fee_cents,o.selected_quote_id,
                        o.current_assignment_id,o.assigned_worker_id,o.version,
                        t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,t.settlement_status
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$order || (string)$order['order_source_type'] !== 'platform') {
                throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            }
            if ((string)$order['customer_flow_mode'] !== 'platform_managed') {
                throw new OrderOperationException('旧小程序预约平台单必须先完成数据清理，不能继续修改托管预付款。', 'PLATFORM_BOOKING_FLOW_INVALID', 409);
            }
            if ((int)$order['booking_fee_cents'] !== 0) {
                throw new OrderOperationException('平台单仍残留普通预约费字段，请先执行资金口径清理。', 'PLATFORM_BOOKING_LEGACY_FIELD_CONFLICT', 409);
            }
            if ((string)$order['lifecycle_status'] !== 'active' || (string)$order['settlement_status'] !== 'pending') {
                throw new OrderOperationException('订单已经进入验收或结算阶段，不能再修改外部预付款。', 'PLATFORM_BOOKING_LOCKED', 409);
            }
            if (!empty($order['selected_quote_id']) || !empty($order['current_assignment_id']) || !empty($order['assigned_worker_id'])) {
                throw new OrderOperationException('订单已经选定或安排师傅，外部预付款已进入成交资金口径，不能直接修改。', 'PLATFORM_BOOKING_LOCKED', 409);
            }
            if ($prepaymentCents > (int)$order['customer_contract_total_cents']) {
                throw new OrderOperationException('外部已收预付款不能超过客户成交金额。', 'PLATFORM_TOTAL_BELOW_BOOKING', 422);
            }

            $before = (int)$order['booking_paid_cents'];
            if ($before === $prepaymentCents) {
                return ['before'=>$before,'after'=>$prepaymentCents];
            }

            $changed = $db->execute(
                "UPDATE order_platform_terms
                 SET booking_paid_cents=:prepayment,updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND settlement_status='pending' AND customer_flow_mode='platform_managed'",
                ['prepayment'=>$prepaymentCents,'id'=>$orderId]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('订单资料已发生变化，请刷新后重试。', 'PLATFORM_BOOKING_CONFLICT', 409);
            }
            $db->execute('UPDATE orders SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id AND version=:version', [
                'id'=>$orderId,'version'=>$order['version'],
            ]);

            $payload = json_encode([
                'before_cents'=>$before,
                'after_cents'=>$prepaymentCents,
                'source'=>'external_channel_received',
                'message'=>'管理员修改平台托管单外部已收预付款；不产生微信支付或退款记录。',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->insert(
                "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'platform_external_prepayment_updated','admin',:actor_id,:payload,UTC_TIMESTAMP())",
                ['order_id'=>$orderId,'actor_id'=>$adminId,'payload'=>$payload]
            );

            return ['before'=>$before,'after'=>$prepaymentCents];
        });
    }
}

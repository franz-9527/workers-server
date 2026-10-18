<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderDraftClosureRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 未付款草稿关闭只结束订单事实，不创建退款申请、不计入取消风控。
 * 与预约费创建共用 orders 行锁，避免“关闭草稿”和“创建微信支付单”并发穿透。
 */
final class DatabaseOrderDraftClosureRepository implements OrderDraftClosureRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function close(array $actor, string $orderNo): array
    {
        return $this->db->transaction(function (Database $db) use ($actor, $orderNo): array {
            $order = $db->fetch(
                'SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE',
                ['public_no' => $orderNo]
            );
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能关闭自己的未付款订单', 'FORBIDDEN', 403);
            }
            if ((string)($order['order_source_type'] ?? 'consumer') === 'platform') {
                throw new OrderOperationException('平台承接单不能按普通未付款草稿关闭', 'DRAFT_CLOSE_PLATFORM_ORDER_FORBIDDEN', 409);
            }

            $paymentStatus = (string)($order['payment_status'] ?? '');
            $lifecycle = (string)($order['lifecycle_status'] ?? '');
            $audit = (string)($order['audit_status'] ?? '');
            if ($lifecycle === 'cancelled' && $audit === 'draft' && $paymentStatus !== 'paid') {
                return ['order_id' => $orderNo, 'already_closed' => true];
            }
            if ($lifecycle !== 'active' || $audit !== 'draft' || !in_array($paymentStatus, ['unpaid', 'awaiting_payment'], true)) {
                throw new OrderOperationException('该订单已经进入正式流程，不能按未付款草稿关闭', 'DRAFT_CLOSE_NOT_ALLOWED', 409);
            }
            if (!empty($order['selected_quote_id']) || !empty($order['assigned_worker_id'])
                || !in_array((string)($order['fulfillment_status'] ?? 'not_started'), ['', 'not_started'], true)) {
                throw new OrderOperationException('订单已存在成交或履约事实，不能按未付款草稿关闭', 'DRAFT_CLOSE_HAS_DEAL_FACTS', 409);
            }

            // 过期的本地支付单可先落 closed；有效 created/pending 仍可能对应正在创建/可支付的微信预支付单，不能直接关闭订单。
            $db->execute(
                "UPDATE payment_orders
                 SET status='closed',updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id
                   AND business_type IN ('order_booking','booking_fee')
                   AND status IN ('created','pending')
                   AND expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()",
                ['order_id' => $order['id']]
            );

            $paid = $db->fetch(
                "SELECT public_no,status FROM payment_orders
                 WHERE order_id=:order_id
                   AND business_type IN ('order_booking','booking_fee')
                   AND status IN ('paid','refunded')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id' => $order['id']]
            );
            if ($paid) {
                throw new OrderOperationException(
                    '检测到预约费已经到账，不能按未付款草稿关闭；请刷新订单后按退款流程处理。',
                    'DRAFT_CLOSE_PAYMENT_ALREADY_PAID',
                    409,
                    ['payment_no' => (string)$paid['public_no']]
                );
            }

            $pending = $db->fetch(
                "SELECT public_no,status,expires_at FROM payment_orders
                 WHERE order_id=:order_id
                   AND business_type IN ('order_booking','booking_fee')
                   AND status IN ('created','pending')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id' => $order['id']]
            );
            if ($pending) {
                throw new OrderOperationException(
                    '预约费支付结果仍在确认中。为避免关闭后发生迟到到账，请等待本次支付失效或完成后再处理。',
                    'DRAFT_CLOSE_PAYMENT_PENDING',
                    409,
                    [
                        'payment_no' => (string)$pending['public_no'],
                        'payment_status' => (string)$pending['status'],
                        'expires_at' => $pending['expires_at'] ?? null,
                    ]
                );
            }

            $db->execute(
                "UPDATE orders
                 SET lifecycle_status='cancelled',publish_status='hidden',quote_status='cancelled',assignment_status='cancelled',
                     cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id",
                ['id' => $order['id']]
            );
            $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id' => $order['id']]);
            $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id' => $order['id']]);
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id' => $order['id']]);
            $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'", ['id' => $order['id']]);
            $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id' => $order['id']]);

            $db->insert(
                "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'draft_closed',:event_key,'user',:actor_id,:payload,UTC_TIMESTAMP())",
                [
                    'order_id' => $order['id'],
                    'event_key' => strtolower(\business_no('EV')),
                    'actor_id' => (int)$actor['user_id'],
                    'payload' => json_encode([
                        'message' => '用户主动关闭未付款订单；未创建退款申请',
                        'payment_status_before' => $paymentStatus,
                        'audit_status_before' => $audit,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );

            return ['order_id' => $orderNo, 'already_closed' => false];
        });
    }
}

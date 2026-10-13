<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class PlatformOrderBookingPaymentVerifier
{
    public function verifiedPaidCents(
        Database $db,
        int $orderId,
        int $customerTotalCents,
        ?int $expectedCents = null,
        ?string $customerFlowMode = null
    ): int {
        if ($orderId <= 0 || $customerTotalCents <= 0) {
            throw new OrderOperationException('平台承接订单金额或编号无效', 'PLATFORM_BOOKING_INVALID', 409);
        }
        if ($expectedCents !== null && ($expectedCents < 0 || $expectedCents > $customerTotalCents)) {
            throw new OrderOperationException('订单约定预约费金额异常，请先核对订单资金条件', 'PLATFORM_BOOKING_AMOUNT_MISMATCH', 409);
        }
        $flow = trim((string)($customerFlowMode ?? ''));
        if ($flow === '') {
            if ((int)($expectedCents ?? 0) === 0) {
                $flow = 'platform_managed';
            } else {
                try {
                    $storedFlow = $db->fetchValue(
                        'SELECT customer_flow_mode FROM order_platform_terms WHERE order_id=:order_id LIMIT 1 FOR UPDATE',
                        ['order_id'=>$orderId]
                    );
                    $flow = trim((string)$storedFlow);
                } catch (Throwable $exception) {
                    $flow = 'miniapp_booking';
                }
                if ($flow === '') $flow = 'miniapp_booking';
            }
        }
        if (!in_array($flow, ['platform_managed','miniapp_booking'], true)) {
            throw new OrderOperationException('平台承接单客户参与方式异常，请先核对订单', 'PLATFORM_BOOKING_FLOW_INVALID', 409);
        }
        $payments = $db->fetchAll(
            "SELECT id,amount_cents FROM payment_orders
             WHERE order_id=:order_id AND business_type IN ('order_booking','booking_fee')
               AND status='paid' ORDER BY id FOR UPDATE",
            ['order_id'=>$orderId]
        );
        if ($flow === 'platform_managed') {
            if ($payments) {
                throw new OrderOperationException('平台托管单检测到额外微信预约费付款，请先核对是否重复收款', 'PLATFORM_BOOKING_UNEXPECTED_PAYMENT', 409);
            }
            return max(0, (int)($expectedCents ?? 0));
        }
        if ((int)($expectedCents ?? 0) === 0) {
            if ($payments) {
                throw new OrderOperationException('本单未约定预约费但检测到真实预约费付款，请先核对资金', 'PLATFORM_BOOKING_UNEXPECTED_PAYMENT', 409);
            }
            return 0;
        }
        if (!$payments) {
            throw new OrderOperationException('没有真实支付成功的预约费，不能执行平台单资金结算', 'PLATFORM_BOOKING_NOT_PAID', 409);
        }
        $total = 0;
        foreach ($payments as $payment) {
            $amount = (int)($payment['amount_cents'] ?? 0);
            if ($amount <= 0 || $amount > $customerTotalCents || $total > $customerTotalCents - $amount) {
                throw new OrderOperationException('预约费支付金额异常，不能自动截断或重复抵扣', 'PLATFORM_BOOKING_AMOUNT_MISMATCH', 409);
            }
            $total += $amount;
        }
        if ($expectedCents !== null && $total !== $expectedCents) {
            throw new OrderOperationException('真实预约费与订单约定金额不一致，请先核对支付流水', 'PLATFORM_BOOKING_AMOUNT_MISMATCH', 409);
        }
        $refunds = $db->fetchAll(
            "SELECT r.id,r.status,r.amount_cents FROM refunds r
             INNER JOIN payment_orders p ON p.id=r.payment_order_id
             WHERE p.order_id=:order_id AND p.business_type IN ('order_booking','booking_fee')
               AND p.status='paid'
               AND r.status NOT IN ('failed','closed','cancelled','rejected')
             ORDER BY r.id FOR UPDATE",
            ['order_id'=>$orderId]
        );
        foreach ($refunds as $refund) {
            if ((string)$refund['status'] === 'succeeded') {
                throw new OrderOperationException('预约费已发生退款，原结算金额不能继续使用，请先核对资金', 'PLATFORM_BOOKING_REFUNDED', 409);
            }
            throw new OrderOperationException('预约费存在未终结的退款申请，暂不能确认订单结算', 'PLATFORM_BOOKING_REFUND_PENDING', 409);
        }
        return $total;
    }
}

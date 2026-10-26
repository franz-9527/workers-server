<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PaidPaymentSettlement
{
    /** Apply one verified SUCCESS payment resource inside the caller's transaction and return payment_order id. */
    public function settle(array $resource): ?int;

    /** Return the unique automatic refund created by a late/invalid paid payment, if any. */
    public function pendingAutomaticRefund(string $paymentPublicNo): ?array;

    public function markAutomaticRefundProcessing(int $refundId, ?string $wechatRefundId): void;

    /** Apply a verified SUCCESS refund result; used by synchronous createRefund success and refund notifications. */
    public function applyImmediateRefundSuccess(array $resource, string $outRefundNo): void;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PlatformOrderFinanceRepository
{
    /** @return array<string,mixed> */
    public function dashboard(): array;

    public function approveChange(int $changeId, int $customerAddCents, string $note, int $adminId): void;

    public function rejectChange(int $changeId, string $note, int $adminId): void;

    /** Confirm an actual externally received platform-income payment, never create a synthetic payment record. */
    public function markIncomePaid(int $orderId, int $confirmedAmountCents, string $paymentReference, string $note, int $adminId): void;

    /** A successful, post-acceptance refund always requires explicit human reconciliation. */
    public function openRefundReview(int $refundId, int $adminId): void;

    /** Allocate refund responsibility without automatically reversing previously credited/withdrawn worker income. */
    public function decideRefundReview(int $reviewId, string $responsibility, int $workerRecoveryCents, string $note, int $adminId): void;

    /** One verified external bank receipt per installment, including a partial recovery. */
    public function confirmRefundRecovery(int $reviewId, int $amountCents, string $paymentReference, string $note, int $adminId): void;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminFinanceRepository
{
    /** Acquire the legacy demo-write named lock and reject DEMO business when demo data is disabled. */
    public function guardDemoWrite(string $route, int $id): void;

    /** @return array<string,mixed> */
    public function resourcePage(string $resource, int $page, string $status, string $search, string $businessType, bool $includeDemo): array;

    /** @return array<string,int> */
    public function paymentStats(bool $includeDemo): array;

    /** @return array<string,mixed> */
    public function wallets(int $page, int $walletId, string $search, bool $includeDemo): array;

    /** @return array<string,mixed> */
    public function tailSettlement(string $search, bool $includeDemo): array;

    /** @return array<string,mixed>|null */
    public function walletRechargeSnapshot(int $rechargeId): ?array;

    /** @return array<string,mixed>|null */
    public function lockWalletRecharge(int $rechargeId): ?array;

    /** @return array<string,mixed>|null */
    public function lockPayment(int $paymentId): ?array;

    /** @return array<string,mixed>|null */
    public function activeRefundForPayment(int $paymentId): ?array;

    public function markRechargeRefunded(int $rechargeId): void;
    public function markRechargeRefundProcessing(int $rechargeId): void;

    /** @return array<string,mixed> */
    public function createRechargeRefund(int $paymentId, int $amountCents, string $reason, int $adminId): array;

    /** @return array<string,mixed>|null */
    public function lockRefund(int $refundId): ?array;
    public function updateRefundAmount(int $refundId, int $amountCents): void;

    /** @return array<string,int> */
    public function bookingFeeAllocations(int $paymentId, int $orderId, int $excludeRefundId): array;

    public function approveRefundForSubmission(array $refund, array $payment, int $adminId): void;
    public function markRefundSubmitted(int $refundId, ?int $orderId, string $wechatRefundId): void;
    public function markRefundSubmitFailed(int $refundId, string $message): void;
    public function markRechargeRefundSubmitted(int $refundId, string $wechatRefundId): void;
    public function markRechargeRefundUncertain(int $refundId, string $message): void;

    /** @return array<string,mixed> */
    public function transitionWithdrawalLocked(int $id, string $target, string $externalTradeNo, string $note, int $adminId): array;

    /** @return array<string,mixed> */
    public function markTailBadDebtLocked(int $id, string $note, int $adminId): array;

    /** @return array<string,mixed> */
    public function reviewTailSettlementLocked(int $id, string $target, string $note, int $adminId): array;
}

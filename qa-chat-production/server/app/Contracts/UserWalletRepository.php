<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 用户钱包持久化端口；所有 SQL 和表结构均留在 Infrastructure。 */
interface UserWalletRepository
{
    public function ensureWallet(int $userId): void;

    public function findWallet(int $userId): ?array;

    public function lockWallet(int $userId): array;

    public function ledgerPage(int $userId, int $limit, int $offset): array;

    public function ledgerCount(int $userId): int;

    public function findLedgerBalanceByIdempotency(string $idempotencyKey): ?array;

    public function updateWalletBalances(
        int $walletId,
        int $beforeAvailable,
        int $beforeReserved,
        int $afterAvailable,
        int $afterReserved
    ): bool;

    public function appendLedger(array $entry): void;

    public function reservationUserId(int $reservationId): ?int;

    public function lockReservation(int $reservationId): ?array;

    public function transitionReservation(int $reservationId, string $fromStatus, string $toStatus): bool;

    public function findRefundReservationForUpdate(string $refundPublicNo): ?array;

    public function findRefundReservationUserId(int $refundId, string $refundPublicNo): ?int;

    public function findRefundReservationByBusinessForUpdate(int $refundId, string $refundPublicNo): ?array;

    public function createRefundReservation(
        int $userId,
        int $refundId,
        string $refundPublicNo,
        int $amountCents
    ): array;

    public function markRechargeRefunded(int $rechargeId): void;

    public function restoreRechargePaid(int $rechargeId): void;
}

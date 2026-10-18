<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WalletRechargeRepository
{
    public function findActorByTokenHash(string $tokenHash): ?array;
    public function touchSession(int $sessionId): void;
    public function findOwnedPayment(string $paymentNo, int $userId): ?array;
    public function expiredPendingPayments(int $limit): array;
    public function closeUnpaid(int $paymentId, int $rechargeId): bool;
}

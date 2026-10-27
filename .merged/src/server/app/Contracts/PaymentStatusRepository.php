<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PaymentStatusRepository
{
    /** @return array<string,mixed>|null */
    public function findOwned(string $paymentPublicNo, int $payerUserId): ?array;

    /** @return array<string,mixed>|null */
    public function findById(int $paymentId): ?array;

    /** Close an unpaid external payment and release any local pending payment shell that must be recreated. */
    public function closeExternallyFailed(int $paymentId): void;
}

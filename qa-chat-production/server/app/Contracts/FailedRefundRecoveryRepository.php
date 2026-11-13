<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface FailedRefundRecoveryRepository
{
    /** @return array<int,array<string,mixed>> */
    public function cases(): array;

    /** @return array<string,mixed> */
    public function detail(int $refundId): array;

    /** @return array<string,mixed> */
    public function recheck(int $refundId, int $adminId): array;

    /** CLOSED 为终态时，按原支付、原金额生成新的 created 退款单。 @return array<string,mixed> */
    public function replaceClosed(int $refundId, int $adminId): array;
}

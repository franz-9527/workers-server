<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WithdrawalSubmittedNotifier
{
    public function notifySubmitted(int $withdrawalRequestId, string $publicNo, int $workerId, int $amountCents): void;
}

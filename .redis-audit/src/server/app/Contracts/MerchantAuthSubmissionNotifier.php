<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MerchantAuthSubmissionNotifier
{
    public function notifySubmitted(int $merchantId, string $businessName, string $businessAddress): void;
}

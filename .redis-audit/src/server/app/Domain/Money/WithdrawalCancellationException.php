<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Money;

use RuntimeException;

final class WithdrawalCancellationException extends RuntimeException
{
    private string $apiCode;

    public function __construct(string $message, string $apiCode, int $httpStatus = 422)
    {
        parent::__construct($message, $httpStatus);
        $this->apiCode = $apiCode;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

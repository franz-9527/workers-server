<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Money;

use RuntimeException;
use Throwable;

final class CommissionPaymentCreationException extends RuntimeException
{
    private string $apiCode;

    public function __construct(string $message, string $apiCode, int $httpStatus, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->apiCode = $apiCode;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

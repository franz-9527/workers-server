<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Money;

use RuntimeException;
use Throwable;

/** 余额充值创建的稳定业务错误，保留旧 API code/status。 */
final class WalletRechargeCreationException extends RuntimeException
{
    private string $apiCode;

    public function __construct(string $message, string $apiCode, int $httpStatus = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->apiCode = $apiCode;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

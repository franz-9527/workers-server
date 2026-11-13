<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Money;

use RuntimeException;

/** Money 用户侧板块的稳定业务异常。 */
final class MoneyOperationException extends RuntimeException
{
    private string $apiCode;

    public function __construct(string $message, string $apiCode, int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
        $this->apiCode = $apiCode;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

use RuntimeException;
use Throwable;

final class OrderOperationException extends RuntimeException
{
    private string $apiCode;
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(string $message, string $apiCode, int $httpStatus = 422, array $data = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
        $this->apiCode = $apiCode;
        $this->data = $data;
    }

    public function apiCode(): string { return $this->apiCode; }
    /** @return array<string,mixed> */
    public function data(): array { return $this->data; }
}
<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

use RuntimeException;

final class MessageOperationException extends RuntimeException
{
    private string $apiCode;
    public function __construct(string $message, string $apiCode, int $status = 422)
    {
        parent::__construct($message, $status);
        $this->apiCode = $apiCode;
    }
    public function apiCode(): string { return $this->apiCode; }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Identity;

use RuntimeException;

final class IdentityOperationException extends RuntimeException
{
    private string $apiCode;
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(string $message, string $apiCode, int $status = 422, array $data = [])
    {
        parent::__construct($message, $status);
        $this->apiCode = $apiCode;
        $this->data = $data;
    }

    public function apiCode(): string { return $this->apiCode; }
    /** @return array<string,mixed> */
    public function data(): array { return $this->data; }
}

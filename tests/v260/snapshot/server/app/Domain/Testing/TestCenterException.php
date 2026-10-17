<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Testing;

use RuntimeException;

final class TestCenterException extends RuntimeException
{
    private string $businessCode;
    private int $httpStatus;
    /** @var array<string,mixed> */
    private array $details;

    /** @param array<string,mixed> $details */
    public function __construct(string $message, string $businessCode = 'TEST_CENTER_ERROR', int $httpStatus = 409, array $details = [])
    {
        parent::__construct($message);
        $this->businessCode = $businessCode;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
    }

    public function businessCode(): string { return $this->businessCode; }
    public function httpStatus(): int { return $this->httpStatus; }
    /** @return array<string,mixed> */
    public function details(): array { return $this->details; }
}

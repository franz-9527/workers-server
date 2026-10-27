<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AgreedRefundExecutionRepository
{
    /** @return array<int,array<string,mixed>> */
    public function cases(): array;

    /** @return array<string,mixed> */
    public function prepare(string $requestNo, string $note, int $adminId): array;
}

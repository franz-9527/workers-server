<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MiniPlatformOrderCreationRepository
{
    /** @return array<int,array<string,mixed>> */
    public function categories(): array;

    /** Only public identifiers and masked contact data. @return array<int,array<string,mixed>> */
    public function customers(string $query): array;

    /** @return array<string,mixed> */
    public function create(array $input, int $operatorUserId, string $requestId): array;
}

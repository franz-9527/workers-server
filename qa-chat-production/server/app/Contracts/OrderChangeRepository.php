<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderChangeRepository
{
    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function create(array $actor, array $order, int $addedAmountCents, string $reason): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function decide(array $actor, array $order, string $changePublicId, string $decision, string $note): array;
}

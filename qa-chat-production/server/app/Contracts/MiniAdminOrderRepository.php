<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MiniAdminOrderRepository
{
    /** @return array<string,mixed> */
    public function findOrder(string $publicNo): array;

    /** @return array<string,mixed> */
    public function performOrderAction(array $actor, array $order, array $command): array;

    /** @return array<string,mixed> */
    public function savePlatformQuote(array $actor, array $order, int $amountCents, string $description): array;

    /** @return array<string,mixed> */
    public function createShare(array $actor, array $order, array $command): array;
}

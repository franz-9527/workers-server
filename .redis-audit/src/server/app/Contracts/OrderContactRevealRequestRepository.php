<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderContactRevealRequestRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function request(array $actor, string $orderNo, string $reason): array;
}

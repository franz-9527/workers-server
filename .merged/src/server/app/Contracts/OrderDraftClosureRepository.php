<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderDraftClosureRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function close(array $actor, string $orderNo): array;
}

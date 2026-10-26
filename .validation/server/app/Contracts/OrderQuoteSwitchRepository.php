<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderQuoteSwitchRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array{message:string,data:array<string,mixed>}
     */
    public function switchWorker(array $actor, string $orderPublicNo, string $quotePublicId, string $reason): array;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderQuoteSelectionRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array{message:string,data:array<string,mixed>}
     */
    public function select(array $actor, string $quotePublicId): array;
}

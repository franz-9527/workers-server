<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderDetailRepository
{
    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function view(array $actor, string $publicNo, array $context = []): array;
}

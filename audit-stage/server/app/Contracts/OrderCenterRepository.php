<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderCenterRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array{rows:array<int,array<string,mixed>>,has_more:bool}
     */
    public function page(array $actor, string $kind, string $status, int $page, int $limit, string $keyword = ''): array;

    /** @param array<string,mixed> $actor */
    public function supplementCount(array $actor, string $kind): int;

    /** @param array<string,mixed> $actor */
    public function todoCount(array $actor, string $kind): int;
}
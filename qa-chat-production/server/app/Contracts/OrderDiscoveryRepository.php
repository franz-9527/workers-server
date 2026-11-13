<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderDiscoveryRepository
{
    /**
     * @param array<string,mixed> $actor
     * @return array{rows:array<int,array<string,mixed>>,has_more:bool}
     */
    public function hallPage(
        array $actor,
        int $categoryId,
        string $sort,
        ?float $lat,
        ?float $lng,
        int $page,
        int $limit
    ): array;
}

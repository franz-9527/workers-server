<?php

declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface OrderCenterRepository
{
    public function page(array $actor, string $kind, string $status, int $page, int $limit, string $keyword = ''): array;
    public function supplementCount(array $actor, string $kind): int;
    public function todoCount(array $actor, string $kind): int;
}

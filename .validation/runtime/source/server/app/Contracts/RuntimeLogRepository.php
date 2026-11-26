<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface RuntimeLogRepository
{
    public function dashboard(string $channel, string $q, int $page): array;
    public function purgeOlderThan(int $days, string $category = ''): array;
    public function clearCategory(string $category): array;
    public function clear(string $channel): array;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WorkerOrderDiscountRepository
{
    public function status(array $actor): ?array;
    public function submit(array $actor): array;
    public function page(string $status, int $page): array;
    public function review(int $worker, string $decision, string $reason, array $checks, int $admin): void;
}

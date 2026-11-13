<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminOrderAssistanceRepository
{
    public function context(string $orderNo): array;
    public function save(array $operator, array $command): array;
}

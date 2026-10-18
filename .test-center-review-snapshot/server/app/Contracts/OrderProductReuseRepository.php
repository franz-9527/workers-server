<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

interface OrderProductReuseRepository
{
    public function history(array $actor, array $query): array;
}

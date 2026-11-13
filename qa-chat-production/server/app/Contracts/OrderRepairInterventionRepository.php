<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderRepairInterventionRepository
{
    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function request(array $actor, string $orderNo, string $afterSaleNo, string $note): array;
}

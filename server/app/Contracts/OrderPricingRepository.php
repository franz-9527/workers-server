<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderPricingRepository
{
    /**
     * Update actual quantities for base pricing rows on an itemized platform order.
     * Unit prices remain immutable; this operation is not an add-price request.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $order
     * @param array<int,array{sort_order:int,quantity:float}> $items
     * @return array<string,mixed>
     */
    public function updateActualQuantities(array $actor, array $order, array $items): array;
}

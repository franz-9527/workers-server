<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderFulfillmentContextRepository
{
    /** @return array<string,mixed> */
    public function order(string $publicNo): array;

    /** @return array<string,mixed> */
    public function orderByChange(string $changePublicId): array;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PublicStoreContactGateway
{
    public function fetchMobile(string $storeId): string;
}

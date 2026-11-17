<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WorkerCloudGateway
{
    /** @return array<string,mixed> */
    public function invoke(string $action, array $payload = []): array;
}

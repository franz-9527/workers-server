<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface ActiveSessionGateway
{
    public function assertActive(string $token): void;
}

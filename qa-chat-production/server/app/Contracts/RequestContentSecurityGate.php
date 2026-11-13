<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface RequestContentSecurityGate
{
    public function inspect(string $route): void;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin;

interface AdminModule
{
    public function supports(string $route): bool;

    public function dispatch(string $route): void;
}

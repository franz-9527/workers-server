<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use WorkerAdmin\Controllers\HelpCenterApiController;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Api\ApiModule;

final class HelpCenterApiModule implements ApiModule
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function supports(string $route): bool
    {
        return strpos($route, 'help.') === 0;
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        (new HelpCenterApiController($this->db))->dispatch($route);
    }
}

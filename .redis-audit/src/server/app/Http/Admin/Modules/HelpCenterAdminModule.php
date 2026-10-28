<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use WorkerAdmin\Controllers\HelpCenterAdminController;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

final class HelpCenterAdminModule implements AdminModule
{
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->db = $db;
        $this->auth = $auth;
        $this->csrf = $csrf;
    }

    public function supports(string $route): bool
    {
        return strpos($route, 'help') === 0;
    }

    public function dispatch(string $route): void
    {
        (new HelpCenterAdminController($this->db, $this->auth, $this->csrf))->dispatch($route);
    }
}

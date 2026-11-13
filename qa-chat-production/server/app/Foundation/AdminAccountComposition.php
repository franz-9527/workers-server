<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminAccountSecurity;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminAccountRepository;

final class AdminAccountComposition
{
    public static function build(Database $db): AdminAccountSecurity
    {
        return new AdminAccountSecurity(new DatabaseAdminAccountRepository($db));
    }

    private function __construct()
    {
    }
}

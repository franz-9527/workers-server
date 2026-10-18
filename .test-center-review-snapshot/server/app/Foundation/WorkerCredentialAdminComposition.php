<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\WorkerCredentialAdmin;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWorkerCredentialAdminRepository;

final class WorkerCredentialAdminComposition
{
    public static function build(Database $db): WorkerCredentialAdmin
    {
        return new WorkerCredentialAdmin(new DatabaseWorkerCredentialAdminRepository($db));
    }

    private function __construct()
    {
    }
}

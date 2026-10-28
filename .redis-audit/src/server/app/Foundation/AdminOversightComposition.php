<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminOversightQuery;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOversightRepository;

final class AdminOversightComposition
{
    public static function build(Database $db): AdminOversightQuery
    {
        return new AdminOversightQuery(new DatabaseAdminOversightRepository($db));
    }
}

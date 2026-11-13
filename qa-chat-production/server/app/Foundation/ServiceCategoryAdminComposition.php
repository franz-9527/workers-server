<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Catalog\ServiceCategoryAdmin;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseServiceCategoryAdminRepository;

final class ServiceCategoryAdminComposition
{
    public static function build(Database $db): ServiceCategoryAdmin
    {
        return new ServiceCategoryAdmin(new DatabaseServiceCategoryAdminRepository($db));
    }
}

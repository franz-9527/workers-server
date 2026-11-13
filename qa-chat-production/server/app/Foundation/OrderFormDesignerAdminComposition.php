<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Form\OrderFormDesignerAdmin;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFormDesignerAdminRepository;

final class OrderFormDesignerAdminComposition
{
    public static function build(Database $db): OrderFormDesignerAdmin
    {
        $container = new ApplicationContainer($db);
        return new OrderFormDesignerAdmin(new DatabaseOrderFormDesignerAdminRepository(
            $db,
            $container->formSystemFields(),
            $container->formSnapshotBuilder()
        ));
    }

    private function __construct()
    {
    }
}

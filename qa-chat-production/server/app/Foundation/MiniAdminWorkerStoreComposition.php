<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\MiniAdminWorkerStoreOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\LegacyWorkerCloudGateway;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminWorkerStoreRepository;

/** Mini Admin Worker / Store 板块组合根。 */
final class MiniAdminWorkerStoreComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container = new ApplicationContainer($db);
        $repository = new DatabaseMiniAdminWorkerStoreRepository($db, new LegacyWorkerCloudGateway());
        return [
            'actors'=>$container->apiActorResolver(),
            'operations'=>new MiniAdminWorkerStoreOperations($repository),
        ];
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\WorkerAdmin;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\LegacyWorkerCloudGateway;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminWorkerRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseIdentityRepository;

final class WorkerAdminComposition
{
    public static function build(Database $db): WorkerAdmin
    {
        $container = new ApplicationContainer($db);
        $settings = $container->settings();
        $cipher = $container->privateCipher();
        $identity = new DatabaseIdentityRepository($db, $cipher, $settings);
        $cloud = new LegacyWorkerCloudGateway();
        $repository = new DatabaseAdminWorkerRepository($db, $identity, $settings, $cipher);
        $runtimeSync = (new ConfigurationApplicationContainer($db))->runtimeSync();
        return new WorkerAdmin($repository, $cloud, $runtimeSync);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Identity\BootstrapReadModel;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\LegacyWorkerCloudGateway;
use WorkerAdmin\Infrastructure\Persistence\DatabaseBootstrapRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseIdentityRepository;

final class BootstrapComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container=new ApplicationContainer($db);$settings=$container->settings();$cipher=$container->privateCipher();
        $identityRepo=new DatabaseIdentityRepository($db,$cipher,$settings);
        return [
            'actors'=>$container->apiActorResolver(),
            'reader'=>new BootstrapReadModel(
                new DatabaseBootstrapRepository($db),$identityRepo,new LegacyWorkerCloudGateway(),$settings,$container->actorCapabilities()
            ),
        ];
    }
}

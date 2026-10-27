<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Identity\MiniAdminDemoOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminDemoRepository;
use WorkerAdmin\Infrastructure\Testing\TestCenterEntrySession;

final class MiniAdminDemoComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container=new ApplicationContainer($db);$settings=$container->settings();$actors=$container->apiActorResolver();
        return [
            'actors'=>$actors,
            'operations'=>new MiniAdminDemoOperations(new DatabaseMiniAdminDemoRepository($db,$settings),$settings),
            'test_entry'=>new TestCenterEntrySession($db),
        ];
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\MiniAdminOrderOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminOrderRepository;

final class MiniAdminOrderComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container=new ApplicationContainer($db);$settings=$container->settings();
        return [
            'actors'=>$container->apiActorResolver(),
            'operations'=>new MiniAdminOrderOperations(
                new DatabaseMiniAdminOrderRepository($db,(string)\app_config('app.timezone','Asia/Shanghai')),
                $settings,
                (string)\app_config('app.timezone','Asia/Shanghai')
            ),
        ];
    }
}

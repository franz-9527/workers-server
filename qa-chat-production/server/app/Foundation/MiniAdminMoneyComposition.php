<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Money\MiniAdminMoneyOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniAdminMoneyRepository;

final class MiniAdminMoneyComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container = new ApplicationContainer($db);
        return [
            'actors'=>$container->apiActorResolver(),
            'operations'=>new MiniAdminMoneyOperations(
                new DatabaseMiniAdminMoneyRepository($db),
                (string)\app_config('app.timezone', 'Asia/Shanghai')
            ),
        ];
    }
}

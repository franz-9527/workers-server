<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Identity\MerchantProfileReader;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMerchantProfileRepository;

final class MerchantComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container=new ApplicationContainer($db);
        return [
            'actors'=>$container->apiActorResolver(),
            'submission'=>$container->merchantAuthSubmission(),
            'profile'=>new MerchantProfileReader(new DatabaseMerchantProfileRepository($db),$container->settings()),
        ];
    }
}

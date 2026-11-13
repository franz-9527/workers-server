<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Media\MediaOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Media\LegacyImageOptimizer;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMediaRepository;

/** Media 板块统一组合根。 */
final class MediaComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container=new ApplicationContainer($db);
        $storage=$container->objectStorage();
        return [
            'actors'=>$container->apiActorResolver(),
            'storage'=>$storage,
            'operations'=>new MediaOperations(
                new DatabaseMediaRepository($db,$container->settings(),$container->privateCipher()),
                new LegacyImageOptimizer(),
                $storage,
                $container->transaction()
            ),
        ];
    }
}

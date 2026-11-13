<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Agreements\AgreementAcknowledgementRecorder;
use WorkerAdmin\Application\Identity\IdentityAccessOperations;
use WorkerAdmin\Application\Identity\WorkerDirectoryOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\LegacyWeChatIdentityGateway;
use WorkerAdmin\Infrastructure\External\LegacyWorkerCloudGateway;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAgreementAcknowledgementAudit;
use WorkerAdmin\Infrastructure\Persistence\DatabaseIdentityRepository;

/** Identity / Worker 前端板块统一组合根。 */
final class IdentityWorkerComposition
{
    /** @return array<string,object> */
    public static function build(Database $db): array
    {
        $container=new ApplicationContainer($db);
        $settings=$container->settings();$cipher=$container->privateCipher();
        $repository=new DatabaseIdentityRepository($db,$cipher,$settings);
        $wechat=new LegacyWeChatIdentityGateway();$workerCloud=new LegacyWorkerCloudGateway();
        return [
            'actors'=>$container->apiActorResolver(),
            'identity'=>new IdentityAccessOperations($repository,$wechat,$workerCloud),
            'workers'=>new WorkerDirectoryOperations($repository,$workerCloud,$settings,$cipher),
            'agreement_acknowledgements'=>new AgreementAcknowledgementRecorder(new DatabaseAgreementAcknowledgementAudit($db)),
        ];
    }
}

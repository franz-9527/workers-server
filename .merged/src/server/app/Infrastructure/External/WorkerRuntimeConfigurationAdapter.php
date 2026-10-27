<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\WorkerRuntimeConfigurationGateway;
use WorkerAdmin\Services\WorkerCloudGatewayService;

final class WorkerRuntimeConfigurationAdapter implements WorkerRuntimeConfigurationGateway
{

    public function sync(array $values):array { return (new WorkerCloudGatewayService())->invoke('server.runtimeConfigSync',$values); }
}

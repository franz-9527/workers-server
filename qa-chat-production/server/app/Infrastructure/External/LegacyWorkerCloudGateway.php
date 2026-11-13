<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\WorkerCloudGateway;
use WorkerAdmin\Services\WorkerCloudGatewayService;

final class LegacyWorkerCloudGateway implements WorkerCloudGateway
{
    private WorkerCloudGatewayService $service;

    public function __construct(?WorkerCloudGatewayService $service = null)
    {
        $this->service = $service ?: new WorkerCloudGatewayService();
    }

    public function invoke(string $action, array $payload = []): array
    {
        return $this->service->invoke($action, $payload);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

final class MiniPlatformOrderCreationApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    private OrderApplicationContainer $container;

    public function __construct(Database $db) { $this->container = new OrderApplicationContainer($db); }

    public function supports(string $route): bool
    {
        return in_array($route, ['admin.platform.orders.create-options','admin.platform.orders.customers','admin.platform.orders.create'], true);
    }

    public function requiresContentSecurity(string $route): bool { return false; }

    public function dispatch(string $route): void
    {
        try {
            $creating = $route === 'admin.platform.orders.create';
            $this->orderRequireMethod($creating ? 'POST' : 'GET');
            $actor = $this->orderActor($this->container);
            $service = $this->container->miniPlatformOrderCreation();
            if ($creating) $data = $service->create($actor, $this->orderJsonBody());
            elseif ($route === 'admin.platform.orders.customers') $data = $service->customers($actor, (string)($_GET['q'] ?? ''));
            else {
                $data = $service->options($actor);
                $data['form'] = $this->container->submission()->form($actor);
            }
            \json_response(['ok'=>true,'message'=>$creating ? '平台承接单已创建，系统核验后自动发布' : '', 'data'=>$data]);
        } catch (Throwable $exception) { $this->orderHandleException($exception); }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

final class OrderNegotiationApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    private OrderApplicationContainer $container;
    public function __construct(Database $db) { $this->container = new OrderApplicationContainer($db); }
    public function supports(string $route): bool { return in_array($route, ['exceptions.respond','exceptions.escalate','refunds.propose','refunds.withdraw','after_sales.repair.respond'], true); }
    public function requiresContentSecurity(string $route): bool { return true; }
    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        try {
            $result = $this->container->negotiation()->execute($route, $actor, $this->orderJsonBody());
            \json_response(['ok'=>true,'message'=>$result['message'],'data'=>$result['data']]);
        } catch (Throwable $exception) { $this->orderHandleException($exception); }
    }
}

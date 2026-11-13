<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 我的下单 / 我的接单统一订单中心。 */
final class OrderCenterApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'orders.center';
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        try {
            $this->orderRequireMethod('GET');
            $actor = $this->orderActor($this->container);
            $kind = trim((string)($_GET['kind'] ?? 'mine'));
            $status = trim((string)($_GET['status'] ?? 'all'));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $keyword = trim((string)($_GET['keyword'] ?? ''));
            $data = $this->container->center()->query($actor, $kind, $status, $page, $keyword);
            \json_response(['ok' => true, 'data' => $data]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

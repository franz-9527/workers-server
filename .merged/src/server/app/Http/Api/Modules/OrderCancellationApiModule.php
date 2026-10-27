<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** V2.5.0 用户取消/退款统一入口；管理员退款审核复用同一取消领域。 */
final class OrderCancellationApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['orders.cancel.request','admin.refund.review'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return true;
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        if ($route === 'admin.refund.review') {
            $this->requireWorkbenchAdmin($this->container, $actor);
        }
        $body = $this->orderJsonBody();
        try {
            $result = $route === 'admin.refund.review'
                ? $this->container->cancellation()->review($actor, $body)
                : $this->container->cancellation()->request($actor, $body);
            \json_response([
                'ok'=>true,
                'message'=>(string)($result['message'] ?? '操作成功'),
                'data'=>is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** V2.5.0 测量冻结延期：首次小范围自动，其余转平台审核；申请与审核共用同一 Application 用例。 */
final class OrderMeasurementHoldExtensionApiModule implements ApiModule
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
        return in_array($route, [
            'orders.measurement.hold.extend.request',
            'admin.measurement.hold.extend.review',
        ], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return true;
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        if ($route === 'admin.measurement.hold.extend.review') {
            $this->requireWorkbenchAdmin($this->container, $actor);
        }
        $body = $this->orderJsonBody();
        try {
            $result = $route === 'admin.measurement.hold.extend.review'
                ? $this->container->measurementHoldExtension()->review($actor, $body)
                : $this->container->measurementHoldExtension()->request($actor, $body);
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

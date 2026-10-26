<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** V2.5.0 完工异议：先双方协商，必要时再平台介入；管理员普通零金额结案复用同一领域模块。 */
final class OrderDisputeApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    /** @var string[] */
    private const ROUTES = ['disputes.create','disputes.escalate','disputes.resolve','admin.dispute.resolve'];

    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return true;
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        if ($route === 'admin.dispute.resolve') {
            $this->requireWorkbenchAdmin($this->container, $actor);
        }
        $body = $this->orderJsonBody();
        try {
            $result = $this->container->dispute()->execute($route, $actor, $body);
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

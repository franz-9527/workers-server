<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminOrderProxyComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/**
 * 管理员旧代操作 API 兼容路由。
 *
 * V2.5.0 不再允许代确认接单、代报价、代客户确认或代师傅完成售后；
 * 保留路由 owner 仅用于稳定返回明确业务错误，避免回退到旧 Controller 或未知处理器。
 * 协助预约与联系补录由独立 Application 用例执行，不经过旧代理动作分发。
 */
final class AdminOrderProxyApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    private OrderApplicationContainer $container;
    private AdminOrderProxyComposition $composition;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
        $this->composition = new AdminOrderProxyComposition($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'admin.proxy.action' || in_array($route, ['admin.order.assistance.context','admin.order.assistance.save'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return $route !== 'admin.order.assistance.context';
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod($route === 'admin.order.assistance.context' ? 'GET' : 'POST');
        $actor = $this->orderActor($this->container);
        $this->requireWorkbenchAdmin($this->container, $actor);
        $body = $route === 'admin.order.assistance.context' ? $_GET : $this->orderJsonBody();

        try {
            $operator = ['channel'=>'mini_admin','user_id'=>(int)($actor['user_id'] ?? 0)];
            if ($route !== 'admin.proxy.action') {
                $data = $this->composition->assistance($this->container)->execute($route, $operator, $body);
                \json_response(['ok'=>true,'message'=>$route === 'admin.order.assistance.context' ? '' : '补录已保存','data'=>$data]);
                return;
            }
            $result = $this->composition->action()->execute($operator, $body);
            \json_response([
                'ok'=>true,
                'message'=>(string)($result['message'] ?? '处理成功'),
                'data'=>is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

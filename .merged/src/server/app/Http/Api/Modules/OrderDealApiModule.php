<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/**
 * V2.5.0 统一订单成交入口。
 *
 * 普通订单、商家订单、平台承接单共用同一 Http 模块；来源差异由 Application/Repository
 * 内部策略处理，Http 层不再把 platform 转发到另一套订单状态机。
 */
final class OrderDealApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    /** @var string[] */
    private const ROUTES = [
        'quotes.create',
        'quotes.select',
        'quotes.switch',
        'orders.grab',
        'orders.grab.quote',
        'assignments.accept',
        'assignments.reject',
    ];

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
        // 只检查会提交自由文本的动作；选报价/抢单确认/接单本身不消耗微信内容安全额度。
        return in_array($route, ['quotes.create', 'quotes.switch', 'orders.grab.quote', 'assignments.reject'], true);
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        $body = $this->orderJsonBody();
        try {
            if ($route === 'quotes.select') {
                $result = $this->container->quoteSelection()->execute($actor, $body);
            } elseif ($route === 'quotes.switch') {
                $result = $this->container->quoteSwitch()->execute($actor, $body);
            } else {
                $result = $this->container->deal()->execute($route, $actor, $body);
            }
            \json_response([
                'ok' => true,
                'message' => (string)($result['message'] ?? '操作成功'),
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 平台单客户分享：公开脱敏预览 + 登录后手机号校验绑定 + 管理员生成分享。 */
final class PlatformOrderCustomerShareApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    /** @var string[] */
    private const ROUTES = [
        'platform.order.customer-share.create',
        'platform.order.customer-share.preview',
        'platform.order.customer-share.claim',
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
        return false;
    }

    public function dispatch(string $route): void
    {
        try {
            if ($route === 'platform.order.customer-share.preview') {
                $this->orderRequireMethod('GET');
                $token = trim((string)($_GET['t'] ?? $_GET['token'] ?? ''));
                $data = $this->container->platformOrderCustomerShare()->preview($token);
                \json_response(['ok'=>true,'message'=>'订单预览加载成功','data'=>$data]);
            }

            $this->orderRequireMethod('POST');
            $actor = $this->orderActor($this->container);
            $body = $this->orderJsonBody();
            if ($route === 'platform.order.customer-share.create') {
                $data = $this->container->platformOrderCustomerShare()->create(
                    $actor,
                    trim((string)($body['order_id'] ?? '')),
                    (int)($body['valid_days'] ?? 30)
                );
                \json_response(['ok'=>true,'message'=>'客户订单分享已生成','data'=>$data]);
            }
            $data = $this->container->platformOrderCustomerShare()->claim($actor, (string)($body['token'] ?? $body['t'] ?? ''));
            \json_response(['ok'=>true,'message'=>'平台订单已绑定到当前账号','data'=>$data]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}
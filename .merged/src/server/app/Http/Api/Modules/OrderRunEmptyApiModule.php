<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 跑空费先由用户与师傅自行确认；拒绝后才进入平台争议。 */
final class OrderRunEmptyApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['run-empty.claim.submit','run-empty.claim.decide'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return true;
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('POST');
        $actor = $this->orderActor($this->container);
        $body = $this->orderJsonBody();
        try {
            $result = $route === 'run-empty.claim.submit'
                ? $this->container->runEmpty()->submit($actor, $body)
                : $this->container->runEmpty()->decide($actor, $body);
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

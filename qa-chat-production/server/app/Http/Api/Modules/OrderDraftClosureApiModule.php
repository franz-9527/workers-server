<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Foundation\OrderDraftClosureComposition;
use WorkerAdmin\Http\Api\ApiModule;

/** 用户主动关闭未付款草稿；与已付款退款流程严格分离。 */
final class OrderDraftClosureApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private Database $db;
    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'orders.draft.close';
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        try {
            $this->orderRequireMethod('POST');
            $actor = $this->orderActor($this->container);
            $result = OrderDraftClosureComposition::build($this->db)->close($actor, $this->orderJsonBody());
            \json_response([
                'ok' => true,
                'message' => (string)($result['message'] ?? '未付款订单已关闭'),
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

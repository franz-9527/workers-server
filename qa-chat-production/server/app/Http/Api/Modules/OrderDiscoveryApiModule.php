<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** V2.5.0：公开接单大厅、订单详情与附近师傅触达正式脱离 ApiController。 */
final class OrderDiscoveryApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    /** @var string[] */
    private const ROUTES = [
        'hall.orders',
        'orders.detail',
        'orders.nearby-workers',
        'orders.nearby-workers.notify',
        'orders.nearby-workers.notify-status',
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
        $actor = $this->orderActor($this->container);
        try {
            if ($route === 'orders.nearby-workers.notify') {
                $this->orderRequireMethod('POST');
                $result = $this->container->nearbyWorkerOutreach()->notify($actor, $this->orderJsonBody());
                \json_response([
                    'ok'=>true,
                    'message'=>(string)($result['message'] ?? '短信任务已创建'),
                    'data'=>is_array($result['data'] ?? null) ? $result['data'] : [],
                ]);
            }

            $this->orderRequireMethod('GET');
            if ($route === 'orders.nearby-workers') {
                $data = $this->container->nearbyWorkerOutreach()->candidates(
                    $actor,
                    trim((string)($_GET['order_id'] ?? ''))
                );
                \json_response(['ok'=>true,'data'=>$data]);
            }
            if ($route === 'orders.nearby-workers.notify-status') {
                $data = $this->container->nearbyWorkerOutreach()->notifyStatus(
                    $actor,
                    trim((string)($_GET['batch_id'] ?? ''))
                );
                \json_response(['ok'=>true,'data'=>$data]);
            }

            $lat = is_numeric($_GET['lat'] ?? null) ? (float)$_GET['lat'] : null;
            $lng = is_numeric($_GET['lng'] ?? null) ? (float)$_GET['lng'] : null;
            if ($route === 'orders.detail') {
                $data = $this->container->detail()->query(
                    $actor,
                    trim((string)($_GET['id'] ?? '')),
                    $lat,
                    $lng,
                    trim((string)($_GET['share_token'] ?? ''))
                );
                // 读模型与写命令必须对同一事实给出同一答案：一旦已经关联施工单，测量冻结延期不再可申请。
                // 底层历史投影仍可能带出旧标记，因此在统一 orders.detail 出口收紧，避免任何客户端展示必然失败的按钮。
                if (is_array($data['measurement'] ?? null)
                    && !empty($data['measurement']['linked_construction'])
                    && is_array($data['measurement']['actions'] ?? null)) {
                    $data['measurement']['actions']['can_request_hold_extension'] = false;
                }
                \json_response(['ok' => true, 'data' => $data]);
            }

            $data = $this->container->discovery()->hall(
                $actor,
                $this->container->actorResolver()->isMiniAdmin($actor),
                (int)($_GET['category_id'] ?? 0),
                trim((string)($_GET['sort'] ?? '')),
                $lat,
                $lng,
                max(1, (int)($_GET['page'] ?? 1))
            );
            $message = (string)($data['message'] ?? '');
            unset($data['message']);
            $payload = ['ok' => true, 'data' => $data];
            if ($message !== '') $payload['message'] = $message;
            \json_response($payload);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

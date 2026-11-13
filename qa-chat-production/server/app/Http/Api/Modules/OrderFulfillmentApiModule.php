<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Foundation\OrderFulfillmentAuxiliaryComposition;
use WorkerAdmin\Http\Api\ApiModule;

/**
 * V2.5.0 统一订单履约入口。
 *
 * 核心成交履约与成交后的到场异常、测量结构、维修入口共用一个 HTTP owner；
 * 状态机仍分别下沉 Application/Repository，避免继续制造薄 Module。
 */
final class OrderFulfillmentApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    /** @var array<string,string> */
    private const ROUTES = [
        'changes.create'=>'POST',
        'changes.decide'=>'POST',
        'orders.measurement.submit'=>'POST',
        'orders.measurement.rework'=>'POST',
        'fulfillment.pricing.update'=>'POST',
        'fulfillment.complete'=>'POST',
        'fulfillment.accept'=>'POST',
        'arrivals.report'=>'POST',
        'orders.measurement.space.save'=>'POST',
        'orders.measurement.space.delete'=>'POST',
        'orders.measurement.links.eligible'=>'GET',
        'after_sales.repair.create'=>'POST',
        'after_sales.repair.withdraw'=>'POST',
    ];

    /** @var string[] */
    private const AUXILIARY_ROUTES = [
        'arrivals.report',
        'orders.measurement.space.save',
        'orders.measurement.space.delete',
        'orders.measurement.links.eligible',
        'after_sales.repair.create',
        'after_sales.repair.withdraw',
    ];

    private OrderApplicationContainer $container;
    private OrderFulfillmentAuxiliary $auxiliary;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
        $this->auxiliary = OrderFulfillmentAuxiliaryComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return isset(self::ROUTES[$route]);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return in_array($route, [
            'changes.create','changes.decide','orders.measurement.rework','fulfillment.complete',
            'arrivals.report','orders.measurement.space.save','after_sales.repair.create',
        ], true);
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod(self::ROUTES[$route] ?? 'POST');
        $actor = $this->orderActor($this->container);
        $input = self::ROUTES[$route] === 'GET' ? $_GET : $this->orderJsonBody();

        try {
            if (in_array($route, self::AUXILIARY_ROUTES, true)) {
                $result = $this->auxiliary->execute($route, $actor, $input);
                $response = ['ok'=>true];
                if (isset($result['message']) && (string)$result['message'] !== '') {
                    $response['message'] = (string)$result['message'];
                }
                if (array_key_exists('data', $result)) {
                    $response['data'] = is_array($result['data']) ? $result['data'] : [];
                }
                \json_response($response);
                return;
            }

            // 既有核心履约路由保持迁移前响应协议，避免板块扩展顺带改变客户端契约。
            $result = $this->container->fulfillment()->execute($route, $actor, $input);
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

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 表单读取、订单草稿创建、最终提交与审核补资料。 */
final class OrderSubmissionApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['forms.order', 'forms.order.products', 'admin.orders.proxy.form', 'admin.orders.proxy.customers', 'admin.orders.proxy.create', 'admin.orders.proxy.ready', 'orders.create', 'orders.finalize', 'orders.supplement'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return in_array($route, ['orders.create', 'orders.supplement', 'admin.orders.proxy.create'], true);
    }

    public function dispatch(string $route): void
    {
        try {
            $actor = $this->orderActor($this->container);
            $submission = $this->container->submission();

            if (strpos($route, 'admin.orders.proxy.') === 0) {
                $proxy = $this->container->miniOrdinaryOrderCreation();
                if (in_array($route, ['admin.orders.proxy.form','admin.orders.proxy.customers'], true)) {
                    $this->orderRequireMethod('GET');
                    $data = $route === 'admin.orders.proxy.form'
                        ? $proxy->form($actor, (string)($_GET['customer_ref'] ?? ''))
                        : $proxy->customers($actor, (string)($_GET['q'] ?? ''));
                } else {
                    $this->orderRequireMethod('POST');
                    $body = $this->orderJsonBody();
                    $data = $route === 'admin.orders.proxy.create' ? $proxy->create($actor, $body) : $proxy->ready($actor, $body);
                }
                \json_response(['ok'=>true, 'data'=>$data]);
            }
            if ($route === 'forms.order.products') {
                $this->orderRequireMethod('GET');
                \json_response(['ok'=>true, 'data'=>$submission->productHistory($actor, $_GET)]);
            }

            if ($route === 'forms.order') {
                $this->orderRequireMethod('GET');
                \json_response(['ok' => true, 'data' => $submission->form($actor)]);
            }

            $this->orderRequireMethod('POST');
            $body = $this->orderJsonBody();
            if ($route === 'orders.create') {
                $result = $submission->create($actor, $body);
            } elseif ($route === 'orders.finalize') {
                $result = $submission->finalize($actor, $body);
            } else {
                $result = $submission->supplement($actor, $body);
            }
            \json_response(['ok' => true, 'message' => $result['message'] ?? '操作成功', 'data' => $result['data'] ?? []]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** V2.5.0 统一施工/维修预约入口。 */
final class OrderAppointmentApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private const ROUTE = 'appointments.save';
    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return $route === self::ROUTE;
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
            $data = $this->container->appointment()->save($actor, $this->orderJsonBody());
            $message = !empty($data['wait_customer_notice'])
                ? '已记录“等待客户另行通知”，系统视为师傅已响应'
                : '预约记录已保存';
            \json_response(['ok'=>true,'message'=>$message,'data'=>$data]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

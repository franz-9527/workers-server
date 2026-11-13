<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;
use WorkerAdmin\Application\OrderCancellationRisk;
use WorkerAdmin\Foundation\OrderCancellationRiskComposition;

/** 2026-10-08：原拨号按钮记录行为，不新增师傅端操作入口。 */
final class OrderCancellationRiskApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    private OrderApplicationContainer $container;
    private OrderCancellationRisk $risk;
    public function __construct(Database $db) { $this->container=new OrderApplicationContainer($db); $this->risk=OrderCancellationRiskComposition::build($db); }
    public function supports(string $route): bool { return in_array($route,['orders.contact.record','orders.submission-policy'],true); }
    public function requiresContentSecurity(string $route): bool { return false; }
    public function dispatch(string $route): void
    {
        try {
            $this->orderRequireMethod($route==='orders.submission-policy' ? 'GET' : 'POST');
            $actor=$this->orderActor($this->container);
            if ($route==='orders.submission-policy') \json_response(['ok'=>true,'data'=>$this->risk->state((int)$actor['user_id'])]);
            $body=$this->orderJsonBody();
            $this->risk->recordDial($actor, trim((string)($body['order_id'] ?? '')));
            \json_response(['ok'=>true,'message'=>'联系行为已记录']);
        } catch (Throwable $exception) { $this->orderHandleException($exception); }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 平台测量成果确认后的管理员核价入口；托管核价完成即生效。 */
final class PlatformOrderPricingReviewApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private const REVIEW_ROUTE = 'admin.platform.pricing.review';
    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return $route === self::REVIEW_ROUTE;
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
            $body = $this->orderJsonBody();
            $data = $this->container->platformOrderPricingReview()->review($actor, $body);
            \json_response(['ok'=>true,'message'=>'核价已完成并生效，可由师傅预约施工','data'=>$data]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 订单级邀请领取；不再创建受邀专属师傅身份。 */
final class OrderInvitationApiModule implements ApiModule
{
    use OrderApiModuleSupport;

    private OrderApplicationContainer $container;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'invites.claim';
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
            $data = $this->container->invitation()->claim($actor, $this->orderJsonBody());
            \json_response([
                'ok' => true,
                'message' => !empty($data['already_claimed']) ? '邀请已经领取' : '邀请已领取，请确认接单规则',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            $this->orderHandleException($exception);
        }
    }
}

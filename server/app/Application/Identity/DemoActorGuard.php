<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use WorkerAdmin\Domain\Order\OrderOperationException;

/** Shared capability boundary for production orders and real external effects. */
final class DemoActorGuard
{
    public static function requireProductionOrder(array $actor): void
    {
        if (!empty($actor['is_demo_actor'])) {
            throw new OrderOperationException(
                '演示身份不创建新的生产订单，请使用预置演示场景', 'DEMO_CREATE_BLOCKED', 403
            );
        }
    }

    public static function requireExternalAction(array $actor): void
    {
        if (!empty($actor['is_demo_actor'])) {
            throw new OrderOperationException(
                '演示身份禁止真实支付、提现、实名、云资料或文件上传操作', 'DEMO_EXTERNAL_ACTION_BLOCKED', 403
            );
        }
    }
}

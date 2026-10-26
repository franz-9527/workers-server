<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

/**
 * 订单核心流程中跨模块共享的稳定状态值。
 *
 * 这里只保存领域常量，不读取数据库、不调用全局函数。
 */
final class OrderStatus
{
    public const AUDIT_APPROVED = 'approved';
    public const PAYMENT_PAID = 'paid';
    public const PUBLISH_PUBLISHED = 'published';
    public const PUBLISH_HIDDEN = 'hidden';
    public const LIFECYCLE_ACTIVE = 'active';

    public const QUOTE_WAITING = 'waiting';
    public const QUOTE_QUOTING = 'quoting';
    public const QUOTE_COLLECTING = 'collecting';

    public const REFUND_NONE = 'none';

    private function __construct()
    {
    }
}
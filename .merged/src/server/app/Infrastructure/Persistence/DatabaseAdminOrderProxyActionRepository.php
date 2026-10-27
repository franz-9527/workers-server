<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminOrderProxyActionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 旧端口只用于兼容拒绝；任何层级都不得代替师傅确认接单。 */
final class DatabaseAdminOrderProxyActionRepository implements AdminOrderProxyActionRepository
{
    public function __construct(Database $db) {}

    public function acceptAssignment(array $operator, string $orderNo, string $note): array
    {
        throw new OrderOperationException('接单必须由师傅本人确认', 'ADMIN_ASSIGNMENT_PROXY_REMOVED', 422);
    }
}

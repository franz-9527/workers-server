<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminWorkbenchQuery;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminWorkbenchTaskRepository;

/** 异常工作台读模型组合根。 */
final class AdminWorkbenchReadComposition
{
    private Database $db;
    private ?AdminWorkbenchQuery $query = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function capabilitiesForOrder(string $orderNo): array
    {
        return (new \WorkerAdmin\Infrastructure\Persistence\AdminWorkbenchCapabilityReader($this->db))->forOrder($orderNo);
    }

    public function query(): AdminWorkbenchQuery
    {
        if (!$this->query) {
            $this->query = new AdminWorkbenchQuery(
                new DatabaseAdminWorkbenchTaskRepository($this->db)
            );
        }
        return $this->query;
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminWorkbenchOperations;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminWorkbenchOperationsRepository;

final class AdminWorkbenchOperationsComposition
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function operations(): AdminWorkbenchOperations
    {
        return new AdminWorkbenchOperations(new DatabaseAdminWorkbenchOperationsRepository($this->db));
    }

    public function repository(): DatabaseAdminWorkbenchOperationsRepository
    {
        return new DatabaseAdminWorkbenchOperationsRepository($this->db);
    }
}

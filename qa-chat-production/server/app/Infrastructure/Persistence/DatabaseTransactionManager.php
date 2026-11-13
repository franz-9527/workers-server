<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Core\Database;

final class DatabaseTransactionManager implements TransactionManager
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function run(callable $callback)
    {
        return $this->db->transaction(function (Database $db) use ($callback) {
            return $callback();
        });
    }
}

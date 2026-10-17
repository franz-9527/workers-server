<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Order\OrderDispute;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDisputeRepository;

/** CI-only minimal adapter for the dispute finance E2E path. */
final class OrderApplicationContainer
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function dispute():OrderDispute{return new OrderDispute(new DatabaseOrderDisputeRepository($this->db));}
}

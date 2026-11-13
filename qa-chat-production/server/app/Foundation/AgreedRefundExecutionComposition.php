<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AgreedRefundExecution;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAgreedRefundExecutionRepository;

final class AgreedRefundExecutionComposition
{
    private Database $db;
    private ?AgreedRefundExecution $useCase = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function useCase(): AgreedRefundExecution
    {
        if (!$this->useCase) {
            $this->useCase = new AgreedRefundExecution(new DatabaseAgreedRefundExecutionRepository($this->db));
        }
        return $this->useCase;
    }
}

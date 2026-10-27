<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\FailedRefundRecovery;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseFailedRefundRecoveryRepository;

final class FailedRefundRecoveryComposition
{
    private Database $db;
    private ?FailedRefundRecovery $useCase = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function useCase(): FailedRefundRecovery
    {
        if (!$this->useCase) {
            $this->useCase = new FailedRefundRecovery(new DatabaseFailedRefundRecoveryRepository($this->db));
        }
        return $this->useCase;
    }
}

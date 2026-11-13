<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WithdrawalCreationRepository
{
    public function isRealNameApproved(int $workerId): bool;

    /**
     * 调用方必须已开启数据库事务。
     *
     * @return array{id:int,public_no:string,amount_cents:int}
     */
    public function createPending(int $workerId, int $amountCents): array;
}

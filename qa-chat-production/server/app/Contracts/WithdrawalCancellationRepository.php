<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WithdrawalCancellationRepository
{
    /**
     * 调用方必须已开启数据库事务。
     * 只允许撤销当前师傅自己的 pending 提现，并在同一事务内释放冻结余额、记录事件。
     */
    public function cancelPending(int $workerId, string $withdrawalNo): void;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Contracts\WithdrawalCancellationRepository;
use WorkerAdmin\Domain\Money\WithdrawalCancellationException;

/** 师傅撤销提现：只允许撤销本人 pending 提现，并在同一事务中释放冻结余额与记录事件。 */
final class WithdrawalCancellation
{
    private WithdrawalCancellationRepository $repository;
    private TransactionManager $transactions;

    public function __construct(
        WithdrawalCancellationRepository $repository,
        TransactionManager $transactions
    ) {
        $this->repository = $repository;
        $this->transactions = $transactions;
    }

    /** @return array{message:string} */
    public function cancel(array $actor, array $input): array
    {
        $workerId = (int)($actor['worker_id']??0);
        if ($workerId < 1) {
            throw new WithdrawalCancellationException('你还不是师傅用户','WORKER_REQUIRED',403);
        }

        $withdrawalNo = trim((string)($input['withdrawal_no']??''));
        $this->transactions->run(function () use ($workerId,$withdrawalNo): void {
            $this->repository->cancelPending($workerId,$withdrawalNo);
        });

        return ['message'=>'提现申请已撤销，余额已释放'];
    }
}

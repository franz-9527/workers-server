<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Contracts\WithdrawalCreationRepository;
use WorkerAdmin\Contracts\WithdrawalSubmittedNotifier;
use WorkerAdmin\Domain\Money\WithdrawalCreationException;

/** 师傅提现创建：资格/金额校验，以及提现单、冻结余额、事件和管理员待办的原子提交。 */
final class WithdrawalCreation
{
    private WithdrawalCreationRepository $repository;
    private WithdrawalSubmittedNotifier $notifier;
    private SettingsProvider $settings;
    private TransactionManager $transactions;

    public function __construct(
        WithdrawalCreationRepository $repository,
        WithdrawalSubmittedNotifier $notifier,
        SettingsProvider $settings,
        TransactionManager $transactions
    ) {
        $this->repository = $repository;
        $this->notifier = $notifier;
        $this->settings = $settings;
        $this->transactions = $transactions;
    }

    /** @return array{message:string,data:array{withdrawal_no:string}} */
    public function create(array $actor, array $input): array
    {
        $workerId = (int)($actor['worker_id']??0);
        if ($workerId < 1) {
            throw new WithdrawalCreationException('你还不是师傅用户','WORKER_REQUIRED',403);
        }

        $amount = (int)($input['amount_cents']??0);
        $minimum = max(1,$this->settings->int('withdrawal.minimum_cents',100));

        // 保持旧接口校验顺序：先实名，再判断最低提现金额。
        if (!$this->repository->isRealNameApproved($workerId)) {
            throw new WithdrawalCreationException('实名认证通过后才能申请提现','REAL_NAME_REQUIRED',403);
        }
        if ($amount < $minimum) {
            throw new WithdrawalCreationException('提现金额低于最低提现金额','WITHDRAW_TOO_SMALL',422);
        }

        $created = $this->transactions->run(function () use ($workerId,$amount): array {
            $request = $this->repository->createPending($workerId,$amount);
            // 与旧链路一致：管理员待办作为本地 outbox 事实与提现冻结同事务提交。
            $this->notifier->notifySubmitted(
                (int)$request['id'],
                (string)$request['public_no'],
                $workerId,
                $amount
            );
            return $request;
        });

        return [
            'message'=>'提现申请已提交，平台审核后人工支付宝付款',
            'data'=>['withdrawal_no'=>(string)$created['public_no']],
        ];
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use WorkerAdmin\Contracts\MiniAdminMoneyRepository;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;

final class MiniAdminMoneyOperations
{
    private MiniAdminMoneyRepository $repository;
    private string $timezone;

    public function __construct(MiniAdminMoneyRepository $repository, string $timezone = 'Asia/Shanghai')
    {
        $this->repository = $repository;
        $this->timezone = $timezone;
    }

    /** @return array<string,mixed> */
    public function execute(string $route, array $actor, array $input): array
    {
        if (empty($actor['business_admin'])) {
            throw new MiniAdminOperationException('仅管理员可使用工作台', 'ADMIN_REQUIRED', 403);
        }
        if ($route === 'admin.withdrawals') {
            $page = max(1, (int)($input['page'] ?? 1));
            return ['ok'=>true,'data'=>$this->repository->listWithdrawals($page, 25, $this->timezone)];
        }
        if ($route === 'admin.withdrawal.review') {
            $publicNo = trim((string)($input['withdrawal_id'] ?? ''));
            $decision = strtolower(trim((string)($input['decision'] ?? '')));
            $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
            if (!in_array($decision, ['approved','rejected'], true)) {
                throw new MiniAdminOperationException('提现审核动作无效', 'INVALID_WITHDRAWAL_ACTION', 422);
            }
            if ($decision === 'rejected' && mb_strlen($note) < 2) {
                throw new MiniAdminOperationException('驳回提现时请填写原因', 'REASON_REQUIRED', 422);
            }
            $data = $this->repository->reviewWithdrawal($actor, $publicNo, $decision, $note);
            return [
                'ok'=>true,
                'message'=>$decision === 'approved' ? '提现已审核通过，实际付款仍需在网页后台登记' : '提现已驳回并解冻余额',
                'data'=>$data,
            ];
        }
        throw new MiniAdminOperationException('接口不存在', 'NOT_FOUND', 404);
    }
}

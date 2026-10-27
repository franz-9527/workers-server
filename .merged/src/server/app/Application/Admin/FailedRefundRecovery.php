<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use WorkerAdmin\Contracts\FailedRefundRecoveryRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 微信退款异常/关闭后的安全恢复入口：ABNORMAL 处理原单，CLOSED 才允许生成新退款单。 */
final class FailedRefundRecovery
{
    private FailedRefundRecoveryRepository $repository;

    public function __construct(FailedRefundRecoveryRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<int,array<string,mixed>> */
    public function cases(): array
    {
        return $this->repository->cases();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function detail(array $input): array
    {
        return $this->repository->detail($this->refundId($input));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function recheck(array $input, int $adminId): array
    {
        if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        return $this->repository->recheck($this->refundId($input), $adminId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function replaceClosed(array $input, int $adminId): array
    {
        if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        return $this->repository->replaceClosed($this->refundId($input), $adminId);
    }

    /** @param array<string,mixed> $input */
    private function refundId(array $input): int
    {
        $id = (int)($input['id'] ?? 0);
        if ($id < 1) throw new OrderOperationException('退款记录不存在', 'REFUND_ID_REQUIRED', 422);
        return $id;
    }
}

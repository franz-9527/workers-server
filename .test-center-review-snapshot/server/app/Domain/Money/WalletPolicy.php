<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Money;

use RuntimeException;

/** 用户钱包纯业务规则，不访问数据库和系统配置。 */
final class WalletPolicy
{
    public function assertUserId(int $userId): void
    {
        if ($userId < 1) {
            throw new RuntimeException('用户钱包身份无效');
        }
    }

    public function calculateMutation(
        int $available,
        int $reserved,
        int $amountCents,
        int $availableDelta,
        int $reservedDelta,
        string $idempotencyKey
    ): array {
        if ($amountCents < 0) {
            throw new RuntimeException('钱包流水金额不能为负数');
        }
        if (trim($idempotencyKey) === '') {
            throw new RuntimeException('钱包流水缺少幂等键');
        }

        $afterAvailable = $available + $availableDelta;
        $afterReserved = $reserved + $reservedDelta;
        if ($afterAvailable < 0) {
            throw new RuntimeException('用户可用余额不足');
        }
        if ($afterReserved < 0) {
            throw new RuntimeException('用户预占余额状态异常');
        }

        return [
            'available_after' => $afterAvailable,
            'reserved_after' => $afterReserved,
        ];
    }

    public function assertRefundHoldInput(
        int $userId,
        int $refundId,
        string $refundPublicNo,
        int $amountCents
    ): void {
        if ($userId < 1 || $refundId < 1 || $amountCents < 1 || trim($refundPublicNo) === '') {
            throw new RuntimeException('充值纠错退款冻结参数无效');
        }
    }

    public function assertExistingRefundReservationMatches(
        array $reservation,
        int $userId,
        int $refundId,
        int $amountCents
    ): void {
        if ((int) ($reservation['user_id'] ?? 0) !== $userId
            || (int) ($reservation['business_id'] ?? 0) !== $refundId
            || (int) ($reservation['amount_cents'] ?? 0) !== $amountCents) {
            throw new RuntimeException('充值纠错退款冻结记录与当前业务不一致');
        }
    }
}

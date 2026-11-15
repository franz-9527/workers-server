<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Order;

/**
 * V2.5.0 平台承接单纯金额规则。
 *
 * 不访问数据库、不读取配置、不处理 HTTP，供当前平台履约适配器以及后续统一
 * OrderFulfillment Application 复用，避免 worker_collect / platform_balance 的
 * 金额算法在多个入口各写一份。
 */
final class PlatformOrderSettlementPolicy
{
    public const MODE_WORKER_COLLECT = 'worker_collect';
    public const MODE_PLATFORM_BALANCE = 'platform_balance';

    /**
     * 兼容现有 extra_income_rate_bp_snapshot 单字段的规则编码。
     * 0..10000 表示万分比；大于等于该偏移量表示“固定金额”，偏移后的值单位为分。
     * 这样 V2.5.0 尚未正式运营时无需再拆一套加项结算表，且所有资金算法仍集中在领域层。
     */
    public const EXTRA_INCOME_FIXED_OFFSET = 1000000000;

    /**
     * @return array{booking_paid_cents:int,customer_tail_cents:int,platform_receivable_cents:int,worker_balance_credit_cents:int}
     */
    public function settlement(int $customerTotal, int $workerLabor, int $bookingPaid, string $mode): array
    {
        if ($customerTotal <= 0 || $workerLabor <= 0) {
            throw new OrderOperationException('客户金额或师傅工费尚未确认', 'PLATFORM_SETTLEMENT_AMOUNT_REQUIRED', 409);
        }
        $this->assertMode($mode);

        $bookingPaid = min($customerTotal, max(0, $bookingPaid));
        $customerTail = max(0, $customerTotal - $bookingPaid);
        $platformReceivable = $mode === self::MODE_PLATFORM_BALANCE
            ? $customerTail
            : $customerTotal - $workerLabor - $bookingPaid;
        if ($platformReceivable < 0) {
            throw new OrderOperationException(
                '本单会形成负差额，请先与客户加收或处理加价后再验收',
                'PLATFORM_SETTLEMENT_NEGATIVE',
                409
            );
        }

        return [
            'booking_paid_cents' => $bookingPaid,
            'customer_tail_cents' => $customerTail,
            'platform_receivable_cents' => $platformReceivable,
            'worker_balance_credit_cents' => $mode === self::MODE_PLATFORM_BALANCE ? $workerLabor : 0,
        ];
    }

    /** @return array{platform_share_cents:int,worker_share_cents:int} */
    public function splitExtraIncome(int $addedAmount, int $platformRuleValue): array
    {
        if ($addedAmount <= 0) {
            throw new OrderOperationException('请输入正确的加价金额', 'PLATFORM_CHANGE_AMOUNT_INVALID', 422);
        }

        if ($platformRuleValue >= self::EXTRA_INCOME_FIXED_OFFSET) {
            $fixed = max(0, $platformRuleValue - self::EXTRA_INCOME_FIXED_OFFSET);
            if ($fixed > $addedAmount) {
                throw new OrderOperationException('本次加项金额低于本单设置的固定平台收入，请先调整加项金额或平台收入规则', 'PLATFORM_CHANGE_BELOW_FIXED_INCOME', 422);
            }
            return [
                'platform_share_cents' => $fixed,
                'worker_share_cents' => $addedAmount - $fixed,
            ];
        }

        $rate = max(0, min(10000, $platformRuleValue));
        $platformShare = (int) round($addedAmount * $rate / 10000);
        return [
            'platform_share_cents' => $platformShare,
            'worker_share_cents' => max(0, $addedAmount - $platformShare),
        ];
    }

    /** @return array{available_delta_cents:int,frozen_delta_cents:int,entry_type:string} */
    public function workerBalanceEntry(int $workerBalanceCredit, int $freezeDays): array
    {
        $credit = max(0, $workerBalanceCredit);
        $days = max(0, $freezeDays);
        return [
            'available_delta_cents' => $days === 0 ? $credit : 0,
            'frozen_delta_cents' => $days > 0 ? $credit : 0,
            'entry_type' => $days === 0 ? 'platform_order_income' : 'platform_order_income_frozen',
        ];
    }

    private function assertMode(string $mode): void
    {
        if (!in_array($mode, [self::MODE_WORKER_COLLECT, self::MODE_PLATFORM_BALANCE], true)) {
            throw new OrderOperationException('平台承接单工费结算方式无效', 'PLATFORM_SETTLEMENT_MODE_INVALID', 409);
        }
    }
}

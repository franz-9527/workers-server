<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\PlatformOrderFinanceRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 平台承接单财务后台用例；不读取 HTTP、不执行 SQL。 */
final class PlatformOrderFinance
{
    private PlatformOrderFinanceRepository $repository;

    public function __construct(PlatformOrderFinanceRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        return $this->repository->dashboard();
    }

    /** @param array<string,mixed> $input */
    public function approveChange(array $input, int $adminId): void
    {
        $changeId = max(0, (int)($input['change_id'] ?? 0));
        if ($changeId < 1) throw new OrderOperationException('工费加价申请不存在。', 'PLATFORM_CHANGE_NOT_FOUND', 404);
        $customerAdd = $this->moneyToCents((string)($input['customer_add_amount'] ?? '0'));
        if ($customerAdd > 100000000) throw new OrderOperationException('客户加收金额不能超过 100 万元。', 'PLATFORM_CUSTOMER_ADD_INVALID', 422);
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
        $this->repository->approveChange($changeId, $customerAdd, $note, $adminId);
    }

    /** @param array<string,mixed> $input */
    public function rejectChange(array $input, int $adminId): void
    {
        $changeId = max(0, (int)($input['change_id'] ?? 0));
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
        if ($changeId < 1) throw new OrderOperationException('工费加价申请不存在。', 'PLATFORM_CHANGE_NOT_FOUND', 404);
        if (mb_strlen($note) < 2) throw new OrderOperationException('驳回时请填写原因。', 'PLATFORM_CHANGE_REJECT_REASON_REQUIRED', 422);
        $this->repository->rejectChange($changeId, $note, $adminId);
    }

    /** @param array<string,mixed> $input */
    public function markIncomePaid(array $input, int $adminId): void
    {
        $orderId = max(0, (int)($input['order_id'] ?? 0));
        if ($orderId < 1) throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
        $confirmedAmount = $this->strictMoneyToCents((string)($input['confirmed_amount'] ?? ''));
        $reference = mb_substr(trim((string)($input['payment_reference'] ?? '')), 0, 120);
        if (mb_strlen($reference) < 4) {
            throw new OrderOperationException('请填写实际转账交易单号或银行流水编号（至少 4 字）。', 'PLATFORM_INCOME_REFERENCE_REQUIRED', 422);
        }
        if (empty($input['receipt_checked'])) {
            throw new OrderOperationException('请先核对实际收款流水，确认款项确已到账。', 'PLATFORM_INCOME_CONFIRMATION_REQUIRED', 422);
        }
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
        $this->repository->markIncomePaid($orderId, $confirmedAmount, $reference, $note, $adminId);
    }

    /** @param array<string,mixed> $input */
    public function openRefundReview(array $input, int $adminId): void
    {
        $refundId = max(0, (int)($input['refund_id'] ?? 0));
        if ($refundId < 1) throw new OrderOperationException('请选择真实退款记录', 'PLATFORM_REFUND_NOT_FOUND', 404);
        $this->repository->openRefundReview($refundId, $adminId);
    }

    /** @param array<string,mixed> $input */
    public function decideRefundReview(array $input, int $adminId): void
    {
        $reviewId = max(0, (int)($input['review_id'] ?? 0));
        if ($reviewId < 1) throw new OrderOperationException('退款核账单不存在', 'PLATFORM_REFUND_REVIEW_NOT_FOUND', 404);
        $responsibility = trim((string)($input['responsibility'] ?? ''));
        if (!in_array($responsibility, ['platform','worker','shared'], true)) {
            throw new OrderOperationException('请选择正确的退款责任类型', 'PLATFORM_REFUND_RESPONSIBILITY_INVALID', 422);
        }
        $workerCents = $this->zeroAllowedMoneyToCents((string)($input['worker_recovery_amount'] ?? ''));
        $note = mb_substr(trim((string)($input['decision_note'] ?? '')), 0, 1000);
        if (mb_strlen($note) < 6) throw new OrderOperationException('请写明退款判责依据（至少6字）', 'PLATFORM_REFUND_DECISION_REASON_REQUIRED', 422);
        $this->repository->decideRefundReview($reviewId, $responsibility, $workerCents, $note, $adminId);
    }

    /** @param array<string,mixed> $input */
    public function confirmRefundRecovery(array $input, int $adminId): void
    {
        $reviewId = max(0, (int)($input['review_id'] ?? 0));
        if ($reviewId < 1) throw new OrderOperationException('退款核账单不存在', 'PLATFORM_REFUND_REVIEW_NOT_FOUND', 404);
        $reference = mb_substr(trim((string)($input['recovery_reference'] ?? '')), 0, 120);
        if (mb_strlen($reference) < 4) throw new OrderOperationException('请输入实际追偿到账的银行/支付流水号', 'PLATFORM_REFUND_RECOVERY_REFERENCE_REQUIRED', 422);
        if (empty($input['receipt_checked'])) throw new OrderOperationException('请先确认已核对真实的线下追偿款到账', 'PLATFORM_REFUND_RECOVERY_PROOF_REQUIRED', 422);
        $amount = $this->zeroAllowedMoneyToCents((string)($input['recovery_amount'] ?? ''));
        if ($amount < 1) {
            throw new OrderOperationException('请填写本次实际到账金额，必须大于零', 'PLATFORM_REFUND_RECOVERY_AMOUNT_INVALID', 422);
        }
        $note = mb_substr(trim((string)($input['note'] ?? '')), 0, 500);
        $this->repository->confirmRefundRecovery($reviewId, $amount, $reference, $note, $adminId);
    }

    private function strictMoneyToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new OrderOperationException('请按实际到账金额填写，最多保留两位小数。', 'PLATFORM_INCOME_AMOUNT_INVALID', 422);
        }
        $cents = ((int)$parts[1]) * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
        if ($cents < 1 || $cents > 100000000) {
            throw new OrderOperationException('平台收入款到账金额超出有效范围。', 'PLATFORM_INCOME_AMOUNT_INVALID', 422);
        }
        return $cents;
    }

    private function zeroAllowedMoneyToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new OrderOperationException('请填写正确金额，最多保留两位小数。', 'PLATFORM_FINANCE_AMOUNT_INVALID', 422);
        }
        $cents = ((int)$parts[1]) * 100 + (int)str_pad($parts[2] ?? '', 2, '0');
        if ($cents > 100000000) throw new OrderOperationException('金额不能超过100万元', 'PLATFORM_FINANCE_AMOUNT_INVALID', 422);
        return $cents;
    }

    private function moneyToCents(string $value): int
    {
        $value = trim(str_replace([',','￥','¥','元'], '', $value));
        if ($value === '' || !is_numeric($value)) return 0;
        return max(0, (int)round((float)$value * 100));
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Money;

use RuntimeException;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Contracts\UserWalletRepository;
use WorkerAdmin\Domain\Money\WalletPolicy;

/**
 * 用户钱包应用服务。
 *
 * 事务锁顺序保持：先 wallet user 快照 -> user_wallets FOR UPDATE -> reservation FOR UPDATE。
 */
final class UserWalletManager
{
    private UserWalletRepository $repository;
    private SettingsProvider $settings;
    private ?TransactionManager $transaction;
    private WalletPolicy $policy;

    public function __construct(
        UserWalletRepository $repository,
        SettingsProvider $settings,
        ?TransactionManager $transaction = null,
        ?WalletPolicy $policy = null
    ) {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->transaction = $transaction;
        $this->policy = $policy ?: new WalletPolicy();
    }

    public function summary(int $userId): array
    {
        $this->ensureWallet($userId);
        return $this->formatWallet($this->repository->findWallet($userId) ?: []);
    }

    public function ledger(int $userId, int $page = 1, int $pageSize = 20): array
    {
        $this->policy->assertUserId($userId);
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $rows = $this->repository->ledgerPage($userId, $pageSize, $offset);
        $total = $this->repository->ledgerCount($userId);

        foreach ($rows as &$row) {
            foreach (['amount_cents','available_delta_cents','reserved_delta_cents','available_after_cents','reserved_after_cents'] as $key) {
                $row[$key] = (int) $row[$key];
            }
        }
        unset($row);

        return [
            'items' => $rows,
            'page' => $page,
            'has_more' => $offset + count($rows) < $total,
            'total' => $total,
        ];
    }

    public function ensureWallet(int $userId): void
    {
        $this->policy->assertUserId($userId);
        $this->repository->ensureWallet($userId);
    }

    public function lockWallet(int $userId): array
    {
        $this->policy->assertUserId($userId);
        return $this->repository->lockWallet($userId);
    }

    public function applyLocked(
        array $wallet,
        string $entryType,
        string $direction,
        int $amountCents,
        int $availableDelta,
        int $reservedDelta,
        string $referenceType,
        ?int $referenceId,
        string $idempotencyKey,
        string $description
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        $existing = $idempotencyKey !== ''
            ? $this->repository->findLedgerBalanceByIdempotency($idempotencyKey)
            : null;
        if ($existing) {
            return [
                'id' => (int) $wallet['id'],
                'user_id' => (int) $wallet['user_id'],
                'available_cents' => (int) $existing['available_after_cents'],
                'reserved_cents' => (int) $existing['reserved_after_cents'],
                'version' => (int) $wallet['version'],
            ];
        }

        $available = (int) $wallet['available_cents'];
        $reserved = (int) $wallet['reserved_cents'];
        $mutation = $this->policy->calculateMutation(
            $available,
            $reserved,
            $amountCents,
            $availableDelta,
            $reservedDelta,
            $idempotencyKey
        );
        $afterAvailable = (int) $mutation['available_after'];
        $afterReserved = (int) $mutation['reserved_after'];

        if (!$this->repository->updateWalletBalances(
            (int) $wallet['id'],
            $available,
            $reserved,
            $afterAvailable,
            $afterReserved
        )) {
            throw new RuntimeException('钱包余额已变化，请重试');
        }

        $this->repository->appendLedger([
            'user_id' => (int) $wallet['user_id'],
            'entry_type' => $entryType,
            'direction' => $direction,
            'amount_cents' => $amountCents,
            'available_delta_cents' => $availableDelta,
            'reserved_delta_cents' => $reservedDelta,
            'available_after_cents' => $afterAvailable,
            'reserved_after_cents' => $afterReserved,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
        ]);

        return [
            'id' => (int) $wallet['id'],
            'user_id' => (int) $wallet['user_id'],
            'available_cents' => $afterAvailable,
            'reserved_cents' => $afterReserved,
            'version' => (int) $wallet['version'] + 1,
        ];
    }

    public function consumeReservationLocked(int $reservationId, string $invitePublicNo): array
    {
        $userId = $this->repository->reservationUserId($reservationId);
        if ($userId === null) {
            throw new RuntimeException('短信余额预占不存在');
        }
        $wallet = $this->lockWallet($userId);
        $reservation = $this->repository->lockReservation($reservationId);
        if (!$reservation || (int) $reservation['user_id'] !== $userId) {
            throw new RuntimeException('短信余额预占状态异常');
        }
        if ((string) $reservation['status'] === 'consumed') {
            return $this->formatWallet($wallet);
        }
        if ((string) $reservation['status'] !== 'held') {
            throw new RuntimeException('短信余额预占已释放，不能再次扣费');
        }

        $wallet = $this->applyLocked(
            $wallet,
            'sms_charge',
            'debit',
            (int) $reservation['amount_cents'],
            0,
            -(int) $reservation['amount_cents'],
            (($reservation['business_type']??'')==='chat_sms_nudge'?'chat_sms_nudge':'nearby_worker_sms_invite'),
            (int) $reservation['business_id'],
            'sms_charge:' . $invitePublicNo,
            (($reservation['business_type']??'')==='chat_sms_nudge'?'聊天短信催促费':'附近师傅短信通知费')
        );
        if (!$this->repository->transitionReservation($reservationId, 'held', 'consumed')) {
            throw new RuntimeException('短信余额预占结算状态已变化');
        }
        return $this->formatWallet($wallet);
    }

    public function consumeReservation(int $reservationId, string $invitePublicNo): array
    {
        return $this->requireTransaction()->run(function () use ($reservationId, $invitePublicNo): array {
            return $this->consumeReservationLocked($reservationId, $invitePublicNo);
        });
    }

    public function releaseReservationLocked(
        int $reservationId,
        string $invitePublicNo,
        string $reason = '短信未确认成功，释放预占'
    ): array {
        $userId = $this->repository->reservationUserId($reservationId);
        if ($userId === null) {
            throw new RuntimeException('短信余额预占不存在');
        }
        $wallet = $this->lockWallet($userId);
        $reservation = $this->repository->lockReservation($reservationId);
        if (!$reservation || (int) $reservation['user_id'] !== $userId) {
            throw new RuntimeException('短信余额预占状态异常');
        }
        if (in_array((string) $reservation['status'], ['released', 'expired'], true)) {
            return $this->formatWallet($wallet);
        }
        if ((string) $reservation['status'] === 'consumed') {
            throw new RuntimeException('短信费用已经结算，不能释放');
        }

        $wallet = $this->applyLocked(
            $wallet,
            'reservation_release',
            'transfer',
            (int) $reservation['amount_cents'],
            (int) $reservation['amount_cents'],
            -(int) $reservation['amount_cents'],
            (($reservation['business_type']??'')==='chat_sms_nudge'?'chat_sms_nudge':'nearby_worker_sms_invite'),
            (int) $reservation['business_id'],
            'sms_release:' . $invitePublicNo,
            $reason
        );
        if (!$this->repository->transitionReservation($reservationId, 'held', 'released')) {
            throw new RuntimeException('短信余额预占释放状态已变化');
        }
        return $this->formatWallet($wallet);
    }

    public function releaseReservation(
        int $reservationId,
        string $invitePublicNo,
        string $reason = '短信未确认成功，释放预占'
    ): array {
        return $this->requireTransaction()->run(function () use ($reservationId, $invitePublicNo, $reason): array {
            return $this->releaseReservationLocked($reservationId, $invitePublicNo, $reason);
        });
    }

    public function createRechargeRefundHoldLocked(
        int $userId,
        int $refundId,
        string $refundPublicNo,
        int $amountCents
    ): array {
        $this->policy->assertRefundHoldInput($userId, $refundId, $refundPublicNo, $amountCents);
        $wallet = $this->lockWallet($userId);
        $existing = $this->repository->findRefundReservationForUpdate($refundPublicNo);
        if ($existing) {
            $this->policy->assertExistingRefundReservationMatches($existing, $userId, $refundId, $amountCents);
            return ['reservation' => $existing, 'wallet' => $this->formatWallet($wallet)];
        }
        if ((int) $wallet['available_cents'] < $amountCents) {
            throw new RuntimeException('当前可用余额不足原充值金额，不能自动整笔纠错退款，请转人工财务处理');
        }

        $reservation = $this->repository->createRefundReservation($userId, $refundId, $refundPublicNo, $amountCents);
        $wallet = $this->applyLocked(
            $wallet,
            'wallet_refund_hold',
            'transfer',
            $amountCents,
            -$amountCents,
            $amountCents,
            'wallet_recharge_refund',
            $refundId,
            'wallet_refund_hold:' . $refundPublicNo,
            '充值纠错退款冻结'
        );
        return ['reservation' => $reservation, 'wallet' => $this->formatWallet($wallet)];
    }

    public function consumeRechargeRefundHoldLocked(
        int $refundId,
        string $refundPublicNo,
        int $rechargeId
    ): array {
        $userId = $this->repository->findRefundReservationUserId($refundId, $refundPublicNo);
        if ($userId === null) {
            throw new RuntimeException('充值纠错退款冻结记录不存在');
        }
        $wallet = $this->lockWallet($userId);
        $reservation = $this->repository->findRefundReservationByBusinessForUpdate($refundId, $refundPublicNo);
        if (!$reservation) {
            throw new RuntimeException('充值纠错退款冻结记录不存在');
        }
        if ((string) $reservation['status'] === 'consumed') {
            $this->repository->markRechargeRefunded($rechargeId);
            return $this->formatWallet($wallet);
        }
        if ((string) $reservation['status'] !== 'held') {
            throw new RuntimeException('充值纠错退款冻结已释放，不能再次冲销');
        }

        $wallet = $this->applyLocked(
            $wallet,
            'wallet_refund_consume',
            'debit',
            (int) $reservation['amount_cents'],
            0,
            -(int) $reservation['amount_cents'],
            'wallet_recharge_refund',
            $refundId,
            'wallet_refund_consume:' . $refundPublicNo,
            '充值纠错退款成功冲销'
        );
        if (!$this->repository->transitionReservation((int) $reservation['id'], 'held', 'consumed')) {
            throw new RuntimeException('充值纠错退款冻结状态已变化');
        }
        $this->repository->markRechargeRefunded($rechargeId);
        return $this->formatWallet($wallet);
    }

    public function releaseRechargeRefundHoldLocked(
        int $refundId,
        string $refundPublicNo,
        int $rechargeId,
        string $reason = '充值纠错退款失败，释放冻结'
    ): array {
        $userId = $this->repository->findRefundReservationUserId($refundId, $refundPublicNo);
        if ($userId === null) {
            $this->repository->restoreRechargePaid($rechargeId);
            return [];
        }
        $wallet = $this->lockWallet($userId);
        $reservation = $this->repository->findRefundReservationByBusinessForUpdate($refundId, $refundPublicNo);
        if (!$reservation) {
            return $this->formatWallet($wallet);
        }
        if (in_array((string) $reservation['status'], ['released', 'expired'], true)) {
            $this->repository->restoreRechargePaid($rechargeId);
            return $this->formatWallet($wallet);
        }
        if ((string) $reservation['status'] === 'consumed') {
            throw new RuntimeException('充值纠错退款已经成功，不能释放冻结');
        }

        $wallet = $this->applyLocked(
            $wallet,
            'wallet_refund_release',
            'transfer',
            (int) $reservation['amount_cents'],
            (int) $reservation['amount_cents'],
            -(int) $reservation['amount_cents'],
            'wallet_recharge_refund',
            $refundId,
            'wallet_refund_release:' . $refundPublicNo,
            $reason
        );
        if (!$this->repository->transitionReservation((int) $reservation['id'], 'held', 'released')) {
            throw new RuntimeException('充值纠错退款冻结释放状态已变化');
        }
        $this->repository->restoreRechargePaid($rechargeId);
        return $this->formatWallet($wallet);
    }

    public function formatWallet(array $wallet): array
    {
        $available = (int) ($wallet['available_cents'] ?? 0);
        $reserved = (int) ($wallet['reserved_cents'] ?? 0);
        return [
            'balance_cents' => $available,
            'balance_text' => '¥' . number_format($available / 100, 2, '.', ''),
            'reserved_cents' => $reserved,
            'reserved_text' => '¥' . number_format($reserved / 100, 2, '.', ''),
            'can_withdraw' => false,
            'withdraw_notice' => '余额仅用于平台内支持余额支付的服务，暂不支持提现',
            'recharge_min_cents' => max(10, $this->settings->int('wallet.recharge_min_cents', 10)),
            'recharge_max_cents' => max(10, $this->settings->int('wallet.recharge_max_cents', 500000)),
        ];
    }

    private function requireTransaction(): TransactionManager
    {
        if (!$this->transaction) {
            throw new RuntimeException('钱包事务管理器未配置');
        }
        return $this->transaction;
    }
}

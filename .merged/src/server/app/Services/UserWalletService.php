<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager;
use WorkerAdmin\Infrastructure\Persistence\DatabaseUserWalletRepository;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/**
 * @deprecated 用户钱包兼容门面。
 *
 * 原公开方法和 Database 参数保持不变，确保支付/短信/退款旧调用继续使用同一事务连接；
 * 新代码应依赖 Application\Money\UserWalletManager。
 */
final class UserWalletService
{
    private Database $db;
    private UserWalletManager $manager;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->manager = $this->managerFor($db, true);
    }

    public function summary(int $userId): array
    {
        return $this->manager->summary($userId);
    }

    public function ledger(int $userId, int $page = 1, int $pageSize = 20): array
    {
        return $this->manager->ledger($userId, $page, $pageSize);
    }

    public function ensureWallet(int $userId): void
    {
        $this->manager->ensureWallet($userId);
    }

    public function lockWallet(Database $db, int $userId): array
    {
        return $this->managerFor($db, false)->lockWallet($userId);
    }

    public function applyLocked(
        Database $db,
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
        return $this->managerFor($db, false)->applyLocked(
            $wallet,
            $entryType,
            $direction,
            $amountCents,
            $availableDelta,
            $reservedDelta,
            $referenceType,
            $referenceId,
            $idempotencyKey,
            $description
        );
    }

    public function consumeReservationLocked(Database $db, int $reservationId, string $invitePublicNo): array
    {
        return $this->managerFor($db, false)->consumeReservationLocked($reservationId, $invitePublicNo);
    }

    public function consumeReservation(int $reservationId, string $invitePublicNo): array
    {
        return $this->manager->consumeReservation($reservationId, $invitePublicNo);
    }

    public function releaseReservationLocked(
        Database $db,
        int $reservationId,
        string $invitePublicNo,
        string $reason = '短信未确认成功，释放预占'
    ): array {
        return $this->managerFor($db, false)->releaseReservationLocked($reservationId, $invitePublicNo, $reason);
    }

    public function releaseReservation(
        int $reservationId,
        string $invitePublicNo,
        string $reason = '短信未确认成功，释放预占'
    ): array {
        return $this->manager->releaseReservation($reservationId, $invitePublicNo, $reason);
    }

    public function createRechargeRefundHoldLocked(
        Database $db,
        int $userId,
        int $refundId,
        string $refundPublicNo,
        int $amountCents
    ): array {
        return $this->managerFor($db, false)->createRechargeRefundHoldLocked(
            $userId,
            $refundId,
            $refundPublicNo,
            $amountCents
        );
    }

    public function consumeRechargeRefundHoldLocked(
        Database $db,
        int $refundId,
        string $refundPublicNo,
        int $rechargeId
    ): array {
        return $this->managerFor($db, false)->consumeRechargeRefundHoldLocked(
            $refundId,
            $refundPublicNo,
            $rechargeId
        );
    }

    public function releaseRechargeRefundHoldLocked(
        Database $db,
        int $refundId,
        string $refundPublicNo,
        int $rechargeId,
        string $reason = '充值纠错退款失败，释放冻结'
    ): array {
        return $this->managerFor($db, false)->releaseRechargeRefundHoldLocked(
            $refundId,
            $refundPublicNo,
            $rechargeId,
            $reason
        );
    }

    private function managerFor(Database $db, bool $withTransaction): UserWalletManager
    {
        return new UserWalletManager(
            new DatabaseUserWalletRepository($db),
            new GlobalSettingsProvider(),
            $withTransaction ? new DatabaseTransactionManager($db) : null
        );
    }
}

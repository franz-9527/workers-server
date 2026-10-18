<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\UserWalletRepository;
use WorkerAdmin\Core\Database;

final class DatabaseUserWalletRepository implements UserWalletRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function ensureWallet(int $userId): void
    {
        $this->db->execute(
            "INSERT IGNORE INTO user_wallets(user_id,available_cents,reserved_cents,version,created_at,updated_at)
             VALUES(:user_id,0,0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['user_id' => $userId]
        );
    }

    public function findWallet(int $userId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM user_wallets WHERE user_id=:user_id LIMIT 1', ['user_id' => $userId]);
        return $row ?: null;
    }

    public function lockWallet(int $userId): array
    {
        $this->ensureWallet($userId);
        $wallet = $this->db->fetch(
            'SELECT * FROM user_wallets WHERE user_id=:user_id LIMIT 1 FOR UPDATE',
            ['user_id' => $userId]
        );
        if (!$wallet) {
            throw new RuntimeException('用户钱包创建失败');
        }
        return $wallet;
    }

    public function ledgerPage(int $userId, int $limit, int $offset): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        return $this->db->fetchAll(
            "SELECT public_no,entry_type,direction,amount_cents,available_delta_cents,reserved_delta_cents,
                    available_after_cents,reserved_after_cents,reference_type,description,status,created_at
             FROM user_wallet_ledger
             WHERE user_id=:user_id
             ORDER BY id DESC
             LIMIT " . $limit . " OFFSET " . $offset,
            ['user_id' => $userId]
        );
    }

    public function ledgerCount(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM user_wallet_ledger WHERE user_id=:user_id',
            ['user_id' => $userId]
        );
    }

    public function findLedgerBalanceByIdempotency(string $idempotencyKey): ?array
    {
        $row = $this->db->fetch(
            'SELECT available_after_cents,reserved_after_cents FROM user_wallet_ledger WHERE idempotency_key=:key LIMIT 1',
            ['key' => $idempotencyKey]
        );
        return $row ?: null;
    }

    public function updateWalletBalances(
        int $walletId,
        int $beforeAvailable,
        int $beforeReserved,
        int $afterAvailable,
        int $afterReserved
    ): bool {
        return $this->db->execute(
            "UPDATE user_wallets
             SET available_cents=:available,reserved_cents=:reserved,version=version+1,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND available_cents=:before_available AND reserved_cents=:before_reserved",
            [
                'available' => $afterAvailable,
                'reserved' => $afterReserved,
                'id' => $walletId,
                'before_available' => $beforeAvailable,
                'before_reserved' => $beforeReserved,
            ]
        ) === 1;
    }

    public function appendLedger(array $entry): void
    {
        $this->db->insert(
            "INSERT INTO user_wallet_ledger
             (public_no,user_id,entry_type,direction,amount_cents,available_delta_cents,reserved_delta_cents,
              available_after_cents,reserved_after_cents,reference_type,reference_id,idempotency_key,description,status,created_at)
             VALUES(:public_no,:user_id,:entry_type,:direction,:amount,:available_delta,:reserved_delta,
                    :available_after,:reserved_after,:reference_type,:reference_id,:idempotency,:description,'effective',UTC_TIMESTAMP())",
            [
                'public_no' => \public_id(),
                'user_id' => (int) $entry['user_id'],
                'entry_type' => substr((string) $entry['entry_type'], 0, 32),
                'direction' => substr((string) $entry['direction'], 0, 16),
                'amount' => (int) $entry['amount_cents'],
                'available_delta' => (int) $entry['available_delta_cents'],
                'reserved_delta' => (int) $entry['reserved_delta_cents'],
                'available_after' => (int) $entry['available_after_cents'],
                'reserved_after' => (int) $entry['reserved_after_cents'],
                'reference_type' => substr((string) $entry['reference_type'], 0, 40),
                'reference_id' => $entry['reference_id'],
                'idempotency' => substr((string) $entry['idempotency_key'], 0, 128),
                'description' => mb_substr((string) $entry['description'], 0, 500),
            ]
        );
    }

    public function reservationUserId(int $reservationId): ?int
    {
        $row = $this->db->fetch(
            'SELECT user_id FROM user_wallet_reservations WHERE id=:id LIMIT 1',
            ['id' => $reservationId]
        );
        return $row ? (int) $row['user_id'] : null;
    }

    public function lockReservation(int $reservationId): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM user_wallet_reservations WHERE id=:id FOR UPDATE',
            ['id' => $reservationId]
        );
        return $row ?: null;
    }

    public function transitionReservation(int $reservationId, string $fromStatus, string $toStatus): bool
    {
        $params = [
            'id' => $reservationId,
            'from_status' => $fromStatus,
        ];
        if ($toStatus === 'consumed') {
            $sql = "UPDATE user_wallet_reservations
                    SET status='consumed',consumed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                    WHERE id=:id AND status=:from_status";
        } elseif (in_array($toStatus, ['released', 'expired'], true)) {
            $sql = "UPDATE user_wallet_reservations
                    SET status=:to_status,released_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                    WHERE id=:id AND status=:from_status";
            $params['to_status'] = $toStatus;
        } else {
            $sql = "UPDATE user_wallet_reservations
                    SET status=:to_status,updated_at=UTC_TIMESTAMP()
                    WHERE id=:id AND status=:from_status";
            $params['to_status'] = $toStatus;
        }
        return $this->db->execute($sql, $params) === 1;
    }

    public function findRefundReservationForUpdate(string $refundPublicNo): ?array
    {
        $row = $this->db->fetch(
            "SELECT * FROM user_wallet_reservations
             WHERE business_type='wallet_recharge_refund' AND business_key=:business_key
             LIMIT 1 FOR UPDATE",
            ['business_key' => $refundPublicNo]
        );
        return $row ?: null;
    }

    public function findRefundReservationUserId(int $refundId, string $refundPublicNo): ?int
    {
        $row = $this->db->fetch(
            "SELECT user_id FROM user_wallet_reservations
             WHERE business_type='wallet_recharge_refund' AND business_id=:business_id AND business_key=:business_key LIMIT 1",
            ['business_id' => $refundId, 'business_key' => $refundPublicNo]
        );
        return $row ? (int) $row['user_id'] : null;
    }

    public function findRefundReservationByBusinessForUpdate(int $refundId, string $refundPublicNo): ?array
    {
        $row = $this->db->fetch(
            "SELECT * FROM user_wallet_reservations
             WHERE business_type='wallet_recharge_refund' AND business_id=:business_id AND business_key=:business_key
             LIMIT 1 FOR UPDATE",
            ['business_id' => $refundId, 'business_key' => $refundPublicNo]
        );
        return $row ?: null;
    }

    public function createRefundReservation(
        int $userId,
        int $refundId,
        string $refundPublicNo,
        int $amountCents
    ): array {
        $publicNo = \public_id();
        $id = $this->db->insert(
            "INSERT INTO user_wallet_reservations
             (public_no,user_id,amount_cents,business_type,business_id,business_key,status,expires_at,created_at,updated_at)
             VALUES(:public_no,:user_id,:amount,'wallet_recharge_refund',:business_id,:business_key,'held',
                    DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_no' => $publicNo,
                'user_id' => $userId,
                'amount' => $amountCents,
                'business_id' => $refundId,
                'business_key' => $refundPublicNo,
            ]
        );
        $row = $this->db->fetch('SELECT * FROM user_wallet_reservations WHERE id=:id', ['id' => $id]);
        if (!$row) {
            throw new RuntimeException('充值纠错退款冻结记录创建失败');
        }
        return $row;
    }

    public function markRechargeRefunded(int $rechargeId): void
    {
        $this->db->execute(
            "UPDATE user_wallet_recharges
             SET status='refunded',refunded_at=COALESCE(refunded_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['id' => $rechargeId]
        );
    }

    public function restoreRechargePaid(int $rechargeId): void
    {
        $this->db->execute(
            "UPDATE user_wallet_recharges SET status='paid',updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='refund_processing'",
            ['id' => $rechargeId]
        );
    }
}

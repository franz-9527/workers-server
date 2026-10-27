<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\WalletRechargeRepository;
use WorkerAdmin\Core\Database;

final class DatabaseWalletRechargeRepository implements WalletRechargeRepository
{
    private Database $db;

    public function __construct(Database $db) { $this->db = $db; }

    public function findActorByTokenHash(string $tokenHash): ?array
    {
        $row = $this->db->fetch(
            "SELECT s.id AS session_id,u.id AS user_id
             FROM login_sessions s
             JOIN users u ON u.id=s.user_id AND u.status='active'
             WHERE s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
             LIMIT 1",
            ['hash'=>$tokenHash]
        );
        if (!$row) return null;
        $row['session_id']=(int)$row['session_id'];
        $row['user_id']=(int)$row['user_id'];
        return $row;
    }

    public function touchSession(int $sessionId): void
    {
        $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id',['id'=>$sessionId]);
    }

    public function findOwnedPayment(string $paymentNo, int $userId): ?array
    {
        $row=$this->db->fetch(
            "SELECT p.id,p.public_no,p.status,p.api_version,p.wechat_prepay_id,p.expires_at,
                    r.id recharge_id,r.status recharge_status,r.amount_cents
             FROM payment_orders p
             JOIN user_wallet_recharges r ON r.payment_order_id=p.id
             WHERE p.public_no=:payment_no AND p.payer_user_id=:user_id AND p.business_type='wallet_recharge'
             LIMIT 1",
            ['payment_no'=>$paymentNo,'user_id'=>$userId]
        );
        return $row?:null;
    }

    public function expiredPendingPayments(int $limit): array
    {
        $limit=max(1,min(100,$limit));
        return $this->db->fetchAll(
            "SELECT p.id,p.public_no,p.status,p.api_version,p.wechat_prepay_id,p.expires_at,
                    r.id recharge_id,r.status recharge_status,r.user_id,r.amount_cents
             FROM payment_orders p
             JOIN user_wallet_recharges r ON r.payment_order_id=p.id
             WHERE p.business_type='wallet_recharge'
               AND p.status IN ('created','pending')
               AND (
                    (p.expires_at IS NOT NULL AND p.expires_at<=UTC_TIMESTAMP())
                    OR
                    (p.expires_at IS NULL AND p.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR))
               )
               AND r.status IN ('created','awaiting_payment')
             ORDER BY COALESCE(p.expires_at,p.created_at),p.id
             LIMIT ".$limit
        );
    }

    public function closeUnpaid(int $paymentId, int $rechargeId): bool
    {
        return (bool)$this->db->transaction(function(Database $db) use($paymentId,$rechargeId): bool {
            $payment=$db->fetch('SELECT status FROM payment_orders WHERE id=:id LIMIT 1 FOR UPDATE',['id'=>$paymentId]);
            $recharge=$db->fetch('SELECT status FROM user_wallet_recharges WHERE id=:id LIMIT 1 FOR UPDATE',['id'=>$rechargeId]);
            if(!$payment||!$recharge) return false;
            if((string)$payment['status']==='paid'||(string)$recharge['status']==='paid') return false;
            if(!in_array((string)$payment['status'],['created','pending','closed'],true)) return false;
            if(!in_array((string)$recharge['status'],['created','awaiting_payment','closed'],true)) return false;
            $db->execute(
                "UPDATE payment_orders SET status='closed',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status IN ('created','pending')",
                ['id'=>$paymentId]
            );
            $db->execute(
                "UPDATE user_wallet_recharges SET status='closed',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status IN ('created','awaiting_payment')",
                ['id'=>$rechargeId]
            );
            return true;
        });
    }
}

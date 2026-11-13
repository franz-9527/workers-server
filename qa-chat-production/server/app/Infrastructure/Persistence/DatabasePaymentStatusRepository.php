<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PaymentStatusRepository;
use WorkerAdmin\Core\Database;

final class DatabasePaymentStatusRepository implements PaymentStatusRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findOwned(string $paymentPublicNo, int $payerUserId): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM payment_orders WHERE public_no=:no AND payer_user_id=:user_id LIMIT 1',
            ['no'=>$paymentPublicNo,'user_id'=>$payerUserId]
        );
        return $row ?: null;
    }

    public function findById(int $paymentId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM payment_orders WHERE id=:id LIMIT 1', ['id'=>$paymentId]);
        return $row ?: null;
    }

    public function closeExternallyFailed(int $paymentId): void
    {
        $payment = $this->db->fetch('SELECT * FROM payment_orders WHERE id=:id LIMIT 1 FOR UPDATE', ['id'=>$paymentId]);
        if (!$payment) return;

        $this->db->execute(
            "UPDATE payment_orders SET status='closed',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status IN ('created','pending')",
            ['id'=>$paymentId]
        );

        if ((string)$payment['business_type'] === 'commission') {
            $this->db->execute(
                "UPDATE commission_payments SET status='failed' WHERE payment_order_id=:id AND status='pending'",
                ['id'=>$paymentId]
            );
        }
        if ((string)$payment['business_type'] === 'wallet_recharge') {
            $this->db->execute(
                "UPDATE user_wallet_recharges SET status='closed',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE payment_order_id=:id AND status<>'paid'",
                ['id'=>$paymentId]
            );
        }
    }
}

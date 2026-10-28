<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\WalletRechargeCreationRepository;
use WorkerAdmin\Core\Database;

final class DatabaseWalletRechargeCreationRepository implements WalletRechargeCreationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findRequest(int $userId, string $clientRequestId): ?array
    {
        $row = $this->db->fetch(
            'SELECT r.*,p.public_no payment_no,p.status payment_status,p.wechat_prepay_id,p.api_version,p.payer_openid,p.description
             FROM user_wallet_recharges r
             LEFT JOIN payment_orders p ON p.id=r.payment_order_id
             WHERE r.user_id=:user_id AND r.client_request_id=:request_id
             LIMIT 1',
            ['user_id'=>$userId,'request_id'=>$clientRequestId]
        );
        return $row ?: null;
    }

    public function createdInLastHour(int $userId): int
    {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM user_wallet_recharges
             WHERE user_id=:user_id AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)',
            ['user_id'=>$userId]
        );
    }

    public function preparePayment(
        array $actor,
        int $amountCents,
        string $clientRequestId,
        string $apiVersion,
        string $appId,
        string $mchId,
        string $clientIp,
        int $expireMinutes
    ): array {
        $row = $this->db->fetch(
            'SELECT r.*,p.public_no payment_no,p.status payment_status,p.wechat_prepay_id,p.api_version,p.payer_openid,p.description
             FROM user_wallet_recharges r
             LEFT JOIN payment_orders p ON p.id=r.payment_order_id
             WHERE r.user_id=:user_id AND r.client_request_id=:request_id
             LIMIT 1 FOR UPDATE',
            ['user_id'=>(int)$actor['user_id'],'request_id'=>$clientRequestId]
        );
        if ($row) {
            if ((int)$row['amount_cents'] !== $amountCents) {
                throw new RuntimeException('同一充值请求编号不能修改金额');
            }
            if (empty($row['payment_order_id'])) {
                throw new RuntimeException('充值支付单状态异常，请联系客服');
            }
            $payment = $this->db->fetch('SELECT * FROM payment_orders WHERE id=:id LIMIT 1', ['id'=>$row['payment_order_id']]);
            if (!$payment) throw new RuntimeException('充值支付单状态异常，请联系客服');
            return $payment;
        }

        $rechargeNo = \public_id();
        $rechargeId = $this->db->insert(
            "INSERT INTO user_wallet_recharges(public_no,user_id,amount_cents,status,payment_order_id,client_request_id,created_at,updated_at)
             VALUES(:public_no,:user_id,:amount,'created',NULL,:request_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_no'=>$rechargeNo,
                'user_id'=>(int)$actor['user_id'],
                'amount'=>$amountCents,
                'request_id'=>$clientRequestId,
            ]
        );

        $expires = gmdate('Y-m-d H:i:s', time() + $expireMinutes * 60);
        $paymentNo = 'P'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(5)));
        $paymentId = $this->db->insert(
            "INSERT INTO payment_orders(public_no,business_type,business_id,order_id,payer_user_id,payer_openid,app_id,mch_id,api_version,amount_cents,status,client_ip,description,expires_at,created_at,updated_at)
             VALUES(:public_no,'wallet_recharge',:business_id,NULL,:user_id,:openid,:app_id,:mch_id,:api_version,:amount,'created',:ip,:description,:expires,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_no'=>$paymentNo,
                'business_id'=>$rechargeId,
                'user_id'=>(int)$actor['user_id'],
                'openid'=>(string)$actor['payer_openid'],
                'app_id'=>$appId,
                'mch_id'=>$mchId,
                'api_version'=>$apiVersion,
                'amount'=>$amountCents,
                'ip'=>$clientIp,
                'description'=>'壁选用户余额充值-'.$rechargeNo,
                'expires'=>$expires,
            ]
        );
        $this->db->execute(
            "UPDATE user_wallet_recharges
             SET payment_order_id=:payment_id,status='awaiting_payment',updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['payment_id'=>$paymentId,'id'=>$rechargeId]
        );
        $payment = $this->db->fetch('SELECT * FROM payment_orders WHERE id=:id LIMIT 1', ['id'=>$paymentId]);
        if (!$payment) throw new RuntimeException('充值支付单创建失败');
        return $payment;
    }

    public function recoverConcurrentPayment(int $userId, string $clientRequestId): ?array
    {
        $row = $this->db->fetch(
            'SELECT r.amount_cents,r.payment_order_id,p.*
             FROM user_wallet_recharges r
             INNER JOIN payment_orders p ON p.id=r.payment_order_id
             WHERE r.user_id=:user_id AND r.client_request_id=:request_id
             LIMIT 1',
            ['user_id'=>$userId,'request_id'=>$clientRequestId]
        );
        return $row ?: null;
    }

    public function markPending(int $paymentId, string $prepayId): void
    {
        $this->db->execute(
            "UPDATE payment_orders
             SET wechat_prepay_id=:prepay,status='pending',updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='created'",
            ['prepay'=>$prepayId,'id'=>$paymentId]
        );
    }
}

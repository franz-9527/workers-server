<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\CommissionPaymentCreationRepository;
use WorkerAdmin\Core\Database;

final class DatabaseCommissionPaymentCreationRepository implements CommissionPaymentCreationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function prepare(
        array $actor,
        string $receivableNo,
        string $apiVersion,
        string $appId,
        string $merchantId,
        string $clientIp
    ): array {
        $workerId = (int)($actor['worker_id'] ?? 0);
        $receivable = $this->db->fetch(
            'SELECT * FROM commission_receivables WHERE public_no=:no AND worker_id=:worker_id FOR UPDATE',
            ['no'=>$receivableNo,'worker_id'=>$workerId]
        );
        if (!$receivable) throw new RuntimeException('服务费账单不存在');

        $receiptCount = (int)$this->db->fetchValue(
            "SELECT COUNT(*)
             FROM media_links l
             JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
             WHERE l.entity_type='order' AND l.entity_id=:order_id AND l.purpose='collection_receipt'",
            ['order_id'=>$receivable['order_id']]
        );
        if ($receiptCount < 1) {
            throw new RuntimeException('请先在施工订单详情上传线下收款凭证，再支付平台服务费');
        }

        $amount = max(0,(int)$receivable['amount_due_cents']-(int)$receivable['amount_paid_cents']);
        if ($amount < 1) throw new RuntimeException('该账单已经结清');

        $existing = $this->db->fetch(
            "SELECT * FROM payment_orders
             WHERE business_type='commission' AND business_id=:id
               AND status IN ('created','pending','paid')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['id'=>$receivable['id']]
        );
        if ($existing
            && (string)$existing['status'] !== 'paid'
            && !empty($existing['expires_at'])
            && strtotime((string)$existing['expires_at']) <= time()) {
            $this->db->execute(
                "UPDATE payment_orders SET status='closed',updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['id'=>$existing['id']]
            );
            $this->db->execute(
                "UPDATE commission_payments SET status='failed' WHERE payment_order_id=:id AND status='pending'",
                ['id'=>$existing['id']]
            );
            $existing = null;
        }
        if ($existing) return $existing;

        $publicNo = 'C'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(5)));
        $paymentId = $this->db->insert(
            "INSERT INTO payment_orders(
                public_no,business_type,business_id,order_id,payer_user_id,payer_openid,
                app_id,mch_id,api_version,amount_cents,status,client_ip,description,expires_at,created_at,updated_at
             ) VALUES(
                :public_no,'commission',:business_id,:order_id,:user_id,:openid,
                :app_id,:mch_id,:api_version,:amount,'created',:ip,:description,
                DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_no'=>$publicNo,
                'business_id'=>$receivable['id'],
                'order_id'=>$receivable['order_id'],
                'user_id'=>(int)($actor['user_id']??0),
                'openid'=>(string)($actor['payer_openid']??''),
                'app_id'=>$appId,
                'mch_id'=>$merchantId,
                'api_version'=>$apiVersion,
                'amount'=>$amount,
                'ip'=>$clientIp,
                'description'=>'壁选师傅平台服务费-'.(string)$receivable['public_no'],
            ]
        );
        $this->db->insert(
            "INSERT INTO commission_payments(receivable_id,payment_order_id,amount_cents,status,created_at)
             VALUES(:receivable_id,:payment_id,:amount,'pending',UTC_TIMESTAMP())",
            ['receivable_id'=>$receivable['id'],'payment_id'=>$paymentId,'amount'=>$amount]
        );
        $payment = $this->db->fetch('SELECT * FROM payment_orders WHERE id=:id',['id'=>$paymentId]);
        if (!$payment) throw new RuntimeException('服务费支付单创建失败');
        return $payment;
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

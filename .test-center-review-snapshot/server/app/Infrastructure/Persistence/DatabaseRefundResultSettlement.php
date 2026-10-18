<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\PaidPaymentSettlement;
use WorkerAdmin\Contracts\RefundResultSettlement;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\UserWalletService;

/** 已验签微信退款结果的本地收口；SUCCESS 复用支付通知阶段已迁出的成功逻辑。 */
final class DatabaseRefundResultSettlement implements RefundResultSettlement
{
    private Database $db;
    private PaidPaymentSettlement $paidSettlement;

    public function __construct(Database $db, PaidPaymentSettlement $paidSettlement)
    {
        $this->db = $db;
        $this->paidSettlement = $paidSettlement;
    }

    public function apply(array $resource): void
    {
        $outRefundNo = trim((string)($resource['out_refund_no']??''));
        if ($outRefundNo === '') throw new RuntimeException('微信退款通知缺少商户退款单号');

        $refund = $this->db->fetch(
            'SELECT r.*,p.public_no payment_no,p.amount_cents payment_total_cents,p.mch_id payment_mch_id,p.business_type payment_business_type,p.business_id payment_business_id FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id WHERE r.public_no=:no FOR UPDATE',
            ['no'=>$outRefundNo]
        );
        if (!$refund) throw new RuntimeException('微信退款通知对应的退款单不存在');

        $amount = is_array($resource['amount']??null) ? $resource['amount'] : [];
        if ((string)($resource['mchid']??'') !== (string)$refund['payment_mch_id']
            || (string)($resource['out_trade_no']??'') !== (string)$refund['payment_no']
            || (int)($amount['refund']??-1) !== (int)$refund['amount_cents']
            || (int)($amount['total']??-1) !== (int)$refund['payment_total_cents']) {
            throw new RuntimeException('微信退款通知的商户、原支付单或金额不一致');
        }

        $status = strtoupper(trim((string)($resource['refund_status']??$resource['status']??'')));
        if ($status === 'SUCCESS') {
            $this->paidSettlement->applyImmediateRefundSuccess($resource,$outRefundNo);
            return;
        }
        if (!in_array($status,['ABNORMAL','CLOSED'],true)) return;
        // succeeded 是退款单不可逆终态。迟到 ABNORMAL/CLOSED 只做幂等吸收，不得回退成功事实或释放已消费冻结。
        if ((string)$refund['status'] === 'succeeded') return;

        $changed = $this->db->execute(
            "UPDATE refunds SET status='failed',failure_code=:code,failure_message=:message,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status<>'succeeded'",
            [
                'code'=>$status,
                'message'=>(string)($resource['user_received_account']??'退款异常，请管理员处理'),
                'id'=>$refund['id'],
            ]
        );
        if ($changed !== 1) return;

        if ((string)$refund['payment_business_type'] === 'wallet_recharge') {
            $rechargeId = (int)$refund['payment_business_id'];
            if ($rechargeId > 0) {
                (new UserWalletService($this->db))->releaseRechargeRefundHoldLocked(
                    $this->db,
                    (int)$refund['id'],
                    (string)$refund['public_no'],
                    $rechargeId,
                    '微信明确返回退款失败/关闭，释放充值纠错冻结'
                );
            }
            return;
        }

        if (!empty($refund['cancel_request_id']) && !empty($refund['order_id'])) {
            $this->db->execute(
                "UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND refund_status<>'succeeded'",
                ['id'=>$refund['order_id']]
            );
            return;
        }
        if (!empty($refund['order_id']) && !in_array((string)$refund['payment_business_type'],['measurement_balance','measurement_change'],true)) {
            $this->db->execute(
                "UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND refund_status<>'succeeded'",
                ['id'=>$refund['order_id']]
            );
        }
    }
}

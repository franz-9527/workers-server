<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\PaidPaymentSettlement;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;
use WorkerAdmin\Services\UserWalletService;

/** 微信支付成功后的资金与业务事实落库；外部退款调用由 Application 在事务提交后执行。 */
final class DatabasePaidPaymentSettlement implements PaidPaymentSettlement
{
    private Database $db;
    private DatabaseOrderPaidPaymentSettlement $orders;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->orders = new DatabaseOrderPaidPaymentSettlement($db,$settings);
    }

    public function settle(array $resource): ?int
    {
        $outTradeNo = trim((string)($resource['out_trade_no']??''));
        if ($outTradeNo === '') throw new RuntimeException('微信回调缺少商户支付单号');
        if ((string)($resource['trade_state']??'') !== 'SUCCESS') {
            $id = $this->db->fetchValue('SELECT id FROM payment_orders WHERE public_no=:no',['no'=>$outTradeNo]);
            return $id ? (int)$id : null;
        }

        $payment = $this->db->fetch('SELECT * FROM payment_orders WHERE public_no=:no LIMIT 1 FOR UPDATE',['no'=>$outTradeNo]);
        if (!$payment) throw new RuntimeException('微信回调对应的支付单不存在');
        $total = (int)($resource['amount']['total']??-1);
        if ((string)($resource['appid']??'') !== (string)$payment['app_id']
            || (string)($resource['mchid']??'') !== (string)$payment['mch_id']
            || $total !== (int)$payment['amount_cents']) {
            throw new RuntimeException('微信回调的商户、AppID 或金额与支付单不一致');
        }
        if (!empty($payment['payer_openid']) && !empty($resource['payer']['openid'])
            && (string)$resource['payer']['openid'] !== (string)$payment['payer_openid']) {
            throw new RuntimeException('微信回调付款人不一致');
        }
        $currentStatus = (string)$payment['status'];
        if ($currentStatus === 'paid') return (int)$payment['id'];
        // refunded 是支付单不可逆终态。迟到或换通知 ID 的 SUCCESS 只能被幂等吸收，不能把资金事实倒退为 paid。
        if ($currentStatus === 'refunded') return (int)$payment['id'];

        $changed = $this->db->execute(
            "UPDATE payment_orders SET status='paid',wechat_transaction_id=:transaction,paid_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status<>'refunded'",
            ['transaction'=>(string)($resource['transaction_id']??''),'id'=>$payment['id']]
        );
        if ($changed !== 1) return (int)$payment['id'];
        $payment['status'] = 'paid';
        $businessType = (string)$payment['business_type'];
        if (in_array($businessType,['order_booking','booking_fee','run_empty_fee','measurement_balance','measurement_change'],true)) {
            $this->orders->settle($businessType,$payment);
        } elseif ($businessType === 'commission') {
            $this->confirmCommissionPayment($payment);
        } elseif ($businessType === 'wallet_recharge') {
            $this->confirmWalletRechargePayment($payment);
        }
        return (int)$payment['id'];
    }

    public function pendingAutomaticRefund(string $paymentPublicNo): ?array
    {
        $row = $this->db->fetch(
            "SELECT r.*,p.public_no payment_no,p.amount_cents total_cents,p.api_version
             FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id
             WHERE p.public_no=:no AND r.status='created' ORDER BY r.id DESC LIMIT 1",
            ['no'=>$paymentPublicNo]
        );
        return $row ?: null;
    }

    public function markAutomaticRefundProcessing(int $refundId, ?string $wechatRefundId): void
    {
        $this->db->execute(
            "UPDATE refunds SET status='processing',wechat_refund_id=:refund_id,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='created'",
            ['refund_id'=>$wechatRefundId,'id'=>$refundId]
        );
    }

    public function applyImmediateRefundSuccess(array $resource, string $outRefundNo): void
    {
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
        $status = (string)($resource['refund_status']??$resource['status']??'');
        if ($status !== 'SUCCESS') return;
        if ((string)$refund['status'] === 'succeeded') return;

        $this->db->execute(
            "UPDATE refunds SET status='succeeded',wechat_refund_id=:refund_id,succeeded_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id",
            ['refund_id'=>(string)($resource['refund_id']??''),'id'=>$refund['id']]
        );
        $fullyRefunded = $this->syncPaymentRefundStatus((int)$refund['payment_order_id']);
        if ($fullyRefunded && (string)$refund['payment_business_type'] === 'measurement_change') {
            $this->db->execute("UPDATE change_requests SET payment_status='refunded',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$refund['payment_business_id']]);
        }
        if ((string)$refund['payment_business_type'] === 'wallet_recharge') {
            $rechargeId = (int)$refund['payment_business_id'];
            if ($rechargeId < 1) throw new RuntimeException('充值纠错退款缺少充值业务单');
            (new UserWalletService($this->db))->consumeRechargeRefundHoldLocked(
                $this->db,(int)$refund['id'],(string)$refund['public_no'],$rechargeId
            );
            return;
        }
        if (!empty($refund['cancel_request_id'])) {
            $this->finalizeCancelRefundIfComplete((int)$refund['cancel_request_id'],(int)$refund['order_id']);
            return;
        }
        if (!$refund['order_id']) return;
        if ((string)$refund['payment_business_type'] === 'measurement_balance') {
            $this->event((int)$refund['order_id'],'measurement_late_balance_refunded','system',0,[
                'refund_no'=>$refund['public_no'],'amount_cents'=>(int)$refund['amount_cents'],'message'=>'无效或迟到的测量补款已原路退回，测量订单保持原业务状态',
            ]);
        } elseif ((string)$refund['payment_business_type'] === 'measurement_change') {
            $this->event((int)$refund['order_id'],'measurement_change_refunded','system',0,[
                'refund_no'=>$refund['public_no'],'amount_cents'=>(int)$refund['amount_cents'],'message'=>'无效或迟到的测量加费已原路退回，订单原成交总价保持不变',
            ]);
        } else {
            $this->db->execute(
                "UPDATE orders SET refund_status='succeeded',lifecycle_status='cancelled',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$refund['order_id']]
            );
            $this->event((int)$refund['order_id'],'refund_succeeded','system',0,['refund_no'=>$refund['public_no'],'amount_cents'=>(int)$refund['amount_cents']]);
        }
    }

    private function confirmWalletRechargePayment(array $payment): void
    {
        $recharge = $this->db->fetch('SELECT * FROM user_wallet_recharges WHERE id=:id LIMIT 1 FOR UPDATE',['id'=>$payment['business_id']]);
        if (!$recharge) throw new RuntimeException('余额充值业务单不存在');
        if ((int)$recharge['user_id'] !== (int)$payment['payer_user_id'] || (int)$recharge['amount_cents'] !== (int)$payment['amount_cents']) {
            throw new RuntimeException('余额充值付款人与金额不匹配');
        }
        $walletService = new UserWalletService($this->db);
        $wallet = $walletService->lockWallet($this->db,(int)$recharge['user_id']);
        $walletService->applyLocked(
            $this->db,$wallet,'recharge','credit',(int)$recharge['amount_cents'],(int)$recharge['amount_cents'],0,
            'wallet_recharge',(int)$recharge['id'],'wallet_recharge:'.(string)$recharge['public_no'],'微信充值到账'
        );
        $this->db->execute(
            "UPDATE user_wallet_recharges SET status='paid',paid_at=COALESCE(paid_at,UTC_TIMESTAMP()),closed_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id",
            ['id'=>$recharge['id']]
        );
    }

    private function confirmCommissionPayment(array $payment): void
    {
        $receivable = $this->db->fetch('SELECT * FROM commission_receivables WHERE id=:id FOR UPDATE',['id'=>$payment['business_id']]);
        if (!$receivable) throw new RuntimeException('佣金应收不存在');
        $this->db->execute("UPDATE commission_payments SET status='paid',paid_at=UTC_TIMESTAMP() WHERE payment_order_id=:id",['id'=>$payment['id']]);
        $paid = min((int)$receivable['amount_due_cents'],(int)$receivable['amount_paid_cents']+(int)$payment['amount_cents']);
        $isProvisional = (int)$receivable['is_provisional'] === 1;
        $status = $isProvisional
            ? ($paid >= (int)$receivable['amount_due_cents'] ? 'estimate_settled' : 'estimate_paid')
            : ($paid >= (int)$receivable['amount_due_cents'] ? 'paid' : 'pending');
        $this->db->execute(
            "UPDATE commission_receivables SET amount_paid_cents=:paid_value,status=:status,settled_at=IF(:paid_time>=amount_due_cents,UTC_TIMESTAMP(),settled_at),updated_at=UTC_TIMESTAMP() WHERE id=:id",
            ['paid_value'=>$paid,'status'=>$status,'paid_time'=>$paid,'id'=>$receivable['id']]
        );
    }

    private function syncPaymentRefundStatus(int $paymentId): bool
    {
        $payment = $this->db->fetch('SELECT id,amount_cents,status FROM payment_orders WHERE id=:id FOR UPDATE',['id'=>$paymentId]);
        if (!$payment) return false;
        $succeeded = (int)$this->db->fetchValue("SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE payment_order_id=:id AND status='succeeded'",['id'=>$paymentId]);
        $full = $succeeded >= (int)$payment['amount_cents'];
        if ($full) $this->db->execute("UPDATE payment_orders SET status='refunded',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$paymentId]);
        return $full;
    }

    private function finalizeCancelRefundIfComplete(int $cancelRequestId, int $orderId): bool
    {
        $request = $this->db->fetch('SELECT * FROM order_cancel_requests WHERE id=:id FOR UPDATE',['id'=>$cancelRequestId]);
        if (!$request) return false;
        $stats = $this->db->fetch(
            "SELECT COUNT(*) total,SUM(status='succeeded') succeeded,SUM(status='failed') failed,COALESCE(SUM(CASE WHEN status='succeeded' THEN amount_cents ELSE 0 END),0) succeeded_cents FROM refunds WHERE cancel_request_id=:id",
            ['id'=>$cancelRequestId]
        );
        $total=(int)($stats['total']??0);$succeeded=(int)($stats['succeeded']??0);$failed=(int)($stats['failed']??0);
        if ($total < 1) return false;
        if ($failed > 0) {
            $this->db->execute("UPDATE orders SET refund_status='failed',updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$orderId]);
            return false;
        }
        if ($succeeded < $total) {
            $this->db->execute("UPDATE orders SET refund_status='processing',updated_at=UTC_TIMESTAMP() WHERE id=:id AND refund_status<>'processing'",['id'=>$orderId]);
            return false;
        }
        if ($request['status'] === 'approved' && !empty($request['closed_at'])) return true;
        $this->db->execute("UPDATE order_cancel_requests SET status='approved',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=:id",['id'=>$cancelRequestId]);
        $this->db->execute("UPDATE orders SET refund_status='succeeded',lifecycle_status='cancelled',closed_at=COALESCE(closed_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$orderId]);
        $this->event($orderId,'refund_succeeded','system',0,[
            'amount_cents'=>(int)($stats['succeeded_cents']??0),'refund_count'=>$total,'message'=>'取消订单的 '.$total.' 笔原路退款均已由微信确认成功',
        ]);
        return true;
    }

    private function event(int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($this->db,true))->schedule($orderId);
        }
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $this->db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            ['order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,'actor_id'=>$actorId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        );
        OrderNotificationService::queue($this->db,$orderId,$eventKey,$type,$payload);
    }
}

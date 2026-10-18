<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 平台单验收后退款的独立人工核账流程。
 * 仅记录风险、责任分配和经人工核实的线下追偿；绝不回滚微信退款、
 * 绝不伪造实际收款、不直接扣减已经入账或可能已提现的师傅钱包余额。
 */
final class PlatformOrderRefundReviewWriter
{
    /** @return array<int,array<string,mixed>> */
    public function cases(Database $db): array
    {
        return $db->fetchAll(
            "SELECT r.id refund_id,r.public_no refund_no,r.amount_cents refund_amount_cents,
                    r.succeeded_at,o.id order_id,o.public_no order_no,o.title,o.accepted_at,
                    t.worker_settlement_mode,t.worker_labor_cents,t.worker_balance_credit_cents,
                    t.settlement_status,
                    v.id review_id,v.status review_status,v.responsibility,v.worker_recovery_cents,
                    v.recovered_cents,v.decision_note,v.recovery_reference,v.decided_at,v.resolved_at,
                    (SELECT COALESCE(SUM(m.amount_cents),0) FROM platform_order_manual_receipts m
                     WHERE m.refund_review_id=v.id AND m.receipt_kind='refund_recovery') AS receipt_total_cents,
                    (SELECT COUNT(*) FROM platform_order_manual_receipts m
                     WHERE m.refund_review_id=v.id AND m.receipt_kind='refund_recovery') AS receipt_count,
                    EXISTS(SELECT 1 FROM worker_balance_ledger l
                      WHERE l.idempotency_key=CONCAT('platform-order:',o.id,':worker-income')
                        AND l.status='effective') AS worker_income_posted,
                    EXISTS(SELECT 1 FROM worker_balance_ledger l
                      WHERE l.idempotency_key=CONCAT('platform-order:',o.id,':worker-income-release')
                        AND l.status='effective') AS worker_income_released
             FROM refunds r
             INNER JOIN payment_orders p ON p.id=r.payment_order_id
             INNER JOIN orders o ON o.id=p.order_id AND o.order_source_type='platform'
             INNER JOIN order_platform_terms t ON t.order_id=o.id
             LEFT JOIN platform_order_refund_reviews v ON v.refund_id=r.id
             WHERE r.status='succeeded' AND r.amount_cents>0 AND o.accepted_at IS NOT NULL
               AND (r.order_id IS NULL OR r.order_id=o.id)
               AND t.settlement_status IN ('awaiting_platform_income','settled')
             ORDER BY CASE WHEN v.status IS NULL OR v.status<>'resolved' THEN 0 ELSE 1 END,
                      r.id DESC LIMIT 100"
        );
    }

    public function open(Database $db, int $refundId, int $adminId): void
    {
        $db->transaction(function (Database $db) use ($refundId, $adminId): void {
            // 所有变更保持先锁订单、再锁平台条款/退款/复核单的顺序。
            $parent = $db->fetch(
                'SELECT p.order_id FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id WHERE r.id=:id',
                ['id'=>$refundId]
            );
            if (!$parent) throw new OrderOperationException('退款记录不存在', 'PLATFORM_REFUND_NOT_FOUND', 404);
            $orderId = (int)$parent['order_id'];
            $order = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$orderId]);
            $terms = $db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id FOR UPDATE', ['id'=>$orderId]);
            if (!$order || !$terms || (string)$order['order_source_type'] !== 'platform'
                || empty($order['accepted_at'])
                || !in_array((string)$terms['settlement_status'], ['awaiting_platform_income','settled'], true)) {
                throw new OrderOperationException('仅已验收并生成结算快照的平台承接单可发起退款核账', 'PLATFORM_REFUND_REVIEW_NOT_ELIGIBLE', 409);
            }
            $refund = $db->fetch(
                "SELECT r.* FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id
                 WHERE r.id=:id AND p.order_id=:order_id FOR UPDATE",
                ['id'=>$refundId,'order_id'=>$orderId]
            );
            if (!$refund || (string)$refund['status'] !== 'succeeded'
                || (int)$refund['amount_cents'] < 1
                || ($refund['order_id'] !== null && (int)$refund['order_id'] !== $orderId)) {
                throw new OrderOperationException('仅能核账已真实退款成功且关联本单支付流水的退款', 'PLATFORM_REFUND_NOT_SUCCEEDED', 409);
            }
            if ($db->fetch('SELECT id FROM platform_order_refund_reviews WHERE refund_id=:id FOR UPDATE', ['id'=>$refundId])) {
                return; // 重复点击、网络重放不制造第二份责任记录。
            }
            $reviewId = $db->insert(
                "INSERT INTO platform_order_refund_reviews
                 (order_id,refund_id,refund_amount_cents,worker_labor_cents_snapshot,
                  worker_balance_credit_cents_snapshot,status,responsibility,worker_recovery_cents,
                  recovered_cents,created_by_admin_id,created_at,updated_at)
                 VALUES(:order_id,:refund_id,:refund,:labor,:credit,'open','undetermined',0,0,
                        :admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$orderId,'refund_id'=>$refundId,'refund'=>$refund['amount_cents'],
                 'labor'=>$terms['worker_labor_cents'],'credit'=>$terms['worker_balance_credit_cents'],
                 'admin_id'=>$adminId > 0 ? $adminId : null]
            );
            $this->event($db, $orderId, 'platform_refund_review_opened', $adminId, [
                'review_id'=>$reviewId,'refund_id'=>$refundId,'refund_amount_cents'=>(int)$refund['amount_cents'],
                'worker_balance_credit_cents'=>(int)$terms['worker_balance_credit_cents'],
                'note'=>'事后退款单独建档，原工费台账保持原样，禁止自动冲正',
            ]);
        });
    }

    public function decide(Database $db, int $reviewId, string $responsibility, int $workerRecoveryCents,
                           string $note, int $adminId): void
    {
        $db->transaction(function (Database $db) use ($reviewId,$responsibility,$workerRecoveryCents,$note,$adminId): void {
            $orderId = $this->orderId($db, $reviewId);
            $db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE', ['id'=>$orderId]);
            $case = $db->fetch('SELECT * FROM platform_order_refund_reviews WHERE id=:id FOR UPDATE', ['id'=>$reviewId]);
            if (!$case || (string)$case['status'] !== 'open') {
                throw new OrderOperationException('退款核账已判责或已经处理，请刷新', 'PLATFORM_REFUND_REVIEW_ALREADY_DECIDED', 409);
            }
            $refund = $db->fetch('SELECT status,amount_cents FROM refunds WHERE id=:id FOR UPDATE', ['id'=>$case['refund_id']]);
            if (!$refund || (string)$refund['status'] !== 'succeeded'
                || (int)$refund['amount_cents'] !== (int)$case['refund_amount_cents']) {
                throw new OrderOperationException('退款金额或状态发生变化，请先人工复核', 'PLATFORM_REFUND_AMOUNT_CHANGED', 409);
            }
            $refundCents = (int)$case['refund_amount_cents'];
            if (!in_array($responsibility, ['platform','worker','shared'], true)
                || $workerRecoveryCents < 0 || $workerRecoveryCents > $refundCents
                || ($responsibility === 'platform' && $workerRecoveryCents !== 0)
                || ($responsibility === 'worker' && $workerRecoveryCents !== $refundCents)
                || ($responsibility === 'shared' && ($workerRecoveryCents === 0 || $workerRecoveryCents === $refundCents))) {
                throw new OrderOperationException('责任分配与师傅需补交金额不一致', 'PLATFORM_REFUND_RESPONSIBILITY_INVALID', 422);
            }
            $status = $workerRecoveryCents > 0 ? 'awaiting_recovery' : 'resolved';
            $changed = $db->execute(
                "UPDATE platform_order_refund_reviews
                 SET responsibility=:responsibility,worker_recovery_cents=:worker_cents,
                     decision_note=:note,status=:status,decided_by_admin_id=:admin,
                     decided_at=UTC_TIMESTAMP(),resolved_at=IF(:resolved='resolved',UTC_TIMESTAMP(),NULL),
                     resolved_by_admin_id=IF(:resolved_admin='resolved',:admin_resolved,NULL),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='open'",
                ['responsibility'=>$responsibility,'worker_cents'=>$workerRecoveryCents,'note'=>$note,
                 'status'=>$status,'admin'=>$adminId > 0 ? $adminId : null,
                 'resolved'=>$status,'resolved_admin'=>$status,'admin_resolved'=>$adminId > 0 ? $adminId : null,
                 'id'=>$reviewId]
            );
            if ($changed !== 1) throw new OrderOperationException('退款核账已由其他管理员处理', 'PLATFORM_REFUND_REVIEW_RACE', 409);
            $this->event($db, $orderId, 'platform_refund_review_decided', $adminId, [
                'review_id'=>$reviewId,'refund_id'=>(int)$case['refund_id'],
                'responsibility'=>$responsibility,'worker_recovery_cents'=>$workerRecoveryCents,
                'platform_borne_cents'=>$refundCents-$workerRecoveryCents,
                'status'=>$status,'note'=>$note,
                'wallet_auto_debit'=>false,
            ]);
        });
    }

    /** 仅对每笔真正核实到账的款项分次入账；旧的五参数调用仍按剩余全额核验。 */
    public function confirmRecovery(Database $db, int $reviewId, string $reference, string $note,
                                    int $adminId, ?int $amountCents=null): void
    {
        $db->transaction(function (Database $db) use ($reviewId,$reference,$note,$adminId,$amountCents): void {
            $orderId = $this->orderId($db, $reviewId);
            $db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE', ['id'=>$orderId]);
            $case = $db->fetch('SELECT * FROM platform_order_refund_reviews WHERE id=:id FOR UPDATE', ['id'=>$reviewId]);
            if (!$case || (string)$case['status'] !== 'awaiting_recovery'
                || (int)$case['worker_recovery_cents'] < 1) {
                throw new OrderOperationException('该退款核账没有待核实的线下追偿款', 'PLATFORM_REFUND_RECOVERY_NOT_DUE', 409);
            }
            $refund = $db->fetch('SELECT status,amount_cents FROM refunds WHERE id=:id FOR UPDATE', ['id'=>$case['refund_id']]);
            if (!$refund || (string)$refund['status'] !== 'succeeded'
                || (int)$refund['amount_cents'] !== (int)$case['refund_amount_cents']) {
                throw new OrderOperationException('退款状态或金额有变化，请先人工复核', 'PLATFORM_REFUND_AMOUNT_CHANGED', 409);
            }
            $recovered = (int)$case['recovered_cents'];
            $target = (int)$case['worker_recovery_cents'];
            $remaining = $target - $recovered;
            if ($remaining < 1) {
                throw new OrderOperationException('本单已经追偿完毕', 'PLATFORM_REFUND_RECOVERY_NOT_DUE', 409);
            }
            $amount = $amountCents === null ? $remaining : $amountCents;
            if ($amount < 1 || $amount > $remaining) {
                throw new OrderOperationException('本次核对到账金额必须大于零且不能超过待追偿金额', 'PLATFORM_REFUND_RECOVERY_AMOUNT_INVALID', 422);
            }
            // 对账一致性校验：不得直接手改累计金额跳过实际收款台账。
            $registered = (int)$db->fetchValue(
                "SELECT COALESCE(SUM(amount_cents),0) FROM platform_order_manual_receipts
                 WHERE refund_review_id=:id AND receipt_kind='refund_recovery'",
                ['id'=>$reviewId]
            );
            if ($registered !== $recovered) {
                throw new OrderOperationException('原追偿流水与累计金额不一致，请先核查财务台账', 'PLATFORM_REFUND_RECOVERY_LEDGER_MISMATCH', 409);
            }
            $receiptId = (new PlatformOrderManualReceiptRecorder())->record(
                $db,$orderId,$reviewId,'refund_recovery',$amount,$reference,$note,$adminId
            );
            $nextRecovered = $recovered + $amount;
            $nextStatus = $nextRecovered === $target ? 'resolved' : 'awaiting_recovery';
            $changed = $db->execute(
                "UPDATE platform_order_refund_reviews
                 SET status=:status,recovered_cents=:total,recovery_reference=:reference,
                     recovery_note=:note,resolved_at=IF(:final_status='resolved',UTC_TIMESTAMP(),NULL),
                     resolved_by_admin_id=IF(:final_actor='resolved',:admin,NULL),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='awaiting_recovery' AND recovered_cents=:before",
                ['status'=>$nextStatus,'total'=>$nextRecovered,'reference'=>$reference,'note'=>$note,
                 'final_status'=>$nextStatus,'final_actor'=>$nextStatus,'admin'=>$adminId > 0 ? $adminId : null,
                 'id'=>$reviewId,'before'=>$recovered]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('追偿累计金额已发生变化，请刷新后重试', 'PLATFORM_REFUND_RECOVERY_ALREADY_CONFIRMED', 409);
            }
            $this->event($db,$orderId,'platform_refund_recovery_confirmed',$adminId,[
                'review_id'=>$reviewId,'refund_id'=>(int)$case['refund_id'],'manual_receipt_id'=>$receiptId,
                'confirmed_amount_cents'=>$amount,'cumulative_recovered_cents'=>$nextRecovered,
                'remaining_cents'=>$target-$nextRecovered,'recovery_reference'=>$reference,
                'status'=>$nextStatus,'note'=>$note,'verified_manually'=>true,'wallet_auto_debit'=>false,
            ]);
        });
    }

    private function orderId(Database $db, int $reviewId): int
    {
        $row = $db->fetch('SELECT order_id FROM platform_order_refund_reviews WHERE id=:id', ['id'=>$reviewId]);
        if (!$row) throw new OrderOperationException('退款复核单不存在', 'PLATFORM_REFUND_REVIEW_NOT_FOUND', 404);
        return (int)$row['order_id'];
    }

    private function event(Database $db, int $orderId, string $type, int $adminId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,'admin',:actor_id,:payload,UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'type'=>$type,'actor_id'=>$adminId > 0 ? $adminId : null,
             'payload'=>is_string($json) ? $json : '{}']
        );
    }
}

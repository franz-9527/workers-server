<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PlatformOrderFinanceRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class DatabasePlatformOrderFinanceRepository implements PlatformOrderFinanceRepository
{
    private Database $db;
    private PlatformOrderBookingPaymentVerifier $bookingVerifier;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->bookingVerifier = new PlatformOrderBookingPaymentVerifier();
    }

    /** 只查询风险，不擅自修改已结算快照或冲正已经入账/提现的师傅余额。 */
    private function riskSql(): string
    {
        return "(o.dispute_status NOT IN ('none','resolved')
                  OR o.refund_status NOT IN ('none','failed')
                  OR EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing'))
                  OR EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id
                       AND r.status NOT IN ('failed','closed','cancelled','rejected'))
                  OR EXISTS(SELECT 1 FROM refunds br INNER JOIN payment_orders bp ON bp.id=br.payment_order_id
                       WHERE bp.order_id=o.id AND bp.business_type IN ('order_booking','booking_fee')
                         AND br.status NOT IN ('failed','closed','cancelled','rejected'))
                  OR EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing'))
                  OR EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing')))";
    }

    public function dashboard(): array
    {
        $risk = $this->riskSql();
        return [
            'changes'=>$this->db->fetchAll(
                "SELECT c.id,c.public_id,c.order_id,c.original_amount_cents,c.added_amount_cents,c.new_total_cents,c.reason,c.submitted_at,
                        o.public_no,o.title,t.customer_contract_total_cents,t.worker_labor_cents,w.display_name worker_name
                 FROM change_requests c
                 INNER JOIN orders o ON o.id=c.order_id AND o.order_source_type='platform'
                 INNER JOIN order_platform_terms t ON t.order_id=o.id AND t.worker_settlement_mode='platform_balance'
                 INNER JOIN worker_profiles w ON w.id=c.worker_id
                 WHERE c.status='pending' ORDER BY c.id DESC LIMIT 100"
            ),
            'receivables'=>$this->db->fetchAll(
                "SELECT o.id,o.public_no,o.title,o.accepted_at,t.customer_contract_total_cents,t.booking_paid_cents,t.customer_tail_cents,
                        t.platform_receivable_cents,t.worker_labor_cents,t.worker_balance_credit_cents,t.settlement_status,t.worker_settlement_mode,w.display_name worker_name,
                        ".$risk." AS finance_risk
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 LEFT JOIN worker_profiles w ON w.id=o.assigned_worker_id
                 WHERE o.order_source_type='platform' AND t.settlement_status='awaiting_platform_income'
                 ORDER BY o.id DESC LIMIT 100"
            ),
            'exceptions'=>$this->db->fetchAll(
                "SELECT o.id,o.public_no,o.title,o.dispute_status,o.refund_status,
                        t.settlement_status,t.platform_receivable_cents,t.booking_paid_cents
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.order_source_type='platform'
                   AND t.settlement_status IN ('awaiting_platform_income','settled')
                   AND ".$risk." ORDER BY o.id DESC LIMIT 100"
            ),
            'refundReviews'=>(new PlatformOrderRefundReviewWriter())->cases($this->db),
            'recentSettled'=>$this->db->fetchAll(
                "SELECT o.public_no,o.title,t.platform_receivable_cents,t.worker_settlement_mode,t.settled_at,
                        w.display_name worker_name
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 LEFT JOIN worker_profiles w ON w.id=o.assigned_worker_id
                 WHERE o.order_source_type='platform' AND t.settlement_status='settled'
                 ORDER BY t.settled_at DESC,o.id DESC LIMIT 30"
            ),
        ];
    }

    public function approveChange(int $changeId, int $customerAddCents, string $note, int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($changeId,$customerAddCents,$note,$adminId): void {
            $change = $db->fetch(
                "SELECT c.*,o.current_assignment_id,o.lifecycle_status,o.fulfillment_status,o.public_no,
                        t.customer_contract_total_cents,t.worker_labor_cents,t.worker_settlement_mode
                 FROM change_requests c
                 INNER JOIN orders o ON o.id=c.order_id AND o.order_source_type='platform'
                 INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE c.id=:id FOR UPDATE",
                ['id'=>$changeId]
            );
            if (!$change || (string)$change['status'] !== 'pending') {
                throw new OrderOperationException('工费加价申请不存在或已经处理。', 'PLATFORM_CHANGE_NOT_PENDING', 409);
            }
            if ((string)$change['worker_settlement_mode'] !== 'platform_balance') {
                throw new OrderOperationException('只有平台余额结算单由平台审核工费加价。', 'PLATFORM_CHANGE_WRONG_MODE', 409);
            }
            if ((string)$change['lifecycle_status'] !== 'active' || in_array((string)$change['fulfillment_status'], ['submitted','accepted'], true)) {
                throw new OrderOperationException('订单已经进入完工阶段，不能再批准加价。', 'PLATFORM_CHANGE_TOO_LATE', 409);
            }
            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND worker_id=:worker_id AND status='accepted' FOR UPDATE",
                ['id'=>$change['current_assignment_id'] ?: 0,'worker_id'=>$change['worker_id']]
            );
            if (!$assignment) throw new OrderOperationException('当前成交师傅关系已变化。', 'PLATFORM_ASSIGNMENT_CHANGED', 409);

            $laborAdded = (int)$change['added_amount_cents'];
            $workerLabor = (int)$change['worker_labor_cents'] + $laborAdded;
            $customerTotal = (int)$change['customer_contract_total_cents'] + $customerAddCents;
            if ($workerLabor > 100000000 || $customerTotal > 100000000) {
                throw new OrderOperationException('加价后的金额超出允许范围。', 'PLATFORM_CHANGE_TOTAL_TOO_LARGE', 422);
            }

            $db->execute("UPDATE change_requests SET status='accepted',payment_status=NULL,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$change['id']]);
            $db->execute(
                "UPDATE order_platform_terms SET worker_labor_cents=:labor,customer_contract_total_cents=:customer_total,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                ['labor'=>$workerLabor,'customer_total'=>$customerTotal,'order_id'=>$change['order_id']]
            );
            $db->execute(
                "UPDATE assignments SET final_amount_cents_snapshot=:labor,estimated_commission_cents=0,estimated_net_income_cents=:labor,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['labor'=>$workerLabor,'id'=>$assignment['id']]
            );
            if ($customerAddCents > 0) {
                $db->execute("UPDATE orders SET final_amount_cents=:customer_total,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['customer_total'=>$customerTotal,'id'=>$change['order_id']]);
                $sort = (int)$db->fetchValue('SELECT COALESCE(MAX(sort_order),0)+1 FROM order_pricing_items WHERE order_id=:id', ['id'=>$change['order_id']]);
                $db->insert(
                    "INSERT INTO order_pricing_items(order_id,item_source,change_request_id,description,note,quantity,unit,unit_price_cents,amount_cents,sort_order,status,created_at,updated_at)
                     VALUES(:order_id,'change',:change_id,'平台批准加项',:note,1,'项',:amount,:amount2,:sort_order,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'order_id'=>$change['order_id'],'change_id'=>$change['id'],
                        'note'=>$note !== '' ? $note : $change['reason'],
                        'amount'=>$customerAddCents,'amount2'=>$customerAddCents,'sort_order'=>$sort,
                    ]
                );
            }
            $this->event($db, (int)$change['order_id'], 'platform_balance_change_approved', $adminId, [
                'change_id'=>$change['public_id'],
                'worker_added_cents'=>$laborAdded,
                'customer_added_cents'=>$customerAddCents,
                'worker_labor_cents'=>$workerLabor,
                'customer_total_cents'=>$customerTotal,
                'note'=>$note,
            ]);
        });
    }

    public function rejectChange(int $changeId, string $note, int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($changeId,$note,$adminId): void {
            $change = $db->fetch(
                "SELECT c.*,o.order_source_type,t.worker_settlement_mode
                 FROM change_requests c INNER JOIN orders o ON o.id=c.order_id
                 INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE c.id=:id FOR UPDATE",
                ['id'=>$changeId]
            );
            if (!$change || (string)$change['order_source_type'] !== 'platform'
                || (string)$change['worker_settlement_mode'] !== 'platform_balance'
                || (string)$change['status'] !== 'pending') {
                throw new OrderOperationException('该工费加价申请已经不能驳回。', 'PLATFORM_CHANGE_REJECT_NOT_ALLOWED', 409);
            }
            $db->execute("UPDATE change_requests SET status='rejected',payment_status=NULL,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$change['id']]);
            $this->event($db, (int)$change['order_id'], 'platform_balance_change_rejected', $adminId, [
                'change_id'=>$change['public_id'],'reason'=>$note,
            ]);
        });
    }

    public function markIncomePaid(int $orderId, int $confirmedAmountCents, string $paymentReference, string $note, int $adminId): void
    {
        $this->db->transaction(function (Database $db) use ($orderId,$confirmedAmountCents,$paymentReference,$note,$adminId): void {
            $terms = $db->fetch(
                "SELECT t.*,o.order_source_type,o.lifecycle_status,o.fulfillment_status,o.accepted_at,
                        o.dispute_status,o.refund_status
                 FROM order_platform_terms t INNER JOIN orders o ON o.id=t.order_id
                 WHERE t.order_id=:id FOR UPDATE",
                ['id'=>$orderId]
            );
            if (!$terms || (string)$terms['order_source_type'] !== 'platform') {
                throw new OrderOperationException('平台承接单不存在。', 'PLATFORM_ORDER_NOT_FOUND', 404);
            }
            if ((string)$terms['settlement_status'] !== 'awaiting_platform_income'
                || (string)$terms['lifecycle_status'] !== 'completed'
                || (string)$terms['fulfillment_status'] !== 'accepted'
                || empty($terms['accepted_at'])) {
                throw new OrderOperationException('订单尚未验收或平台收入款已经处理。', 'PLATFORM_INCOME_NOT_DUE', 409);
            }
            $due = (int)$terms['platform_receivable_cents'];
            if ($due <= 0 || $due !== $confirmedAmountCents) {
                throw new OrderOperationException('到账金额与本单平台收入款不一致，请核对后再确认；暂不支持部分到账直接结清。', 'PLATFORM_INCOME_AMOUNT_MISMATCH', 409);
            }
            // 收入款到账不意味着退款和售后业务不存在：发现异常先人工核账，不能直接标已结清。
            if (!in_array((string)$terms['dispute_status'], ['none','resolved'], true)
                || !in_array((string)$terms['refund_status'], ['none','failed'], true)
                || (int)$db->fetchValue(
                    'SELECT '.$this->riskSql().' FROM orders o WHERE o.id=:order_id',
                    ['order_id'=>$orderId]
                ) !== 0) {
                throw new OrderOperationException('该订单有退款、争议或售后资金待核对事项，暂不能确认收入款结清', 'PLATFORM_INCOME_RISK_HOLD', 409);
            }
            // 回调核实的 paid 付款如果随后退款，金额不能仍按历史已收快照确认。
            $verifiedBooking = $this->bookingVerifier->verifiedPaidCents(
                $db, $orderId, (int)$terms['customer_contract_total_cents'], (int)$terms['booking_paid_cents']
            );
            if ($verifiedBooking !== (int)$terms['booking_paid_cents']) {
                throw new OrderOperationException('实际预约费流水与验收快照不一致，请先核对财务', 'PLATFORM_INCOME_BOOKING_CHANGED', 409);
            }
            // 同一银行/转账参考号不得在两个平台订单上重复记为完整到账。
            $reused = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM order_events
                 WHERE event_type='platform_income_received' AND order_id<>:order_id
                   AND JSON_VALID(payload_json)
                   AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.payment_reference'))=:reference",
                ['order_id'=>$orderId,'reference'=>$paymentReference]
            );
            if ($reused > 0) {
                throw new OrderOperationException('该交易流水编号已用于其他平台订单，请核查是否重复登记', 'PLATFORM_INCOME_REFERENCE_REUSED', 409);
            }
            // 与退款分笔追偿共用唯一真实外部流水索引；事务失败会一同回滚。
            $manualReceiptId = (new PlatformOrderManualReceiptRecorder())->record(
                $db,$orderId,null,'platform_income',$confirmedAmountCents,$paymentReference,$note,$adminId
            );
            $changed = $db->execute(
                "UPDATE order_platform_terms SET settlement_status='settled',settled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND settlement_status='awaiting_platform_income'",
                ['id'=>$orderId]
            );
            if ($changed !== 1) throw new OrderOperationException('该平台收入款已由其他管理员处理，请刷新。', 'PLATFORM_INCOME_ALREADY_SETTLED', 409);
            $this->event($db, $orderId, 'platform_income_received', $adminId, [
                'platform_receivable_cents'=>$due,
                'confirmed_amount_cents'=>$confirmedAmountCents,
                'payment_reference'=>$paymentReference,
                'manual_receipt_id'=>$manualReceiptId,
                'note'=>$note,
                'verified_manually'=>true,
            ]);
        });
    }

    public function openRefundReview(int $refundId, int $adminId): void
    {
        (new PlatformOrderRefundReviewWriter())->open($this->db, $refundId, $adminId);
    }

    public function decideRefundReview(int $reviewId, string $responsibility, int $workerRecoveryCents, string $note, int $adminId): void
    {
        (new PlatformOrderRefundReviewWriter())->decide($this->db, $reviewId, $responsibility, $workerRecoveryCents, $note, $adminId);
    }

    public function confirmRefundRecovery(int $reviewId, int $amountCents, string $paymentReference, string $note, int $adminId): void
    {
        (new PlatformOrderRefundReviewWriter())->confirmRecovery($this->db, $reviewId, $paymentReference, $note, $adminId, $amountCents);
    }

    private function event(Database $db, int $orderId, string $type, int $adminId, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventId = $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,'admin',:actor_id,:payload,UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'type'=>$type,'actor_id'=>$adminId > 0 ? $adminId : null,'payload'=>is_string($json) ? $json : '{}']
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $type, $payload);
    }
}

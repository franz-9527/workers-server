<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\PlatformOrderSettlementPolicy;

/**
 * 平台单验收的资金侧事务步骤。调用者必须已锁定 orders、order_platform_terms、assignments，
 * 并处于同一个事务；本类不单独提交事务、不生成虚假的客户支付记录。
 * 普通客户订单（包括后台代下单）不调用该类，也绝不创建普通订单服务费。
 */
final class PlatformOrderSettlementWriter
{
    private PlatformOrderSettlementPolicy $policy;
    private PlatformOrderBookingPaymentVerifier $bookingVerifier;

    public function __construct()
    {
        $this->policy = new PlatformOrderSettlementPolicy();
        $this->bookingVerifier = new PlatformOrderBookingPaymentVerifier();
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $terms
     * @param array<string,mixed> $assignment
     * @return array<string,int|string>
     */
    public function apply(Database $db, array $order, array $terms, array $assignment): array
    {
        $orderId = (int)($order['id'] ?? 0);
        $workerId = (int)($order['assigned_worker_id'] ?? 0);
        if ($orderId < 1 || (string)($order['order_source_type'] ?? '') !== 'platform'
            || (int)($terms['order_id'] ?? 0) !== $orderId
            || (string)($terms['customer_flow_mode'] ?? '') !== 'platform_managed'
            || (int)($assignment['worker_id'] ?? 0) !== $workerId
            || $workerId < 1
            || (string)($assignment['status'] ?? '') !== 'accepted'
            || (string)($terms['settlement_status'] ?? '') !== 'pending') {
            throw new OrderOperationException('平台单结算关系或状态已变化，请刷新后重试', 'PLATFORM_SETTLEMENT_STATE_CHANGED', 409);
        }
        // 师傅现场收款凭证是客户向师傅付款的证据，不等同于师傅已向平台付清收入款。
        $receiptCount = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:order_id AND ml.purpose='collection_receipt'",
            ['order_id'=>$orderId]
        );
        if ($receiptCount < 1) {
            throw new OrderOperationException('师傅尚未上传客户现场收款凭证，当前不能验收', 'COLLECTION_RECEIPT_REQUIRED', 409);
        }

        // booking_paid_cents 对平台单表示“外部渠道已实际收取的客户预付款”。
        // orders.booking_fee_cents 属于普通订单微信预约费口径，平台单不得再读取或抵扣它。
        $bookingPaid = $this->bookingVerifier->verifiedPaidCents(
            $db,
            $orderId,
            (int)$terms['customer_contract_total_cents'],
            (int)($terms['booking_paid_cents'] ?? 0),
            'platform_managed'
        );
        $calculation = $this->policy->settlement(
            (int)$terms['customer_contract_total_cents'],
            (int)$terms['worker_labor_cents'],
            $bookingPaid,
            (string)$terms['worker_settlement_mode']
        );
        $platformReceivable = (int)$calculation['platform_receivable_cents'];
        $workerCredit = (int)$calculation['worker_balance_credit_cents'];
        $freezeDays = max(0, (int)$terms['worker_balance_freeze_days_snapshot']);
        $status = $platformReceivable > 0 ? 'awaiting_platform_income' : 'settled';

        $changed = $db->execute(
            "UPDATE order_platform_terms SET booking_paid_cents=:booking,customer_tail_cents=:tail,
                    platform_receivable_cents=:platform_income,worker_balance_credit_cents=:worker_credit,
                    settlement_status=:status,settled_at=IF(:settled_status='settled',UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:order_id AND settlement_status='pending' AND customer_flow_mode='platform_managed'",
            [
                'booking'=>$calculation['booking_paid_cents'],
                'tail'=>$calculation['customer_tail_cents'],
                'platform_income'=>$platformReceivable,'worker_credit'=>$workerCredit,
                'status'=>$status,'settled_status'=>$status,'order_id'=>$orderId,
            ]
        );
        if ($changed !== 1) {
            throw new OrderOperationException('平台结算已被处理，请刷新后重试', 'PLATFORM_SETTLEMENT_ALREADY_POSTED', 409);
        }
        $assignmentChanged = $db->execute(
            "UPDATE assignments SET status='completed',
                    estimated_commission_cents=0,estimated_net_income_cents=:labor,updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND worker_id=:worker_id AND status='accepted'",
            ['labor'=>$terms['worker_labor_cents'],'id'=>$assignment['id'],'worker_id'=>$workerId]
        );
        if ($assignmentChanged !== 1) {
            throw new OrderOperationException('成交师傅关系已变化，请刷新后重试', 'PLATFORM_ASSIGNMENT_CHANGED', 409);
        }
        if ($workerCredit > 0) {
            $entry = $this->policy->workerBalanceEntry($workerCredit, $freezeDays);
            $idempotencyKey = 'platform-order:'.$orderId.':worker-income';
            $existing = $db->fetch(
                'SELECT id FROM worker_balance_ledger WHERE idempotency_key=:key LIMIT 1 FOR UPDATE',
                ['key'=>$idempotencyKey]
            );
            if ($existing) {
                throw new OrderOperationException('师傅工费入账记录已存在，请先核对，禁止重复入账', 'PLATFORM_WORKER_CREDIT_ALREADY_POSTED', 409);
            }
            $inserted = $db->execute(
                "INSERT INTO worker_balance_ledger(worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                        business_type,business_id,idempotency_key,description,status,created_at)
                 VALUES(:worker_id,:entry_type,'credit',:amount,:available,:frozen,'platform_order',:business_id,:key,:description,'effective',UTC_TIMESTAMP())",
                [
                    'worker_id'=>$workerId,'entry_type'=>$entry['entry_type'],'amount'=>$workerCredit,
                    'available'=>$entry['available_delta_cents'],'frozen'=>$entry['frozen_delta_cents'],
                    'business_id'=>$orderId,'key'=>$idempotencyKey,
                    'description'=>$freezeDays === 0 ? '平台承接单工费结算进入可提现余额' : '平台承接单工费进入冻结余额',
                ]
            );
            if ($inserted !== 1) {
                throw new OrderOperationException('师傅余额入账失败，验收已自动回滚', 'PLATFORM_WORKER_CREDIT_FAILED', 409);
            }
        }
        return $calculation + [
            'settlement_status'=>$status,
            'worker_settlement_mode'=>(string)$terms['worker_settlement_mode'],
            'freeze_days'=>$freezeDays,
        ];
    }
}
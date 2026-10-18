<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AgreedRefundExecutionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 双方退款协议成立后的财务执行适配器。
 *
 * 这里只把仍然真实可原路退回的线上款项拆成 refunds.created；不会自动提交微信，
 * 不会修改双方已经达成的退款金额，也不会自动冲减师傅钱包或伪造线下回款。
 */
final class DatabaseAgreedRefundExecutionRepository implements AgreedRefundExecutionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function cases(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT cr.id cancel_request_id,cr.public_id request_id,cr.requested_refund_cents,cr.reason,
                    cr.requested_at,cr.updated_at,o.id order_id,o.public_no order_no,o.title,
                    o.workflow_profile,o.order_source_type,o.lifecycle_status,o.fulfillment_status,
                    o.payment_status,o.dispute_status,o.booking_fee_cents,o.refund_status,o.version
             FROM order_cancel_requests cr
             INNER JOIN orders o ON o.id=cr.order_id
             WHERE cr.status='pending' AND cr.reason_code='bilateral_refund_execution_required'
             ORDER BY cr.updated_at ASC,cr.id ASC LIMIT 100"
        );
        foreach ($rows as &$row) {
            $state = $this->inspect($this->db, $row, false);
            $row['current_refundable_cents'] = $state['available_cents'];
            $row['can_prepare'] = empty($state['blockers']);
            $row['blocking_reason'] = $state['blockers'] ? (string)$state['blockers'][0]['message'] : '';
            $row['payment_count'] = count($state['payments']);
        }
        unset($row);
        return $rows;
    }

    public function prepare(string $requestNo, string $note, int $adminId): array
    {
        if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);

        return $this->db->transaction(function (Database $db) use ($requestNo,$note,$adminId): array {
            $seed = $db->fetch(
                'SELECT order_id FROM order_cancel_requests WHERE BINARY public_id=BINARY :request_no LIMIT 1',
                ['request_no'=>$requestNo]
            );
            if (!$seed) throw new OrderOperationException('退款协议记录不存在', 'AGREED_REFUND_REQUEST_NOT_FOUND', 404);

            // 固定锁顺序：订单 -> 取消申请 -> 支付/退款/争议等资金事实。
            $order = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$seed['order_id']]);
            $request = $db->fetch(
                'SELECT * FROM order_cancel_requests WHERE order_id=:order_id AND BINARY public_id=BINARY :request_no FOR UPDATE',
                ['order_id'=>$seed['order_id'],'request_no'=>$requestNo]
            );
            if (!$order || !$request) throw new OrderOperationException('退款协议记录不存在', 'AGREED_REFUND_REQUEST_NOT_FOUND', 404);
            if ((string)$request['status'] !== 'pending'
                || (string)$request['reason_code'] !== 'bilateral_refund_execution_required') {
                throw new OrderOperationException('该退款协议已经进入执行或状态已变化，请刷新', 'AGREED_REFUND_EXECUTION_CHANGED', 409);
            }

            $snapshot = $order;
            $snapshot['cancel_request_id'] = (int)$request['id'];
            $snapshot['request_id'] = (string)$request['public_id'];
            $snapshot['requested_refund_cents'] = (int)$request['requested_refund_cents'];
            $state = $this->inspect($db, $snapshot, true);
            if ($state['blockers']) {
                $first = $state['blockers'][0];
                throw new OrderOperationException(
                    (string)$first['message'],
                    (string)$first['code'],
                    409,
                    [
                        'agreed_refund_cents'=>(int)$request['requested_refund_cents'],
                        'current_refundable_cents'=>(int)$state['available_cents'],
                    ]
                );
            }

            $refundIds = [];
            $refundNos = [];
            foreach ($state['payments'] as $payment) {
                $amount = (int)$payment['available_cents'];
                if ($amount < 1) continue;
                $refundNo = \business_no('RF');
                $refundId = $db->insert(
                    "INSERT INTO refunds(
                        public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,
                        requested_by_admin_id,requested_at,created_at,updated_at
                     ) VALUES(
                        :public_no,:payment_id,:order_id,:request_id,:amount,:reason,'created',
                        :admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                     )",
                    [
                        'public_no'=>$refundNo,
                        'payment_id'=>$payment['id'],
                        'order_id'=>$order['id'],
                        'request_id'=>$request['id'],
                        'amount'=>$amount,
                        'reason'=>mb_substr(
                            '双方已接受退款方案，Web 财务核账生成；'.$note.'；原支付：'.(string)$payment['business_type'],
                            0,
                            1000
                        ),
                        'admin_id'=>$adminId,
                    ]
                );
                $refundIds[] = $refundId;
                $refundNos[] = $refundNo;
            }
            if (!$refundIds || array_sum(array_map(static function (array $row): int {
                return (int)$row['available_cents'];
            }, $state['payments'])) !== (int)$request['requested_refund_cents']) {
                throw new OrderOperationException('真实可退支付与双方协议金额不一致，不能生成退款单', 'AGREED_REFUND_AMOUNT_CHANGED', 409);
            }

            $changed = $db->execute(
                "UPDATE order_cancel_requests
                 SET status='processing',reason_code='bilateral_refund_execution_prepared',updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='pending' AND reason_code='bilateral_refund_execution_required'",
                ['id'=>$request['id']]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('退款协议状态已变化，请刷新', 'AGREED_REFUND_EXECUTION_CHANGED', 409);
            }

            // 2026-10-08：双方退款协议执行前捕获报价和成交事实。
            (new DatabaseOrderCancellationRiskRepository($db))->captureCancellation($order);
            $db->execute(
                "UPDATE orders
                 SET lifecycle_status='cancel_approved_pending_refund',refund_status='pending_review',publish_status='hidden',
                     quote_status='cancelled',assignment_status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
                     updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id",
                ['id'=>$order['id']]
            );
            $db->execute(
                "UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND status IN ('active','selected')",
                ['id'=>$order['id']]
            );
            $db->execute(
                "UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND status IN ('offered','accepted')",
                ['id'=>$order['id']]
            );
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
            $db->execute(
                "UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND status='active'",
                ['id'=>$order['id']]
            );
            $db->execute(
                "UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id AND status IN ('pending','blocked','claimed')",
                ['id'=>$order['id']]
            );

            $payload = json_encode([
                'cancel_request_id'=>(string)$request['public_id'],
                'agreed_refund_cents'=>(int)$request['requested_refund_cents'],
                'refund_ids'=>$refundIds,
                'refund_nos'=>$refundNos,
                'refund_count'=>count($refundIds),
                'note'=>$note,
                'message'=>'双方退款协议已进入财务执行，已按真实原支付生成待提交退款单；尚未向微信发起退款',
            ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->insert(
                "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'agreed_refund_execution_prepared',:event_key,'admin',:admin_id,:payload,UTC_TIMESTAMP())",
                [
                    'order_id'=>$order['id'],
                    'event_key'=>'agreed_refund_execution_prepared:'.(int)$request['id'],
                    'admin_id'=>$adminId,
                    'payload'=>is_string($payload) ? $payload : '{}',
                ]
            );

            return [
                'order_id'=>(int)$order['id'],
                'order_no'=>(string)$order['public_no'],
                'request_id'=>(string)$request['public_id'],
                'refund_cents'=>(int)$request['requested_refund_cents'],
                'refund_count'=>count($refundIds),
                'refund_ids'=>$refundIds,
                'refund_nos'=>$refundNos,
                'order_source_type'=>(string)($order['order_source_type'] ?? 'consumer'),
                'requires_manual_submit'=>true,
            ];
        });
    }

    /** @return array{available_cents:int,payments:array<int,array<string,mixed>>,blockers:array<int,array{code:string,message:string}>} */
    private function inspect(Database $db, array $row, bool $forUpdate): array
    {
        $payments = $this->refundablePayments($db, $row, $forUpdate);
        $available = 0;
        foreach ($payments as $payment) $available += (int)$payment['available_cents'];
        $requested = max(0, (int)($row['requested_refund_cents'] ?? 0));
        $blockers = [];

        // 新协议会在双方接受时立即冻结履约；兼容冻结前已经产生的 execution_required 旧记录。
        if (!in_array((string)($row['lifecycle_status'] ?? ''), ['active','cancel_approved_pending_refund'], true)
            || in_array((string)($row['fulfillment_status'] ?? ''), ['submitted','accepted'], true)) {
            $blockers[] = ['code'=>'AGREED_REFUND_ORDER_CHANGED','message'=>'订单履约状态已经变化，不能按原退款协议继续执行'];
        }
        if ((string)($row['payment_status'] ?? 'paid') !== 'paid') {
            $blockers[] = ['code'=>'AGREED_REFUND_PAYMENT_CHANGED','message'=>'订单支付状态已经变化，请先核对真实支付流水'];
        }
        if ($requested < 1) {
            $blockers[] = ['code'=>'AGREED_REFUND_NO_ONLINE_AMOUNT','message'=>'双方协议当前没有可原路退回的线上金额，请人工核对线下资金事实'];
        } elseif ($available !== $requested) {
            $blockers[] = ['code'=>'AGREED_REFUND_AMOUNT_CHANGED','message'=>'当前真实可退线上余额与双方协议金额不一致，请先核对历史退款或资金分配'];
        }
        foreach ($payments as $payment) {
            if ((int)$payment['available_cents'] > 0 && (string)$payment['status'] !== 'paid') {
                $blockers[] = ['code'=>'AGREED_REFUND_PAYMENT_CHANGED','message'=>'原支付单已经不是可退款的已支付状态，请先核对微信支付流水'];
                break;
            }
        }

        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $orderId = (int)($row['order_id'] ?? $row['id'] ?? 0);
        $activeDispute = $orderId > 0 ? $db->fetch(
            "SELECT id FROM disputes
             WHERE order_id=:order_id AND status IN ('open','pending','processing','negotiating')
             ORDER BY id DESC LIMIT 1".$lock,
            ['order_id'=>$orderId]
        ) : null;
        if (!in_array((string)($row['dispute_status'] ?? 'none'), ['', 'none','resolved'], true) || $activeDispute) {
            $blockers[] = ['code'=>'AGREED_REFUND_DISPUTE_ACTIVE','message'=>'本单仍有未结争议，请先完成争议处理再执行双方退款协议'];
        }

        $runEmpty = $orderId > 0 ? $db->fetch(
            "SELECT id,status FROM run_empty_fee_claims
             WHERE order_id=:order_id AND status NOT IN ('cancelled','paid')
             ORDER BY id DESC LIMIT 1".$lock,
            ['order_id'=>$orderId]
        ) : null;
        if ($runEmpty) {
            $blockers[] = ['code'=>'AGREED_REFUND_RUN_EMPTY_ACTIVE','message'=>'跑空费仍未最终处理，请先完成跑空费协商/争议再执行退款'];
        }

        if ((string)($row['order_source_type'] ?? 'consumer') !== 'platform' && $orderId > 0) {
            $ledger = $db->fetch(
                "SELECT l.id FROM worker_balance_ledger l
                 WHERE l.status='effective'
                   AND NOT (l.business_type='dispute' AND l.entry_type='run_empty_fee')
                   AND (
                        (l.business_type IN ('order','platform_order','measurement_order','order_measurement') AND l.business_id=:order_id)
                        OR (l.business_type='commission' AND l.business_id IN (SELECT id FROM commission_receivables WHERE order_id=:order_id2))
                        OR (l.business_type='dispute' AND l.business_id IN (SELECT id FROM disputes WHERE order_id=:order_id3))
                   )
                 ORDER BY l.id DESC LIMIT 1".$lock,
                ['order_id'=>$orderId,'order_id2'=>$orderId,'order_id3'=>$orderId]
            );
            if ($ledger) {
                $blockers[] = ['code'=>'AGREED_REFUND_LEDGER_RECONCILIATION_REQUIRED','message'=>'本单已有非跑空费资金计入师傅/平台台账，需先完成财务核账，不能直接生成线上退款'];
            }
        }

        return ['available_cents'=>$available,'payments'=>$payments,'blockers'=>$this->uniqueBlockers($blockers)];
    }

    /** @return array<int,array<string,mixed>> */
    private function refundablePayments(Database $db, array $order, bool $forUpdate): array
    {
        $orderId = (int)($order['order_id'] ?? $order['id'] ?? 0);
        if ($orderId < 1) return [];
        $measurement = (string)($order['workflow_profile'] ?? 'construction') === 'measurement';
        $types = $measurement
            ? ['order_booking','booking_fee','measurement_balance','measurement_change']
            : ['order_booking','booking_fee'];
        $marks = [];
        $params = ['order_id'=>$orderId];
        foreach ($types as $index=>$type) {
            $key = 'type_'.$index;
            $marks[] = ':'.$key;
            $params[$key] = $type;
        }
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $rows = $db->fetchAll(
            "SELECT id,public_no,business_type,status,amount_cents
             FROM payment_orders
             WHERE order_id=:order_id AND business_type IN (".implode(',', $marks).") AND status IN ('paid','refunded')
             ORDER BY CASE business_type
                WHEN 'measurement_change' THEN 1 WHEN 'measurement_balance' THEN 2
                WHEN 'booking_fee' THEN 3 WHEN 'order_booking' THEN 4 ELSE 9 END,id DESC".$lock,
            $params
        );

        $runnerRows = $db->fetchAll(
            "SELECT l.amount_cents
             FROM worker_balance_ledger l
             INNER JOIN disputes d ON d.id=l.business_id
             WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee'
               AND l.status='effective' AND d.order_id=:order_id".$lock,
            ['order_id'=>$orderId]
        );
        $runnerRemaining = 0;
        foreach ($runnerRows as $runnerRow) $runnerRemaining += max(0, (int)$runnerRow['amount_cents']);

        $result = [];
        foreach ($rows as $row) {
            $refundRows = $db->fetchAll(
                "SELECT amount_cents FROM refunds
                 WHERE payment_order_id=:payment_id
                   AND NOT (
                        status IN ('closed','cancelled','rejected')
                        OR (status='failed' AND COALESCE(failure_code,'') IN ('CLOSED','CLOSED_REPLACED'))
                   )".$lock,
                ['payment_id'=>$row['id']]
            );
            $refundAllocated = 0;
            foreach ($refundRows as $refundRow) $refundAllocated += max(0, (int)$refundRow['amount_cents']);

            $cap = max(0, (int)$row['amount_cents']);
            if (in_array((string)$row['business_type'], ['order_booking','booking_fee'], true)
                && (int)($order['booking_fee_cents'] ?? 0) > 0) {
                $cap = min($cap, (int)$order['booking_fee_cents']);
            }
            $runnerApplied = 0;
            if (in_array((string)$row['business_type'], ['order_booking','booking_fee'], true)) {
                $runnerApplied = min($cap, $runnerRemaining);
                $runnerRemaining = max(0, $runnerRemaining - $runnerApplied);
            }
            $row['available_cents'] = max(0, $cap - $refundAllocated - $runnerApplied);
            if ((int)$row['available_cents'] > 0) $result[] = $row;
        }
        return $result;
    }

    /** @param array<int,array{code:string,message:string}> $blockers @return array<int,array{code:string,message:string}> */
    private function uniqueBlockers(array $blockers): array
    {
        $seen = [];
        $result = [];
        foreach ($blockers as $blocker) {
            $key = (string)$blocker['code'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $result[] = $blocker;
        }
        return $result;
    }
}

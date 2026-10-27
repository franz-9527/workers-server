<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderCancellationRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseOrderCancellationRepository implements OrderCancellationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function request(array $actor, string $orderNo, string $reason, string $reasonCode): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$reason,$reasonCode): array {
            $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE', ['public_no'=>$orderNo]);
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能取消自己的订单', 'FORBIDDEN', 403);
            }
            if ((string)$order['payment_status'] !== 'paid') {
                throw new OrderOperationException('预约费尚未支付，不存在可退款款项；如不需要该订单可直接保留未付款状态', 'REFUND_NOT_AVAILABLE_BEFORE_PAYMENT', 409);
            }
            if ((string)$order['lifecycle_status'] !== 'active' || in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) {
                throw new OrderOperationException('该订单当前不能申请退款并取消订单', 'ORDER_NOT_CANCELLABLE', 409);
            }
            if ($db->fetchValue(
                "SELECT id FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing','approved') AND closed_at IS NULL LIMIT 1 FOR UPDATE",
                ['id'=>$order['id']]
            )) {
                throw new OrderOperationException('已经存在待处理的取消申请', 'CANCEL_REQUEST_ALREADY_OPEN', 409);
            }

            $payments = $this->refundablePayments($db, $order);
            $refundAmount = array_sum(array_map(static function (array $row): int { return (int)$row['available_cents']; }, $payments));
            $auto = $refundAmount > 0 && $this->canAutoApproveBeforeService($db, $order);
            $requestPublicId = \public_id();

            if (!$auto) {
                $db->insert(
                    "INSERT INTO order_cancel_requests(public_id,order_id,applicant_type,applicant_id,request_type,order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,requested_at,created_at,updated_at)
                     VALUES(:public_id,:order_id,'user',:user_id,'cancel_refund',:snapshot,:reason_code,:reason,'pending',:refund,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'public_id'=>$requestPublicId,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],
                        'snapshot'=>$this->snapshot($order),'reason_code'=>!empty($order['assigned_worker_id']) ? 'bilateral_refund_pending' : $reasonCode,'reason'=>$reason,'refund'=>$refundAmount,
                    ]
                );
                $db->execute(
                    "UPDATE orders SET refund_status='pending_review',publish_status='hidden',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$order['id']]
                );
                $this->event($db, (int)$order['id'], 'cancel_requested', 'user', (int)$actor['user_id'], [
                    'requested_refund_cents'=>$refundAmount,
                    'notify_recipients'=>!empty($order['assigned_worker_id']) ? ['worker'] : ['admin'],
                    'message'=>!empty($order['assigned_worker_id']) ? '用户提出退款方案，请师傅先接受或拒绝；方案金额 ¥'.number_format($refundAmount/100, 2) : '用户申请退款取消，当前可退线上实收合计 ¥'.number_format($refundAmount/100, 2),
                ]);
                return ['request_id'=>$requestPublicId,'auto_approved'=>false,'bilateral_negotiation'=>!empty($order['assigned_worker_id']),'refund_cents'=>$refundAmount,'refund_count'=>0];
            }

            $requestId = $db->insert(
                "INSERT INTO order_cancel_requests(public_id,order_id,applicant_type,applicant_id,request_type,order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,requested_at,created_at,updated_at)
                 VALUES(:public_id,:order_id,'user',:user_id,'cancel_refund',:snapshot,'pre_service_auto_refund',:reason,'approved',:refund,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'public_id'=>$requestPublicId,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],
                    'snapshot'=>$this->snapshot($order),'reason'=>$reason,'refund'=>$refundAmount,
                ]
            );

            return $this->approveRefund($db, $order, $requestId, $requestPublicId, $payments, $refundAmount, $reason, (int)$actor['user_id']);
        });
    }


    // 2026-10-08：接受方案时锁内复核对方身份和资金；复用未成交自动退款的同一执行器。
    public function approveByAgreement(array $actor, string $requestNo): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$requestNo): array {
            $seed = $db->fetch('SELECT order_id FROM order_cancel_requests WHERE BINARY public_id=BINARY :no', ['no'=>$requestNo]);
            if (!$seed) throw new OrderOperationException('退款方案不存在', 'REFUND_PROPOSAL_NOT_FOUND', 404);
            $order = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$seed['order_id']]);
            $request = $db->fetch('SELECT * FROM order_cancel_requests WHERE BINARY public_id=BINARY :no FOR UPDATE', ['no'=>$requestNo]);
            $isWorker = (string)$request['applicant_type'] === 'user' && (int)($actor['worker_id'] ?? 0) > 0 && (int)$order['assigned_worker_id'] === (int)$actor['worker_id'];
            $isUser = (string)$request['applicant_type'] === 'worker' && (int)$order['user_id'] === (int)($actor['user_id'] ?? 0);
            if (!$isWorker && !$isUser) throw new OrderOperationException('只能由方案的另一方接受', 'FORBIDDEN', 403);
            if ((string)$request['status'] !== 'pending' || (string)$request['reason_code'] !== 'bilateral_refund_pending'
                || (string)$order['lifecycle_status'] !== 'active' || in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)) {
                throw new OrderOperationException('方案已经响应或订单状态已变化', 'REFUND_PROPOSAL_NOT_PENDING', 409);
            }
            $assignment = $db->fetch("SELECT id FROM assignments WHERE id=:id AND order_id=:order AND worker_id=:worker AND status='accepted' FOR UPDATE", ['id'=>$order['current_assignment_id'] ?: 0,'order'=>$order['id'],'worker'=>$order['assigned_worker_id']]);
            if (!$assignment) throw new OrderOperationException('成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            $payments = $this->refundablePayments($db, $order);
            $amount = array_sum(array_column($payments, 'available_cents'));
            // 已同意不等于能够随意分配资金：平台工费/跑空费/争议/已入账收入保持既有财务核验。
            $blocked = (string)$order['order_source_type'] === 'platform' || $amount !== (int)$request['requested_refund_cents'] || $amount < 1
                || !in_array((string)($order['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)
                || (bool)$db->fetchValue("SELECT id FROM run_empty_fee_claims WHERE order_id=:id AND status NOT IN ('cancelled') LIMIT 1", ['id'=>$order['id']])
                || (bool)$db->fetchValue("SELECT l.id FROM worker_balance_ledger l WHERE l.status='effective' AND ((l.business_type IN ('order','platform_order','measurement_order','order_measurement') AND l.business_id=:id) OR (l.business_type='commission' AND l.business_id IN (SELECT id FROM commission_receivables WHERE order_id=:id)) OR (l.business_type='dispute' AND l.business_id IN (SELECT id FROM disputes WHERE order_id=:id))) LIMIT 1", ['id'=>$order['id']]);
            if ($blocked) {
                $db->execute("UPDATE order_cancel_requests SET reason_code='bilateral_refund_execution_required',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$request['id']]);
                // 双方已经接受取消/退款方案时，业务履约立即终止；这里只等待资金核账，不允许订单继续施工。
                $db->execute(
                    "UPDATE orders
                     SET lifecycle_status='cancel_approved_pending_refund',refund_status='pending_review',publish_status='hidden',
                         quote_status='cancelled',assignment_status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
                         version=version+1,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id",
                    ['id'=>$order['id']]
                );
                $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id'=>$order['id']]);
                $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id'=>$order['id']]);
                $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
                $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
                $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id'=>$order['id']]);
                $this->event($db, (int)$order['id'], 'cancel_requested', $isWorker ? 'worker' : 'user', $isWorker ? (int)$actor['worker_id'] : (int)$actor['user_id'], [
                    'message'=>'双方已接受退款方案，订单已停止履约；涉及资金分配或可退金额变化，需平台核账后执行退款','notify_recipients'=>['user','worker','admin'],
                ]);
                return ['request_id'=>$requestNo,'agreement_accepted'=>true,'execution_requires_platform'=>true];
            }
            $db->execute("UPDATE order_cancel_requests SET status='approved',reason_code='bilateral_refund_accepted',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$request['id']]);
            return $this->approveRefund($db, $order, (int)$request['id'], $requestNo, $payments, $amount, '双方接受退款方案：'.$request['reason'], (int)$order['user_id'], true);
        });
    }

    private function approveRefund(Database $db, array $order, int $requestId, string $requestPublicId, array $payments, int $refundAmount, string $reason, int $userId, bool $agreed = false): array
    {
            $refundIds = [];
            $firstRefundId = null;
            foreach ($payments as $payment) {
                $amount = (int)$payment['available_cents'];
                if ($amount < 1) continue;
                $refundId = $db->insert(
                    "INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,created_at,updated_at)
                     VALUES(:public_no,:payment_id,:order_id,:request_id,:amount,:reason,'pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'public_no'=>\business_no('RF'),'payment_id'=>$payment['id'],'order_id'=>$order['id'],'request_id'=>$requestId,
                        'amount'=>$amount,'reason'=>mb_substr('自动取消退款：'.$reason.'；原支付：'.(string)$payment['business_type'], 0, 1000),
                    ]
                );
                if ($firstRefundId === null) $firstRefundId = $refundId;
                $refundIds[] = $refundId;
            }
            if (!$refundIds) {
                throw new OrderOperationException('当前没有可自动原路退款的实际支付，请转人工核对', 'AUTO_REFUND_PAYMENT_MISSING', 409);
            }

            // 2026-10-08：取消成立时保存失效前的报价/成交事实，与取消退款同事务提交。
            (new DatabaseOrderCancellationRiskRepository($db))->captureCancellation($order);

            $db->execute(
                "UPDATE orders SET lifecycle_status='cancel_approved_pending_refund',refund_status='pending_review',publish_status='hidden',quote_status='cancelled',assignment_status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$order['id']]
            );
            $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id'=>$order['id']]);
            $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id'=>$order['id']]);
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
            $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
            $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id'=>$order['id']]);
            $db->insert(
                "INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,reason,refund_amount_cents,run_empty_fee_cents,refund_id,decided_by,decided_at,created_at)
                 VALUES(:request_id,:order_id,'approved','user',:reason,:refund,0,:refund_id,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'request_id'=>$requestId,'order_id'=>$order['id'],
                    'reason'=>mb_substr('系统执行已获准退款；原因：'.$reason, 0, 1000),
                    'refund'=>$refundAmount,'refund_id'=>$firstRefundId,
                ]
            );
            $this->event($db, (int)$order['id'], 'cancel_approved', 'system', 0, [
                'refund_amount_cents'=>$refundAmount,'refund_count'=>count($refundIds),'auto_approved'=>1,
                'requested_by_user_id'=>$userId,
                'message'=>'系统已取消订单并登记 '.count($refundIds).' 笔原路退款，无需管理员审核',
                'notify_recipients'=>$agreed ? ['user','worker'] : ['user'],
            ]);
            return ['request_id'=>$requestPublicId,'auto_approved'=>true,'refund_cents'=>$refundAmount,'refund_count'=>count($refundIds)];
    }

    public function review(
        array $operator,
        string $orderNo,
        string $decision,
        string $workerArrived,
        string $responsibility,
        string $reason,
        int $refundCents
    ): array {
        $operation = function () use ($operator, $orderNo, $decision, $workerArrived, $responsibility, $reason, $refundCents): array {
            return $this->reviewUnderWriteGuard($operator, $orderNo, $decision, $workerArrived, $responsibility, $reason, $refundCents);
        };
        if (($operator['channel'] ?? '') === 'web_admin') {
            return (new DatabaseDemoOrderWriteGuard($this->db))->run($orderNo, $operation);
        }
        return $operation();
    }

    private function reviewUnderWriteGuard(
        array $operator,
        string $orderNo,
        string $decision,
        string $workerArrived,
        string $responsibility,
        string $reason,
        int $refundCents
    ): array {
        $channel = (string)($operator['channel'] ?? '');
        if ($channel === 'mini_admin') {
            $operatorId = max(0, (int)($operator['user_id'] ?? 0));
            $webAdminId = 0;
        } elseif ($channel === 'web_admin') {
            $operatorId = max(0, (int)($operator['admin_user_id'] ?? 0));
            $webAdminId = $operatorId;
        } else {
            $operatorId = 0;
            $webAdminId = 0;
        }
        if ($operatorId < 1) {
            throw new OrderOperationException('管理员操作来源无效', 'ADMIN_REQUIRED', 403);
        }
        $decidedBy = $webAdminId > 0 ? $webAdminId : null;

        return $this->db->transaction(function (Database $db) use ($orderNo,$decision,$workerArrived,$responsibility,$reason,$refundCents,$channel,$operatorId,$decidedBy): array {
            $order = $db->fetch(
                'SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE',
                ['public_no'=>$orderNo]
            );
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);

            $request = $db->fetch(
                "SELECT * FROM order_cancel_requests
                 WHERE order_id=:order_id AND status='pending'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id']]
            );
            if (!$request) {
                throw new OrderOperationException('当前没有待审核退款申请', 'REFUND_REVIEW_CHANGED', 409);
            }
            if (in_array((string)$request['reason_code'], ['bilateral_refund_pending','bilateral_refund_rejected'], true)) {
                throw new OrderOperationException('请先由订单双方申请平台介入', 'NEGOTIATION_NOT_ESCALATED', 409);
            }
            if ((string)$request['reason_code'] === 'bilateral_refund_execution_required') {
                throw new OrderOperationException(
                    '双方已经接受退款方案；当前涉及资金核账或历史入账，请到 Web 后台按真实资金记录执行，不能在小程序重新通过或拒绝',
                    'REFUND_EXECUTION_WEB_REQUIRED',
                    409
                );
            }

            $needsArrival = !empty($order['assigned_worker_id'])
                || in_array((string)($order['assignment_status'] ?? ''), ['offered','accepted','assigned'], true)
                || in_array((string)($order['fulfillment_status'] ?? ''), ['scheduled','in_progress'], true);
            if (!$needsArrival) $workerArrived = 'no';
            if ($needsArrival && !in_array($workerArrived, ['yes','no'], true)) {
                throw new OrderOperationException('请确认师傅是否已经上门', 'WORKER_ARRIVAL_REQUIRED', 422);
            }
            $responsibilityLabels = [
                'worker'=>'师傅','user'=>'用户','platform'=>'平台','shared'=>'双方','unclear'=>'待定',
            ];
            $decisionReason = ($workerArrived === 'yes' ? '师傅已上门' : '师傅未上门')
                .'；责任：'.$responsibilityLabels[$responsibility].'；'.$reason;

            if ($decision === 'reject') {
                $changed = $db->execute(
                    "UPDATE order_cancel_requests
                     SET status='rejected',closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='pending'",
                    ['id'=>$request['id']]
                );
                if ($changed !== 1) {
                    throw new OrderOperationException('退款申请状态已变化，请刷新', 'REFUND_REVIEW_CHANGED', 409);
                }
                $hasMeasurementLink = (bool)$db->fetchValue(
                    "SELECT 1 FROM order_relations
                     WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",
                    ['id'=>$order['id']]
                );
                $snapshot = $this->decodeSnapshot((string)($request['order_state_snapshot_json'] ?? ''));
                $restorePublish = (string)($snapshot['publish_status'] ?? 'hidden');
                if (!in_array($restorePublish, ['published','hidden'], true)) $restorePublish = 'hidden';
                if ($hasMeasurementLink) $restorePublish = 'hidden';
                $restoreRefund = (string)($snapshot['refund_status'] ?? 'none');
                if (!in_array($restoreRefund, ['','none','failed'], true)) $restoreRefund = 'none';
                if ($restoreRefund === '') $restoreRefund = 'none';
                $db->execute(
                    "UPDATE orders
                     SET refund_status=:refund_status,publish_status=:publish_status,
                         updated_at=UTC_TIMESTAMP(),version=version+1
                     WHERE id=:id",
                    [
                        'refund_status'=>$restoreRefund,
                        'publish_status'=>$restorePublish,
                        'id'=>$order['id'],
                    ]
                );
                $db->insert(
                    "INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,reason,refund_amount_cents,run_empty_fee_cents,refund_id,decided_by,decided_at,created_at)
                     VALUES(:request_id,:order_id,'rejected',:responsibility,:reason,0,0,NULL,:decided_by,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'request_id'=>$request['id'],'order_id'=>$order['id'],
                        'responsibility'=>$responsibility,'reason'=>$decisionReason,'decided_by'=>$decidedBy,
                    ]
                );
                $this->event($db, (int)$order['id'], 'cancel_rejected', $channel, $operatorId, [
                    'message'=>'退款申请未通过：'.$decisionReason,
                    'restored_publish_status'=>$restorePublish,
                    'operator_channel'=>$channel,
                    'operator_user_id'=>$channel === 'mini_admin' ? $operatorId : null,
                    'operator_admin_id'=>$channel === 'web_admin' ? $operatorId : null,
                    'notify_recipients'=>['user'],
                ]);
                return ['approved'=>false,'refund_cents'=>0,'refund_ids'=>[]];
            }

            if ($workerArrived === 'yes' && $responsibility !== 'worker') {
                throw new OrderOperationException(
                    '师傅已上门且不是师傅责任时，请拒绝退款并记录说明',
                    'REFUND_ARRIVAL_RESPONSIBILITY_CONFLICT',
                    422
                );
            }

            $payments = $this->refundablePayments($db, $order);
            $available = array_sum(array_map(static function (array $row): int {
                return (int)$row['available_cents'];
            }, $payments));
            $requested = max(0, (int)$request['requested_refund_cents']);
            $maxRefund = $requested > 0 ? min($available, $requested) : $available;
            if ($maxRefund > 0 && $refundCents < 1) {
                throw new OrderOperationException('请填写退款金额', 'REFUND_AMOUNT_REQUIRED', 422);
            }
            if ($refundCents > $maxRefund) {
                throw new OrderOperationException(
                    '退款金额超过当前可退线上余额 '.number_format($maxRefund/100, 2).' 元',
                    'REFUND_AMOUNT_EXCEEDS_AVAILABLE',
                    422,
                    ['max_refund_cents'=>$maxRefund]
                );
            }

            $remaining = $refundCents;
            $refundIds = [];
            $firstRefundId = null;
            foreach ($payments as $payment) {
                if ($remaining <= 0) break;
                $part = min($remaining, (int)$payment['available_cents']);
                if ($part < 1) continue;
                $refundId = $db->insert(
                    "INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,created_at,updated_at)
                     VALUES(:public_no,:payment_id,:order_id,:request_id,:amount,:reason,'pending_review',:requested_by_admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    [
                        'public_no'=>\business_no('RF'),
                        'payment_id'=>$payment['id'],
                        'order_id'=>$order['id'],
                        'request_id'=>$request['id'],
                        'amount'=>$part,
                        'reason'=>$decisionReason.'；原支付：'.(string)$payment['business_type'],
                        'requested_by_admin_id'=>$decidedBy,
                    ]
                );
                if ($firstRefundId === null) $firstRefundId = $refundId;
                $refundIds[] = $refundId;
                $remaining -= $part;
            }
            if ($remaining > 0) {
                throw new OrderOperationException(
                    '退款拆分失败，当前原支付可退余额不足，请刷新后重试',
                    'REFUND_SPLIT_BALANCE_CHANGED',
                    409
                );
            }

            // 先抢占 pending 取消申请，再推进订单；网络重试或并发请求只能有一个事务成功。
            $requestChanged = $db->execute(
                "UPDATE order_cancel_requests
                 SET status=:status,requested_refund_cents=:refund,
                     closed_at=IF(:close_now=1,UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='pending'",
                [
                    'status'=>$refundCents > 0 ? 'processing' : 'approved',
                    'refund'=>$refundCents,
                    'close_now'=>$refundCents > 0 ? 0 : 1,
                    'id'=>$request['id'],
                ]
            );
            if ($requestChanged !== 1) {
                throw new OrderOperationException('退款申请状态已变化，请刷新', 'REFUND_REVIEW_CHANGED', 409);
            }

            $lifecycle = $refundCents > 0 ? 'cancel_approved_pending_refund' : 'cancelled';
            (new DatabaseOrderCancellationRiskRepository($db))->captureCancellation($order, $responsibility);
            $refundStatus = $refundCents > 0 ? 'pending_review' : 'none';
            $db->execute(
                "UPDATE orders
                 SET lifecycle_status=:lifecycle,refund_status=:refund_status,publish_status='hidden',
                     quote_status='cancelled',assignment_status='cancelled',cancelled_at=UTC_TIMESTAMP(),
                     closed_at=IF(:close_now=1,COALESCE(closed_at,UTC_TIMESTAMP()),closed_at),
                     updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id",
                [
                    'lifecycle'=>$lifecycle,'refund_status'=>$refundStatus,
                    'close_now'=>$refundCents > 0 ? 0 : 1,'id'=>$order['id'],
                ]
            );
            $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id'=>$order['id']]);
            $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id'=>$order['id']]);
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
            $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
            $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id'=>$order['id']]);
            $db->insert(
                "INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,reason,refund_amount_cents,run_empty_fee_cents,refund_id,decided_by,decided_at,created_at)
                 VALUES(:request_id,:order_id,'approved',:responsibility,:reason,:refund,0,:refund_id,:decided_by,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'request_id'=>$request['id'],'order_id'=>$order['id'],'responsibility'=>$responsibility,
                    'reason'=>$decisionReason,'refund'=>$refundCents,'refund_id'=>$firstRefundId,'decided_by'=>$decidedBy,
                ]
            );
            $this->event($db, (int)$order['id'], 'cancel_approved', $channel, $operatorId, [
                'refund_amount_cents'=>$refundCents,
                'refund_count'=>count($refundIds),
                'message'=>$refundCents > 0
                    ? '退款审核通过，已按原支付单拆分为 '.count($refundIds).' 笔微信退款'
                    : '取消申请通过，无线上款项需退',
                'operator_channel'=>$channel,
                'operator_user_id'=>$channel === 'mini_admin' ? $operatorId : null,
                'operator_admin_id'=>$channel === 'web_admin' ? $operatorId : null,
                'notify_recipients'=>['user'],
            ]);
            return ['approved'=>true,'refund_cents'=>$refundCents,'refund_ids'=>$refundIds];
        });
    }

    /** @return array<int,array<string,mixed>> */
    private function refundablePayments(Database $db, array $order): array
    {
        $measurement = (string)($order['workflow_profile'] ?? 'construction') === 'measurement';
        $types = $measurement ? ['order_booking','booking_fee','measurement_balance','measurement_change'] : ['order_booking','booking_fee'];
        $marks = [];
        $params = ['order_id'=>$order['id']];
        foreach ($types as $index=>$type) {
            $key = 'type_'.$index;
            $marks[] = ':'.$key;
            $params[$key] = $type;
        }
        $rows = $db->fetchAll(
            "SELECT * FROM payment_orders WHERE order_id=:order_id AND business_type IN (".implode(',', $marks).") AND status IN ('paid','refunded')
             ORDER BY CASE business_type WHEN 'measurement_change' THEN 1 WHEN 'measurement_balance' THEN 2 WHEN 'booking_fee' THEN 3 WHEN 'order_booking' THEN 4 ELSE 9 END,id DESC FOR UPDATE",
            $params
        );
        $runnerRemaining = (int)$db->fetchValue(
            "SELECT COALESCE(SUM(l.amount_cents),0) FROM worker_balance_ledger l
             INNER JOIN disputes d ON d.id=l.business_id
             WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective' AND d.order_id=:order_id",
            ['order_id'=>$order['id']]
        );
        $result = [];
        foreach ($rows as $row) {
            $refundAllocated = (int)$db->fetchValue(
                "SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE payment_order_id=:payment_id AND status NOT IN ('failed','closed','cancelled','rejected')",
                ['payment_id'=>$row['id']]
            );
            $cap = max(0, (int)$row['amount_cents']);
            if (in_array((string)$row['business_type'], ['order_booking','booking_fee'], true)) {
                if ((int)($order['booking_fee_cents'] ?? 0) > 0) $cap = min($cap, (int)$order['booking_fee_cents']);
                $runnerApplied = min($cap, max(0, $runnerRemaining));
                $runnerRemaining = max(0, $runnerRemaining - $runnerApplied);
            } else {
                $runnerApplied = 0;
            }
            $available = max(0, $cap - $refundAllocated - $runnerApplied);
            if ($available < 1) continue;
            $row['available_cents'] = $available;
            $result[] = $row;
        }
        return $result;
    }

    private function canAutoApproveBeforeService(Database $db, array $order): bool
    {
        if ((string)($order['order_source_type'] ?? 'consumer') !== 'consumer') return false;
        if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) return false;
        if (!empty($order['selected_quote_id']) || !empty($order['assigned_worker_id']) || !empty($order['current_assignment_id'])) return false;
        if (!empty($order['contact_revealed_at']) || !empty($order['completion_submitted_at']) || !empty($order['accepted_at'])) return false;
        if (in_array((string)($order['fulfillment_status'] ?? ''), ['scheduled','in_progress','submitted','accepted'], true)) return false;

        $blocked = (int)$db->fetchValue(
            "SELECT (
                EXISTS(SELECT 1 FROM assignments WHERE order_id=:a LIMIT 1)
                OR EXISTS(SELECT 1 FROM quote_selections WHERE order_id=:q LIMIT 1)
                OR EXISTS(SELECT 1 FROM appointment_records WHERE order_id=:ap LIMIT 1)
                OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:c LIMIT 1)
                OR EXISTS(SELECT 1 FROM disputes WHERE order_id=:d LIMIT 1)
                OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:s LIMIT 1)
                OR EXISTS(SELECT 1 FROM run_empty_fee_claims WHERE order_id=:r LIMIT 1)
            ) AS blocked",
            ['a'=>$order['id'],'q'=>$order['id'],'ap'=>$order['id'],'c'=>$order['id'],'d'=>$order['id'],'s'=>$order['id'],'r'=>$order['id']]
        );
        return $blocked === 0;
    }

    /** @return array<string,mixed> */
    private function decodeSnapshot(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function snapshot(array $order): string
    {
        $json = json_encode([
            'lifecycle_status'=>$order['lifecycle_status'] ?? null,
            'fulfillment_status'=>$order['fulfillment_status'] ?? null,
            'payment_status'=>$order['payment_status'] ?? null,
            'workflow_profile'=>$order['workflow_profile'] ?? null,
            'order_source_type'=>$order['order_source_type'] ?? null,
            'refund_status'=>$order['refund_status'] ?? null,
            'publish_status'=>$order['publish_status'] ?? null,
            'quote_status'=>$order['quote_status'] ?? null,
            'assignment_status'=>$order['assignment_status'] ?? null,
            'selected_quote_id'=>$order['selected_quote_id'] ?? null,
            'assigned_worker_id'=>$order['assigned_worker_id'] ?? null,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,
                'actor_id'=>$actorId > 0 ? $actorId : null,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue($db, $orderId, $eventKey, $type, $payload);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderDisputeRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\NegotiationPolicy;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseOrderDisputeRepository implements OrderDisputeRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function startNegotiation(array $actor, string $orderNo, string $description, array $evidenceIds): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$description,$evidenceIds): array {
            $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE', ['public_no'=>$orderNo]);
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能为自己的订单提交异议', 'FORBIDDEN', 403);
            }
            if ((string)$order['fulfillment_status'] !== 'submitted' || (string)$order['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('只有待确认的服务结果可以提交异议', 'DISPUTE_NOT_ALLOWED', 409);
            }
            if (!in_array((string)($order['dispute_status'] ?? 'none'), ['','none','resolved'], true)
                || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:id AND status IN ('negotiating','open','pending','processing') LIMIT 1 FOR UPDATE", ['id'=>$order['id']])) {
                throw new OrderOperationException('本单已有异议正在处理中', 'DISPUTE_ALREADY_ACTIVE', 409);
            }

            $params = ['order_id'=>$order['id']];
            $marks = [];
            foreach (array_values($evidenceIds) as $index=>$publicId) {
                $key = 'media_'.$index;
                $marks[] = ':'.$key;
                $params[$key] = $publicId;
            }
            $mediaRows = $db->fetchAll(
                "SELECT m.id,m.public_id FROM media_files m
                 JOIN media_links l ON l.media_file_id=m.id AND l.entity_type='order' AND l.entity_id=:order_id AND l.purpose='dispute_evidence'
                 WHERE m.public_id IN (".implode(',', $marks).") AND m.status='active'
                 GROUP BY m.id,m.public_id",
                $params
            );
            if (count($mediaRows) !== count($evidenceIds)) {
                throw new OrderOperationException('异议证据图片无效，请重新上传后提交', 'DISPUTE_EVIDENCE_INVALID', 422);
            }

            $measurement = (string)($order['workflow_profile'] ?? 'construction') === 'measurement';
            $disputeNo = 'DSP'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(4)));
            $disputeId = $db->insert(
                "INSERT INTO disputes(public_no,order_id,raised_by_type,raised_by_id,dispute_type,title,description,status,responsibility,refund_amount_cents,run_empty_fee_cents,opened_at,created_at,updated_at)
                 VALUES(:public_no,:order_id,'user',:user_id,:dispute_type,:title,:description,'negotiating','undetermined',0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'public_no'=>$disputeNo,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],
                    'dispute_type'=>$measurement ? 'measurement_result' : 'completion_quality',
                    'title'=>$measurement ? '测量成果异议协商中' : '完工异议协商中',
                    'description'=>$description,
                ]
            );
            foreach ($mediaRows as $row) {
                $db->insert(
                    "INSERT INTO dispute_evidence(dispute_id,submitted_by_type,submitted_by_id,evidence_type,description,media_file_id,occurred_at,created_at)
                     VALUES(:dispute_id,'user',:user_id,'photo',NULL,:media_id,NULL,UTC_TIMESTAMP())",
                    ['dispute_id'=>$disputeId,'user_id'=>$actor['user_id'],'media_id'=>$row['id']]
                );
            }
            $db->execute(
                "UPDATE orders SET dispute_status='negotiating',publish_status='hidden',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$order['id']]
            );
            $eventKey = $this->event($db, (int)$order['id'], 'dispute_negotiation_started', 'user', (int)$actor['user_id'], [
                'dispute_no'=>$disputeNo,
                'evidence_count'=>count($mediaRows),
                'message'=>$measurement
                    ? '用户提出测量成果异议，自动确认已暂停，请双方先自行沟通处理'
                    : '用户提出完工异议，自动验收已暂停，请双方先自行沟通处理',
            ], false);
            $this->notifyWorkerNegotiation($db, $order, $eventKey, $measurement);
            return ['id'=>$disputeNo,'status'=>'negotiating','admin_escalated'=>false];
        });
    }

    public function escalate(array $actor, string $disputeNo, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$disputeNo,$note): array {
            $seed = $db->fetch('SELECT order_id FROM disputes WHERE BINARY public_no=BINARY :no', ['no'=>$disputeNo]);
            if (!$seed) throw new OrderOperationException('异议记录不存在', 'DISPUTE_NOT_FOUND', 404);
            $db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE', ['id'=>$seed['order_id']]);
            $row = $db->fetch(
                "SELECT d.*,o.public_no order_no,o.user_id,o.id order_id,o.workflow_profile
                 FROM disputes d JOIN orders o ON o.id=d.order_id
                 WHERE BINARY d.public_no=BINARY :public_no FOR UPDATE",
                ['public_no'=>$disputeNo]
            );
            if (!$row) throw new OrderOperationException('异议记录不存在', 'DISPUTE_NOT_FOUND', 404);
            if ((int)$row['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能处理自己的订单异议', 'FORBIDDEN', 403);
            }
            if ((string)$row['status'] !== 'negotiating') {
                throw new OrderOperationException('该异议当前不能重复申请平台介入', 'DISPUTE_ESCALATE_NOT_ALLOWED', 409);
            }
            // 2026-10-08：直接 API 与页面使用同一升级条件，不能提交后立即绕过协商。
            $eligibility = NegotiationPolicy::evaluate(NegotiationPolicy::disputeStatus($row['resolution'] ?? null), $row['opened_at'] ?? null);
            if (empty($eligibility['eligible'])) {
                throw new OrderOperationException('请先协商；师傅拒绝或满48小时未解决后才可申请平台介入', 'NEGOTIATION_WAIT_REQUIRED', 409, $eligibility);
            }
            if ($note !== '') {
                $db->insert(
                    "INSERT INTO dispute_evidence(dispute_id,submitted_by_type,submitted_by_id,evidence_type,description,media_file_id,occurred_at,created_at)
                     VALUES(:dispute_id,'user',:user_id,'note',:description,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                    ['dispute_id'=>$row['id'],'user_id'=>$actor['user_id'],'description'=>$note]
                );
            }
            $measurement = (string)($row['workflow_profile'] ?? 'construction') === 'measurement';
            $db->execute(
                "UPDATE disputes SET status='open',title=:title,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='negotiating'",
                ['title'=>$measurement ? '用户申请平台介入测量成果争议' : '用户申请平台介入完工争议','id'=>$row['id']]
            );
            $db->execute(
                "UPDATE orders SET dispute_status='open',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$row['order_id']]
            );
            // 平台待办只通过 queueAdminTask 产生一次；订单事件仅通知双方，避免管理员收到两条重复提醒。
            $eventKey = $this->event($db, (int)$row['order_id'], 'dispute_opened', 'user', (int)$actor['user_id'], [
                'dispute_no'=>$row['public_no'],
                'dispute_type'=>$row['dispute_type'],
                'message'=>'双方自行协商未解决，用户已申请平台介入',
                'reason'=>$note,
                'notify_recipients'=>['user','worker'],
            ], true);
            OrderNotificationService::queueAdminTask(
                $db,
                'dispute:'.$row['id'],
                'completion_dispute_opened',
                $measurement ? '用户申请平台介入测量成果争议' : '用户申请平台介入完工争议',
                [
                    '订单号'=>(string)$row['order_no'],
                    '争议说明'=>(string)$row['description'],
                    '补充说明'=>$note,
                ]
            );
            return ['id'=>(string)$row['public_no'],'status'=>'open','admin_escalated'=>true,'event_key'=>$eventKey];
        });
    }

    public function resolveByAgreement(array $actor, string $disputeNo, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$disputeNo,$note): array {
            $seed = $db->fetch('SELECT order_id FROM disputes WHERE BINARY public_no=BINARY :no', ['no'=>$disputeNo]);
            if (!$seed) throw new OrderOperationException('异议记录不存在', 'DISPUTE_NOT_FOUND', 404);
            $db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE', ['id'=>$seed['order_id']]);
            $row = $db->fetch(
                "SELECT d.*,o.user_id,o.id order_id FROM disputes d JOIN orders o ON o.id=d.order_id
                 WHERE BINARY d.public_no=BINARY :public_no FOR UPDATE",
                ['public_no'=>$disputeNo]
            );
            if (!$row) throw new OrderOperationException('异议记录不存在', 'DISPUTE_NOT_FOUND', 404);
            if ((int)$row['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能处理自己的订单异议', 'FORBIDDEN', 403);
            }
            if ((string)$row['status'] !== 'negotiating') {
                throw new OrderOperationException('平台已经介入或异议已经结束，不能自行关闭', 'DISPUTE_SELF_RESOLVE_NOT_ALLOWED', 409);
            }
            $resolution = '双方自行协商解决'.($note !== '' ? '：'.$note : '');
            $db->execute(
                "UPDATE disputes SET status='resolved',responsibility='mutual',resolution=:resolution,resolved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='negotiating'",
                ['resolution'=>$resolution,'id'=>$row['id']]
            );
            $db->execute(
                "UPDATE orders SET dispute_status='resolved',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$row['order_id']]
            );
            $this->event($db, (int)$row['order_id'], 'dispute_resolved', 'user', (int)$actor['user_id'], [
                'dispute_no'=>$row['public_no'],
                'resolution'=>$resolution,
                'self_resolved'=>1,
                'notify_recipients'=>['worker'],
            ], true);
            return ['id'=>(string)$row['public_no'],'status'=>'resolved','admin_escalated'=>false];
        });
    }

    public function resolveByAdmin(
        array $operator,
        string $orderNo,
        string $resolution,
        string $responsibility,
        int $refundCents = 0,
        int $runEmptyCents = 0
    ): array {
        $operation = function () use ($operator, $orderNo, $resolution, $responsibility, $refundCents, $runEmptyCents): array {
            return $this->resolveByAdminUnderWriteGuard($operator, $orderNo, $resolution, $responsibility, $refundCents, $runEmptyCents);
        };
        if (($operator['channel'] ?? '') === 'web_admin') {
            return (new DatabaseDemoOrderWriteGuard($this->db))->run($orderNo, $operation);
        }
        return $operation();
    }

    private function resolveByAdminUnderWriteGuard(
        array $operator,
        string $orderNo,
        string $resolution,
        string $responsibility,
        int $refundCents = 0,
        int $runEmptyCents = 0
    ): array {
        $channel = (string)($operator['channel'] ?? '');
        if ($channel === 'mini_admin') {
            $operatorId = max(0, (int)($operator['user_id'] ?? 0));
            $handledBy = null;
        } elseif ($channel === 'web_admin') {
            $operatorId = max(0, (int)($operator['admin_user_id'] ?? 0));
            $handledBy = $operatorId;
        } else {
            throw new OrderOperationException('管理员操作来源无效', 'ADMIN_REQUIRED', 403);
        }
        if ($operatorId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        $refundCents = max(0, $refundCents);
        $runEmptyCents = max(0, $runEmptyCents);
        if ($channel === 'mini_admin' && ($refundCents > 0 || $runEmptyCents > 0)) {
            throw new OrderOperationException('涉及退款或跑空费金额，请到网页后台处理', 'DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED', 409);
        }

        return $this->db->transaction(function (Database $db) use (
            $orderNo,$resolution,$responsibility,$refundCents,$runEmptyCents,$channel,$operatorId,$handledBy
        ): array {
            $order = $db->fetch(
                'SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE',
                ['public_no'=>$orderNo]
            );
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);

            $dispute = $db->fetch(
                "SELECT * FROM disputes
                 WHERE order_id=:order_id AND status IN ('open','processing')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id']]
            );
            if (!$dispute) {
                throw new OrderOperationException('当前没有待处理争议', 'DISPUTE_ADMIN_NOT_FOUND', 409);
            }
            if ($channel === 'mini_admin' && ((int)($dispute['refund_amount_cents'] ?? 0) > 0 || (int)($dispute['run_empty_fee_cents'] ?? 0) > 0)) {
                throw new OrderOperationException(
                    '该争议涉及退款或跑空费金额，请到网页后台处理',
                    'DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED',
                    409
                );
            }

            $financial = $refundCents > 0 || $runEmptyCents > 0;
            $effectiveRefundCents = 0;
            $autoRefundCents = 0;
            $allocations = [
                'refund_cents'=>0,
                'outstanding_refund_cents'=>0,
                'succeeded_refund_cents'=>0,
                'runner_cents'=>0,
            ];
            $payment = null;
            $availableBookingFee = 0;

            if ($financial) {
                if ($channel !== 'web_admin' || $handledBy === null) {
                    throw new OrderOperationException('资金争议只能由网页后台裁定', 'DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED', 409);
                }
                if ($this->isTerminalLifecycle((string)($order['lifecycle_status'] ?? ''))) {
                    throw new OrderOperationException('已结束订单不能再次分配预约费，请核对已有退款和结算记录', 'DISPUTE_FINANCE_ORDER_CLOSED', 409);
                }
                $payment = $db->fetch(
                    "SELECT id,amount_cents FROM payment_orders
                     WHERE order_id=:order_id AND business_type IN ('order_booking','booking_fee') AND status='paid'
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['order_id'=>$order['id']]
                );
                $availableBookingFee = $payment
                    ? ((int)($order['booking_fee_cents'] ?? 0) > 0
                        ? min((int)$payment['amount_cents'], (int)$order['booking_fee_cents'])
                        : (int)$payment['amount_cents'])
                    : 0;
                if ($refundCents + $runEmptyCents > $availableBookingFee) {
                    throw new OrderOperationException('退款与跑空费合计不能超过本单实际支付的预约费', 'DISPUTE_FINANCE_EXCEEDS_BOOKING', 409);
                }
                $allocations = $payment
                    ? $this->bookingFeeAllocations($db, (int)$payment['id'], (int)$order['id'])
                    : $allocations;
                if ($allocations['refund_cents'] + $allocations['runner_cents'] + $refundCents + $runEmptyCents > $availableBookingFee) {
                    throw new OrderOperationException('该支付单已有退款或费用分配，剩余预约费不足', 'DISPUTE_FINANCE_ALREADY_ALLOCATED', 409);
                }

                // 资金裁定会终止本次履约；未判给师傅且尚未被占用的预约费必须全部回到退款，避免余额悬空。
                $allocatedAfterDecision = $allocations['refund_cents'] + $allocations['runner_cents'] + $refundCents + $runEmptyCents;
                $autoRefundCents = max(0, $availableBookingFee - $allocatedAfterDecision);
                $effectiveRefundCents = $refundCents + $autoRefundCents;

                if ($effectiveRefundCents > 0) {
                    $db->insert(
                        "INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,created_at,updated_at)
                         VALUES(:public_no,:payment_id,:order_id,NULL,:amount,:reason,'pending_review',:admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                        [
                            'public_no'=>\business_no('RF'),
                            'payment_id'=>$payment['id'],
                            'order_id'=>$order['id'],
                            'amount'=>$effectiveRefundCents,
                            'reason'=>mb_substr('争议裁定：'.$resolution, 0, 1000),
                            'admin_id'=>$handledBy,
                        ]
                    );
                }

                if ($runEmptyCents > 0) {
                    $workerId = !empty($order['assigned_worker_id'])
                        ? (int)$order['assigned_worker_id']
                        : (int)$db->fetchValue(
                            "SELECT worker_id FROM assignments
                             WHERE order_id=:order_id
                             ORDER BY CASE status WHEN 'accepted' THEN 1 WHEN 'completed' THEN 2 WHEN 'cancelled' THEN 3 WHEN 'offered' THEN 4 ELSE 5 END,id DESC
                             LIMIT 1",
                            ['order_id'=>$order['id']]
                        );
                    if ($workerId < 1) {
                        throw new OrderOperationException('订单没有可收取跑空费的师傅，请先核对指派记录', 'DISPUTE_RUN_EMPTY_WORKER_MISSING', 409);
                    }
                    $db->execute(
                        "INSERT INTO worker_balance_ledger(
                            worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                            business_type,business_id,idempotency_key,description,status,created_by_admin_id,created_at
                         ) VALUES(
                            :worker_id,'run_empty_fee','credit',:amount,:available_delta,0,
                            'dispute',:dispute_id,:idempotency_key,:description,'effective',:admin_id,UTC_TIMESTAMP()
                         )",
                        [
                            'worker_id'=>$workerId,
                            'amount'=>$runEmptyCents,
                            'available_delta'=>$runEmptyCents,
                            'dispute_id'=>$dispute['id'],
                            'idempotency_key'=>'dispute:'.$dispute['id'].':run_empty_fee',
                            'description'=>mb_substr('争议裁定跑空费：'.$resolution, 0, 1000),
                            'admin_id'=>$handledBy,
                        ]
                    );
                }
            }

            $changed = $db->execute(
                "UPDATE disputes
                 SET status='resolved',resolution=:resolution,responsibility=:responsibility,
                     refund_amount_cents=:refund,run_empty_fee_cents=:runner,handled_by=:handled_by,
                     resolved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status IN ('open','processing')",
                [
                    'resolution'=>$resolution,
                    'responsibility'=>$responsibility,
                    'refund'=>$effectiveRefundCents,
                    'runner'=>$runEmptyCents,
                    'handled_by'=>$handledBy,
                    'id'=>$dispute['id'],
                ]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('争议状态已变化，请刷新后重试', 'DISPUTE_ADMIN_CHANGED', 409);
            }

            if ($financial) {
                // 取消风控必须在报价/指派失效前捕获原事实，并与争议裁定同一事务提交。
                (new DatabaseOrderCancellationRiskRepository($db))->captureCancellation($order, $responsibility, $runEmptyCents);
                $hasOutstandingRefund = $allocations['outstanding_refund_cents'] + $effectiveRefundCents > 0;
                $lifecycle = $hasOutstandingRefund ? 'cancel_approved_pending_refund' : 'cancelled';
                $db->execute(
                    "UPDATE orders
                     SET dispute_status='resolved',lifecycle_status=:lifecycle,publish_status='hidden',quote_status='cancelled',
                         assignment_status='cancelled',fulfillment_status='cancelled',
                         refund_status=IF(:has_refund=1,'pending_review',refund_status),
                         cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
                         closed_at=IF(:close_now=1,COALESCE(closed_at,UTC_TIMESTAMP()),closed_at),
                         updated_at=UTC_TIMESTAMP(),version=version+1
                     WHERE id=:id",
                    [
                        'lifecycle'=>$lifecycle,
                        'has_refund'=>$hasOutstandingRefund ? 1 : 0,
                        'close_now'=>$hasOutstandingRefund ? 0 : 1,
                        'id'=>$order['id'],
                    ]
                );
                $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id'=>$order['id']]);
                $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id'=>$order['id']]);
                $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
                $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE order_id=:id AND status='active'", ['id'=>$order['id']]);
                $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id'=>$order['id']]);
            } else {
                $db->execute(
                    "UPDATE orders SET dispute_status='resolved',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$order['id']]
                );
            }

            $payload = [
                'dispute_no'=>(string)$dispute['public_no'],
                'message'=>'平台争议处理完成：'.$resolution,
                'resolution'=>$resolution,
                'responsibility'=>$responsibility,
                'refund_cents'=>$effectiveRefundCents,
                'run_empty_fee_cents'=>$runEmptyCents,
                'auto_refund_cents'=>$autoRefundCents,
                'operator_channel'=>$channel,
                'notify_recipients'=>['user','worker'],
            ];
            if ($channel === 'mini_admin') $payload['operator_user_id'] = $operatorId;
            else $payload['operator_admin_id'] = $operatorId;
            $eventKey = $this->event($db, (int)$order['id'], 'dispute_resolved', $channel, $operatorId, $payload, true);

            return [
                'id'=>(string)$dispute['public_no'],
                'status'=>'resolved',
                'order_id'=>(int)$order['id'],
                'refund_cents'=>$effectiveRefundCents,
                'run_empty_fee_cents'=>$runEmptyCents,
                'auto_refund_cents'=>$autoRefundCents,
                'event_key'=>$eventKey,
            ];
        });
    }

    /** @return array{refund_cents:int,outstanding_refund_cents:int,succeeded_refund_cents:int,runner_cents:int} */
    private function bookingFeeAllocations(Database $db, int $paymentId, int $orderId): array
    {
        $refundRows = $db->fetchAll(
            "SELECT id,amount_cents,status FROM refunds
             WHERE payment_order_id=:payment_id
               AND status NOT IN ('failed','closed','cancelled','rejected')
             FOR UPDATE",
            ['payment_id'=>$paymentId]
        );
        $refundCents = 0;
        $outstandingRefundCents = 0;
        $succeededRefundCents = 0;
        foreach ($refundRows as $row) {
            $amount = max(0, (int)$row['amount_cents']);
            $refundCents += $amount;
            if ((string)$row['status'] === 'succeeded') $succeededRefundCents += $amount;
            else $outstandingRefundCents += $amount;
        }

        $runnerCents = 0;
        $paymentBusiness = (string)$db->fetchValue('SELECT business_type FROM payment_orders WHERE id=:id', ['id'=>$paymentId]);
        if ($orderId > 0 && in_array($paymentBusiness, ['order_booking','booking_fee'], true)) {
            $runnerRows = $db->fetchAll(
                "SELECT l.id,l.amount_cents
                 FROM worker_balance_ledger l
                 INNER JOIN disputes d ON d.id=l.business_id
                 WHERE l.business_type='dispute'
                   AND l.entry_type='run_empty_fee'
                   AND l.status='effective'
                   AND d.order_id=:order_id
                 FOR UPDATE",
                ['order_id'=>$orderId]
            );
            foreach ($runnerRows as $row) $runnerCents += max(0, (int)$row['amount_cents']);
        }
        return [
            'refund_cents'=>$refundCents,
            'outstanding_refund_cents'=>$outstandingRefundCents,
            'succeeded_refund_cents'=>$succeededRefundCents,
            'runner_cents'=>$runnerCents,
        ];
    }

    private function isTerminalLifecycle(string $status): bool
    {
        return strpos($status, 'cancel') === 0 || in_array($status, ['completed','closed','expired'], true);
    }

    private function notifyWorkerNegotiation(Database $db, array $order, string $eventKey, bool $measurement): void
    {
        $workerId = (int)($order['assigned_worker_id'] ?? 0);
        if ($workerId < 1) return;
        $data = json_encode(['order_id'=>(int)$order['id'],'event_key'=>$eventKey], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($db->fetchValue(
            "SELECT id FROM notifications WHERE recipient_type='worker' AND recipient_id=:worker_id AND channel='in_app' AND template_key='dispute_negotiation_started' AND BINARY data_json=BINARY :data LIMIT 1",
            ['worker_id'=>$workerId,'data'=>$data]
        )) return;
        $db->insert(
            "INSERT INTO notifications(public_id,recipient_type,recipient_id,channel,template_key,title,content,data_json,status,scheduled_at,created_at)
             VALUES(:public_id,'worker',:worker_id,'in_app','dispute_negotiation_started',:title,:content,:data,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'public_id'=>\public_id(),
                'worker_id'=>$workerId,
                'title'=>$measurement ? '用户提出测量成果异议' : '用户提出完工异议',
                'content'=>'订单 '.(string)$order['public_no'].'：用户对'.($measurement ? '测量成果' : '完工结果').'有异议，请先主动联系协商处理；协商不成时用户可再申请平台介入。',
                'data'=>$data,
            ]
        );
    }

    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload, bool $notify): string
    {
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,'actor_id'=>$actorId,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        if ($notify) OrderNotificationService::queue($db, $orderId, $eventKey, $type, $payload);
        return $eventKey;
    }
}
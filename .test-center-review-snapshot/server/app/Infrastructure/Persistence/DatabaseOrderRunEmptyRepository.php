<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\OrderRunEmptyRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 跑空费协商持久化。
 *
 * 普通 consumer 施工单：师傅提出 -> 用户确认 -> 预约费内自动分配；用户拒绝才进入平台争议。
 * merchant：保留原“用户确认 -> 在线支付”流程。
 * measurement / platform：资金关系与履约流程独立，不自动从普通订单预约费规则抵扣，继续进入平台核对。
 */
final class DatabaseOrderRunEmptyRepository implements OrderRunEmptyRepository
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function submit(array $actor, array $command): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) throw new OrderOperationException('请先完成师傅入驻', 'WORKER_REQUIRED', 403);
        $order = $this->resolveOrder((string)$command['order_id']);
        $assignment = $this->db->fetch(
            "SELECT id,status,worker_id FROM assignments
             WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted'
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        );
        if (!$assignment || (int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
            throw new OrderOperationException('只有本单成交师傅可以提交跑空费', 'FORBIDDEN', 403);
        }
        if ((string)$order['lifecycle_status'] !== 'active' || (string)$order['payment_status'] !== 'paid') {
            throw new OrderOperationException('当前订单阶段不能提交跑空费', 'RUN_EMPTY_ORDER_NOT_READY', 409);
        }
        $amount = (int)$command['amount_cents'];
        $bookingFee = max(0, (int)($order['booking_fee_cents'] ?? 0));
        if ($bookingFee < 1 || $amount > $bookingFee) {
            throw new OrderOperationException('跑空费不能超过本单预约费', 'RUN_EMPTY_AMOUNT_EXCEEDS_BOOKING', 422);
        }

        $lat = (float)$command['lat'];
        $lng = (float)$command['lng'];
        if ($order['lat'] !== null && $order['lng'] !== null) {
            $distance = $this->distanceKm($lat, $lng, (float)$order['lat'], (float)$order['lng']);
            $maxDistance = max(0.1, min(20.0, (float)$this->settings->get('order.arrival_evidence_max_distance_km', 2)));
            if ($distance > $maxDistance) {
                throw new OrderOperationException(
                    '当前定位距离施工地址约 '.number_format($distance, 1).'km，超过取证允许范围，请到达现场后再提交',
                    'RUN_EMPTY_DISTANCE_TOO_FAR', 422
                );
            }
        }
        $evidenceBundle = $this->arrivalEvidence((int)$order['id'], $lat, $lng);
        $source = (string)($order['order_source_type'] ?? 'consumer');
        $workflow = (string)($order['workflow_profile'] ?? 'construction');
        $selfResolution = $source === 'consumer' && $workflow !== 'measurement';

        return $this->db->transaction(function (Database $db) use ($actor,$order,$workerId,$amount,$command,$evidenceBundle,$source,$workflow,$selfResolution,$lat,$lng): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (string)$locked['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'RUN_EMPTY_ORDER_CHANGED', 409);
            }
            $change = $db->fetch(
                "SELECT id,public_id FROM change_requests
                 WHERE order_id=:order_id AND worker_id=:worker_id AND status='rejected'
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$locked['id'],'worker_id'=>$workerId]
            );
            if (!$change) {
                throw new OrderOperationException('只有用户拒绝加价并协商未果后才能登记跑空费', 'RUN_EMPTY_REJECTED_CHANGE_REQUIRED', 409);
            }
            if ($db->fetchValue('SELECT id FROM run_empty_fee_claims WHERE change_request_id=:id LIMIT 1 FOR UPDATE', ['id'=>$change['id']])) {
                throw new OrderOperationException('本次拒绝加价已经提交过跑空费处理', 'RUN_EMPTY_ALREADY_SUBMITTED', 409);
            }

            $cancel = null;
            if ($source !== 'merchant') {
                $cancel = $db->fetch(
                    "SELECT * FROM order_cancel_requests
                     WHERE order_id=:id AND status IN ('pending','processing') AND closed_at IS NULL
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['id'=>$locked['id']]
                );
                if (!$cancel) {
                    throw new OrderOperationException('请先由用户申请退款取消，再提交跑空费协商', 'RUN_EMPTY_CANCEL_REQUEST_REQUIRED', 409);
                }
            }

            $status = ($source === 'merchant' || $selfResolution) ? 'pending_confirmation' : 'disputed';
            $mode = $source === 'merchant' ? 'online' : ($selfResolution ? 'booking_offset' : 'manual');
            $disputeId = null;
            if ($status === 'disputed') {
                $title = $workflow === 'measurement' ? '测量跑空费需要平台核对' : '平台承接单跑空费需要平台处理';
                $disputeId = $this->createDispute($db, $locked, $workerId, $amount, (string)$command['reason'], 'worker');
            }

            $publicId = \public_id();
            $db->insert(
                "INSERT INTO run_empty_fee_claims(
                    public_id,order_id,change_request_id,worker_id,order_source_type,collection_mode,
                    amount_cents,reason,evidence_media_ids_json,watermark_metadata_json,status,dispute_id,
                    submitted_at,created_at,updated_at
                 ) VALUES(
                    :public_id,:order_id,:change_id,:worker_id,:source,:mode,
                    :amount,:reason,:evidence,:watermark,:status,:dispute_id,
                    UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_id'=>$publicId,'order_id'=>$locked['id'],'change_id'=>$change['id'],'worker_id'=>$workerId,
                    'source'=>$source,'mode'=>$mode,'amount'=>$amount,'reason'=>$command['reason'],
                    'evidence'=>json_encode(array_values(array_column($evidenceBundle['rows'], 'id')), JSON_UNESCAPED_UNICODE),
                    'watermark'=>json_encode(['reported_lat'=>$lat,'reported_lng'=>$lng,'photos'=>$evidenceBundle['watermarks']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    'status'=>$status,'dispute_id'=>$disputeId,
                ]
            );

            if ($status === 'disputed') {
                $db->execute("UPDATE orders SET dispute_status='open',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['id'=>$locked['id']]);
                $this->event($db, (int)$locked['id'], 'run_empty_claim_disputed', 'worker', $workerId, [
                    'claim_id'=>$publicId,'amount_cents'=>$amount,'reason'=>$command['reason'],
                    'message'=>$workflow === 'measurement' ? '测量单跑空费需要平台核对' : '平台承接单跑空费需要平台处理',
                ]);
            } else {
                $this->event($db, (int)$locked['id'], 'run_empty_claim_submitted', 'worker', $workerId, [
                    'claim_id'=>$publicId,'amount_cents'=>$amount,'reason'=>$command['reason'],
                    'collection_mode'=>$mode,
                    'message'=>$source === 'merchant'
                        ? '师傅已提交跑空费，请确认后在线支付'
                        : '师傅已提交跑空费，请先确认；同意后直接从已付预约费中结算',
                    'notify_recipients'=>['user'],
                ]);
            }
            // 2026-10-08：原跑空费提交记为取消相关投诉，无需新增师傅操作。
            (new DatabaseOrderCancellationRiskRepository($db))->recordExistingSignal((int)$locked['id'], $workerId, 'complaint', (string)$command['reason']);
            return [
                'id'=>$publicId,'status'=>$status,'collection_mode'=>$mode,'order_source_type'=>$source,
                'workflow_profile'=>$workflow,'requires_platform'=>$status === 'disputed',
            ];
        });
    }

    public function decide(array $actor, string $claimPublicId, string $decision, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$claimPublicId,$decision,$note): array {
            $claim = $db->fetch(
                "SELECT c.*,o.public_no order_no,o.user_id,o.booking_fee_cents,o.lifecycle_status,o.refund_status,
                        o.order_source_type,o.workflow_profile,o.assigned_worker_id,o.current_assignment_id
                 FROM run_empty_fee_claims c JOIN orders o ON o.id=c.order_id
                 WHERE BINARY c.public_id=BINARY :public_id LIMIT 1 FOR UPDATE",
                ['public_id'=>$claimPublicId]
            );
            if (!$claim || (int)$claim['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('跑空费记录不存在或不属于你的订单', 'RUN_EMPTY_CLAIM_NOT_FOUND', 404);
            }
            if ((string)$claim['status'] === 'paid' && $decision === 'accepted') {
                return [
                    'id'=>$claimPublicId,'status'=>'paid','already_processed'=>true,
                    'payment_required'=>false,'collection_mode'=>(string)$claim['collection_mode'],
                ];
            }
            if ((string)$claim['status'] === 'disputed' && $decision === 'rejected') {
                return [
                    'id'=>$claimPublicId,'status'=>'disputed','already_processed'=>true,
                    'payment_required'=>false,'collection_mode'=>(string)$claim['collection_mode'],
                ];
            }
            if ((string)$claim['status'] !== 'pending_confirmation') {
                throw new OrderOperationException('该跑空费当前不能处理，请刷新订单', 'RUN_EMPTY_CLAIM_STATE_CHANGED', 409);
            }

            $source = (string)$claim['order_source_type'];
            if ($decision === 'rejected') {
                $disputeId = $this->createDispute(
                    $db, $claim, (int)$actor['user_id'], (int)$claim['amount_cents'],
                    '师傅说明：'.(string)$claim['reason'].'；用户说明：'.$note, 'user'
                );
                $db->execute(
                    "UPDATE run_empty_fee_claims
                     SET status='disputed',user_note=:note,dispute_id=:dispute_id,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='pending_confirmation'",
                    ['note'=>$note,'dispute_id'=>$disputeId,'id'=>$claim['id']]
                );
                $db->execute("UPDATE orders SET dispute_status='open',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id", ['id'=>$claim['order_id']]);
                $this->event($db, (int)$claim['order_id'], 'run_empty_claim_disputed', 'user', (int)$actor['user_id'], [
                    'claim_id'=>$claimPublicId,'amount_cents'=>(int)$claim['amount_cents'],'note'=>$note,
                    'message'=>'用户不同意跑空费，已申请平台介入处理',
                ]);
                return ['id'=>$claimPublicId,'status'=>'disputed','payment_required'=>false,'collection_mode'=>(string)$claim['collection_mode']];
            }

            if ($source === 'merchant') {
                $db->execute(
                    "UPDATE run_empty_fee_claims
                     SET status='awaiting_payment',user_note=:note,decided_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='pending_confirmation'",
                    ['note'=>$note !== '' ? $note : null,'id'=>$claim['id']]
                );
                $this->event($db, (int)$claim['order_id'], 'run_empty_claim_confirmed', 'user', (int)$actor['user_id'], [
                    'claim_id'=>$claimPublicId,'amount_cents'=>(int)$claim['amount_cents'],
                    'message'=>'商家已确认跑空费，请完成在线支付','notify_recipients'=>['worker'],
                ]);
                return ['id'=>$claimPublicId,'status'=>'awaiting_payment','payment_required'=>true,'collection_mode'=>'online'];
            }
            if ($source !== 'consumer' || (string)($claim['workflow_profile'] ?? 'construction') === 'measurement'
                || (string)$claim['collection_mode'] !== 'booking_offset') {
                throw new OrderOperationException('该订单的跑空费需要平台处理，不能自动从预约费抵扣', 'RUN_EMPTY_MANUAL_REVIEW_REQUIRED', 409);
            }

            return $this->settleConsumerBookingOffset($db, $actor, $claim, $note);
        });
    }

    /** @return array<string,mixed> */
    private function settleConsumerBookingOffset(Database $db, array $actor, array $claim, string $note): array
    {
        $cancel = $db->fetch(
            "SELECT * FROM order_cancel_requests
             WHERE order_id=:order_id AND status IN ('pending','processing') AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['order_id'=>$claim['order_id']]
        );
        if (!$cancel) {
            throw new OrderOperationException('退款取消申请已变化，请刷新后重试', 'RUN_EMPTY_CANCEL_REQUEST_CHANGED', 409);
        }
        $payment = $db->fetch(
            "SELECT * FROM payment_orders
             WHERE order_id=:order_id AND business_type IN ('order_booking','booking_fee') AND status IN ('paid','refunded')
             ORDER BY id DESC LIMIT 1 FOR UPDATE",
            ['order_id'=>$claim['order_id']]
        );
        if (!$payment) {
            throw new OrderOperationException('未找到真实预约费支付记录，请转平台核对', 'RUN_EMPTY_BOOKING_PAYMENT_MISSING', 409);
        }

        $cap = max(0, (int)$payment['amount_cents']);
        if ((int)$claim['booking_fee_cents'] > 0) $cap = min($cap, (int)$claim['booking_fee_cents']);
        $refundAllocated = (int)$db->fetchValue(
            "SELECT COALESCE(SUM(amount_cents),0) FROM refunds
             WHERE payment_order_id=:payment_id AND status NOT IN ('failed','closed','cancelled','rejected')",
            ['payment_id'=>$payment['id']]
        );
        $legacyRunner = (int)$db->fetchValue(
            "SELECT COALESCE(SUM(l.amount_cents),0)
             FROM worker_balance_ledger l JOIN disputes d ON d.id=l.business_id
             WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective' AND d.order_id=:order_id",
            ['order_id'=>$claim['order_id']]
        );
        $otherClaimRunner = (int)$db->fetchValue(
            "SELECT COALESCE(SUM(l.amount_cents),0)
             FROM worker_balance_ledger l JOIN run_empty_fee_claims rc ON rc.id=l.business_id
             WHERE l.business_type='run_empty_claim' AND l.entry_type='run_empty_fee' AND l.status='effective'
               AND rc.order_id=:order_id AND rc.id<>:claim_id",
            ['order_id'=>$claim['order_id'],'claim_id'=>$claim['id']]
        );
        $currentCredit = (int)$db->fetchValue(
            "SELECT COALESCE(SUM(amount_cents),0) FROM worker_balance_ledger
             WHERE business_type='run_empty_claim' AND business_id=:claim_id AND entry_type='run_empty_fee' AND status='effective'",
            ['claim_id'=>$claim['id']]
        );
        $availableBeforeCurrent = max(0, $cap - $refundAllocated - $legacyRunner - $otherClaimRunner);
        $amount = (int)$claim['amount_cents'];
        if ($amount > $availableBeforeCurrent || ($currentCredit > 0 && $currentCredit !== $amount)) {
            throw new OrderOperationException('预约费已有其他退款或费用分配，剩余金额不足，请转平台核对', 'RUN_EMPTY_BOOKING_BALANCE_INSUFFICIENT', 409);
        }

        if ($currentCredit === 0) {
            $db->execute(
                "INSERT INTO worker_balance_ledger(
                    worker_id,entry_type,direction,amount_cents,available_delta_cents,frozen_delta_cents,
                    business_type,business_id,idempotency_key,description,status,created_at
                 ) VALUES(
                    :worker_id,'run_empty_fee','credit',:amount,:available,0,
                    'run_empty_claim',:business_id,:idempotency_key,:description,'effective',UTC_TIMESTAMP()
                 )",
                [
                    'worker_id'=>$claim['worker_id'],'amount'=>$amount,'available'=>$amount,'business_id'=>$claim['id'],
                    'idempotency_key'=>'run-empty-claim:'.$claim['id'].':booking-offset',
                    'description'=>'用户确认跑空费，预约费内自动结算',
                ]
            );
        }

        $remainingToAllocate = max(0, $availableBeforeCurrent - $amount);
        $refundId = null;
        if ($remainingToAllocate > 0) {
            $refundId = $db->insert(
                "INSERT INTO refunds(
                    public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,
                    requested_by_admin_id,requested_at,created_at,updated_at
                 ) VALUES(
                    :public_no,:payment_id,:order_id,:cancel_request_id,:amount,:reason,'pending_review',
                    NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_no'=>\business_no('RF'),'payment_id'=>$payment['id'],'order_id'=>$claim['order_id'],
                    'cancel_request_id'=>$cancel['id'],'amount'=>$remainingToAllocate,
                    'reason'=>'用户确认跑空费后，剩余预约费自动原路退款',
                ]
            );
        }

        $effectiveRefundTotal = $refundAllocated + $remainingToAllocate;
        $db->execute(
            "UPDATE run_empty_fee_claims
             SET status='paid',user_note=:note,decided_at=UTC_TIMESTAMP(),paid_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='pending_confirmation'",
            ['note'=>$note !== '' ? $note : null,'id'=>$claim['id']]
        );
        $db->execute(
            "UPDATE order_cancel_requests
             SET status='approved',requested_refund_cents=:refund,updated_at=UTC_TIMESTAMP(),closed_at=IF(:has_refund=1,NULL,UTC_TIMESTAMP())
             WHERE id=:id AND status IN ('pending','processing')",
            ['refund'=>$effectiveRefundTotal,'has_refund'=>$effectiveRefundTotal > 0 ? 1 : 0,'id'=>$cancel['id']]
        );
        $db->insert(
            "INSERT INTO order_cancel_decisions(
                cancel_request_id,order_id,decision,responsibility,reason,refund_amount_cents,run_empty_fee_cents,
                refund_id,decided_by,decided_at,created_at
             ) VALUES(
                :cancel_request_id,:order_id,'approved','shared',:reason,:refund,:runner,
                :refund_id,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'cancel_request_id'=>$cancel['id'],'order_id'=>$claim['order_id'],
                'reason'=>'用户确认师傅跑空费，系统按真实预约费自动分配；无需管理员裁定',
                'refund'=>$effectiveRefundTotal,'runner'=>$amount,'refund_id'=>$refundId,
            ]
        );

        $riskOrder = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$claim['order_id']]);
        (new DatabaseOrderCancellationRiskRepository($db))->captureCancellation($riskOrder, 'user', $amount);
        $hasRefund = $effectiveRefundTotal > 0;
        $db->execute(
            "UPDATE orders SET
                lifecycle_status=:lifecycle,refund_status=:refund_status,publish_status='hidden',quote_status='cancelled',
                assignment_status='cancelled',fulfillment_status='cancelled',dispute_status='resolved',
                cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()),
                closed_at=IF(:close_now=1,COALESCE(closed_at,UTC_TIMESTAMP()),closed_at),
                updated_at=UTC_TIMESTAMP(),version=version+1
             WHERE id=:id",
            [
                'lifecycle'=>$hasRefund ? 'cancel_approved_pending_refund' : 'cancelled',
                'refund_status'=>$hasRefund ? 'pending_review' : 'none',
                'close_now'=>$hasRefund ? 0 : 1,'id'=>$claim['order_id'],
            ]
        );
        $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')", ['id'=>$claim['order_id']]);
        $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')", ['id'=>$claim['order_id']]);
        $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'", ['id'=>$claim['order_id']]);
        $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'", ['id'=>$claim['order_id']]);
        $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')", ['id'=>$claim['order_id']]);

        $this->event($db, (int)$claim['order_id'], 'run_empty_fee_paid', 'user', (int)$actor['user_id'], [
            'claim_id'=>$claim['public_id'],'amount_cents'=>$amount,'refund_cents'=>$effectiveRefundTotal,
            'collection_mode'=>'booking_offset','message'=>'用户已确认跑空费，费用已从预约费中结算，剩余预约费进入原路退款',
            'notify_recipients'=>['user','worker'],
        ]);
        $this->event($db, (int)$claim['order_id'], 'cancel_approved', 'system', 0, [
            'refund_amount_cents'=>$effectiveRefundTotal,'run_empty_fee_cents'=>$amount,
            'auto_approved'=>1,'message'=>'用户与师傅已自行确认跑空费，系统自动完成取消资金分配',
            'notify_recipients'=>[],
        ], false);

        return [
            'id'=>(string)$claim['public_id'],'status'=>'paid','payment_required'=>false,'collection_mode'=>'booking_offset',
            'run_empty_cents'=>$amount,'refund_cents'=>$effectiveRefundTotal,'refund_created_cents'=>$remainingToAllocate,
        ];
    }

    private function resolveOrder(string $candidate): array
    {
        $candidate = trim($candidate);
        $order = null;
        if (ctype_digit($candidate)) $order = $this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1', ['id'=>(int)$candidate]);
        if (!$order) $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1', ['public_no'=>$candidate]);
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        return $order;
    }

    /** @return array{rows:array<int,array<string,mixed>>,watermarks:array<int,array<string,mixed>>} */
    private function arrivalEvidence(int $orderId, float $reportedLat, float $reportedLng): array
    {
        $rows = $this->db->fetchAll(
            "SELECT mf.id,mf.public_id,mf.metadata_json
             FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:order_id AND ml.purpose='arrival_evidence'
             ORDER BY ml.sort_order,ml.id",
            ['order_id'=>$orderId]
        );
        if (!$rows) throw new OrderOperationException('请先上传带位置和时间水印的到场取证照片', 'ARRIVAL_EVIDENCE_REQUIRED', 422);
        $watermarks = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
            $lat = is_array($metadata) && is_numeric($metadata['captured_lat'] ?? null) ? (float)$metadata['captured_lat'] : null;
            $lng = is_array($metadata) && is_numeric($metadata['captured_lng'] ?? null) ? (float)$metadata['captured_lng'] : null;
            if (!is_array($metadata) || empty($metadata['watermarked']) || trim((string)($metadata['captured_at'] ?? '')) === ''
                || trim((string)($metadata['location_text'] ?? '')) === '' || !$this->validCoordinate($lat, $lng)) {
                throw new OrderOperationException('到场取证照片必须带有位置和时间水印及有效坐标，请重新上传', 'ARRIVAL_EVIDENCE_WATERMARK_REQUIRED', 422);
            }
            if ($this->distanceKm($reportedLat, $reportedLng, (float)$lat, (float)$lng) > 1.0) {
                throw new OrderOperationException('提交定位与取证照片坐标不一致，请重新拍摄现场照片', 'ARRIVAL_EVIDENCE_LOCATION_MISMATCH', 422);
            }
            $watermarks[] = [
                'id'=>(string)$row['public_id'],'captured_at'=>$metadata['captured_at'],'lat'=>$lat,'lng'=>$lng,
                'location_text'=>(string)$metadata['location_text'],
            ];
        }
        return ['rows'=>$rows,'watermarks'=>$watermarks];
    }

    private function createDispute(Database $db, array $orderOrClaim, int $raisedById, int $amount, string $description, string $raisedByType): int
    {
        $orderId = (int)($orderOrClaim['order_id'] ?? $orderOrClaim['id'] ?? 0);
        if ($orderId < 1 && isset($orderOrClaim['order_id'])) $orderId = (int)$orderOrClaim['order_id'];
        $publicNo = 'DSP'.gmdate('ymdHis').strtoupper(bin2hex(random_bytes(3)));
        return $db->insert(
            "INSERT INTO disputes(
                public_no,order_id,raised_by_type,raised_by_id,dispute_type,title,description,status,responsibility,
                refund_amount_cents,run_empty_fee_cents,opened_at,created_at,updated_at
             ) VALUES(
                :public_no,:order_id,:raised_by_type,:raised_by_id,'run_empty_unpaid','跑空费协商未达成一致',:description,'open','undetermined',
                0,:amount,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
             )",
            [
                'public_no'=>$publicNo,'order_id'=>$orderId,'raised_by_type'=>$raisedByType,'raised_by_id'=>$raisedById,
                'description'=>$description,'amount'=>$amount,
            ]
        );
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload, bool $notify = true): void
    {
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,
                'actor_id'=>$actorId > 0 ? $actorId : null,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        if ($notify) OrderNotificationService::queue($db, $orderId, $eventKey, $type, $payload);
    }

    private function validCoordinate(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null && is_finite($lat) && is_finite($lng)
            && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2-$lat1);
        $dLng = deg2rad($lng2-$lng1);
        $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2) ** 2;
        return round($earth * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0-$a))), 1);
    }
}

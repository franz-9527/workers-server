<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderCompletionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\FulfillmentActor;
use WorkerAdmin\Services\SchedulerService;

final class DatabaseOrderCompletionRepository implements OrderCompletionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function complete(array $actor, array $order, string $description, int $warrantyMonths, string $warrantyNote, int $autoAcceptHours): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1 || (int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
            throw new OrderOperationException('只有本单成交师傅可以提交完工', 'FORBIDDEN', 403);
        }
        if ((string)$order['lifecycle_status'] !== 'active') {
            throw new OrderOperationException('当前订单不能提交完工', 'COMPLETION_NOT_ALLOWED', 409);
        }
        if (!empty($order['completion_submitted_at']) || (string)$order['fulfillment_status'] === 'submitted') {
            throw new OrderOperationException('该订单已经提交完工', 'COMPLETION_ALREADY_SUBMITTED', 409);
        }

        $platform = (string)($order['order_source_type'] ?? '') === 'platform';
        $pendingSql = $platform
            ? "SELECT id FROM change_requests WHERE order_id=:id AND status='pending' LIMIT 1"
            : "SELECT id FROM change_requests WHERE order_id=:id AND (status='pending' OR (status='accepted' AND payment_status IN ('awaiting_payment','refund_pending'))) LIMIT 1";
        if ($this->db->fetchValue($pendingSql, ['id'=>$order['id']])) {
            throw new OrderOperationException('加费申请仍待处理、支付或退款，请先完成费用流程', 'CHANGE_PENDING', 409);
        }
        $photoCount = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:id AND ml.purpose='completion'",
            ['id'=>$order['id']]
        );
        if ($photoCount < 1) throw new OrderOperationException('请至少上传一张完工照片', 'COMPLETION_PHOTO_REQUIRED', 422);

        $this->db->transaction(function (Database $db) use ($actor,$order,$workerId,$description,$warrantyMonths,$warrantyNote,$autoAcceptHours,$photoCount,$platform): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (string)$locked['lifecycle_status'] !== 'active' || !empty($locked['completion_submitted_at'])) {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            if ((int)($locked['assigned_worker_id'] ?? 0) !== $workerId) {
                throw new OrderOperationException('当前成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            $assignment = $db->fetch(
                "SELECT id,status FROM assignments WHERE id=:id AND worker_id=:worker_id FOR UPDATE",
                ['id'=>$locked['current_assignment_id'] ?: 0,'worker_id'=>$workerId]
            );
            if (!$assignment || (string)$assignment['status'] !== 'accepted') {
                throw new OrderOperationException('当前成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            }
            $pendingSql = $platform
                ? "SELECT id FROM change_requests WHERE order_id=:id AND status='pending' LIMIT 1 FOR UPDATE"
                : "SELECT id FROM change_requests WHERE order_id=:id AND (status='pending' OR (status='accepted' AND payment_status IN ('awaiting_payment','refund_pending'))) LIMIT 1 FOR UPDATE";
            if ($db->fetchValue($pendingSql, ['id'=>$locked['id']])) {
                throw new OrderOperationException('加费申请仍待处理、支付或退款，请先完成费用流程', 'CHANGE_PENDING', 409);
            }

            $version = (int)$db->fetchValue('SELECT COALESCE(MAX(version),0)+1 FROM completion_submissions WHERE order_id=:id', ['id'=>$locked['id']]);
            $warrantyExpirySql = $warrantyMonths > 0 ? 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.$warrantyMonths.' MONTH)' : 'NULL';
            $expiryParams=[];
            if (FulfillmentActor::assisted($actor)) {
                // 协助完工不新增、延长或清除师傅已经明确作出的保障承诺。
                $warrantyMonths=(int)($locked['warranty_months'] ?? 0);
                $warrantyNote=(string)($locked['warranty_note'] ?? '');
                $warrantyExpirySql=':existing_warranty_expiry';
                $expiryParams=['existing_warranty_expiry'=>$locked['warranty_expires_at'] ?? null];
            }
            $db->insert(
                "INSERT INTO completion_submissions(order_id,worker_id,description,warranty_months,warranty_expires_at,warranty_note,status,submitted_at,version,created_at,updated_at)
                 VALUES(:order_id,:worker_id,:description,:warranty_months,{$warrantyExpirySql},:warranty_note,'submitted',UTC_TIMESTAMP(),:version,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'order_id'=>$locked['id'],'worker_id'=>$workerId,'description'=>$description !== '' ? $description : null,
                    'warranty_months'=>$warrantyMonths > 0 ? $warrantyMonths : null,'warranty_note'=>$warrantyNote !== '' ? $warrantyNote : null,'version'=>$version,
                ] + $expiryParams
            );
            $changed = $db->execute(
                "UPDATE orders SET fulfillment_status='submitted',completion_submitted_at=UTC_TIMESTAMP(),auto_accept_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoAcceptHours} HOUR),
                        warranty_months=:warranty_months,warranty_expires_at={$warrantyExpirySql},warranty_note=:warranty_note,updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id AND completion_submitted_at IS NULL",
                ['warranty_months'=>$warrantyMonths > 0 ? $warrantyMonths : null,'warranty_note'=>$warrantyNote !== '' ? $warrantyNote : null,'id'=>$locked['id']] + $expiryParams
            );
            if ($changed !== 1) throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            $db->insert(
                "INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at)
                 VALUES(:order_id,'auto_accept_completion','pending',:version,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoAcceptHours} HOUR),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$locked['id'],'version'=>(int)$locked['version'] + 1]
            );
            $this->event($db, (int)$locked['id'], 'completion_submitted', FulfillmentActor::type($actor), FulfillmentActor::id($actor), [
                'photo_count'=>$photoCount,'auto_accept_hours'=>$autoAcceptHours,'warranty_months'=>$warrantyMonths,'platform_order'=>$platform,
            ] + FulfillmentActor::metadata($actor));
            if (!$platform) (new SchedulerService($db))->createCommissionEstimate($db, (int)$locked['id']);
        });

        return [
            'platform_order'=>$platform,
            'message'=>$platform
                ? '完工已提交，等待客户验收；平台承接单不生成普通平台服务费'
                : '完工已提交，已生成预计服务费；可上传收款凭证并提前支付，用户未确认时将按设置自动验收',
        ];
    }

    public function accept(array $actor, array $order, int $autoReviewDays): array
    {
        if ((string)$order['fulfillment_status'] !== 'submitted' || (string)$order['lifecycle_status'] !== 'active') {
            throw new OrderOperationException('该订单当前不能验收', 'ORDER_NOT_ACCEPTABLE', 409);
        }
        if (!in_array((string)($order['dispute_status'] ?? 'none'), ['none','resolved'], true)
            || $this->db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1", ['order_id'=>$order['id']])) {
            throw new OrderOperationException('本单正在争议处理中，请等待平台裁决后再验收', 'DISPUTE_IN_PROGRESS', 409);
        }
        if ((int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:order_id AND ml.purpose='collection_receipt'",
            ['order_id'=>$order['id']]
        ) < 1) {
            throw new OrderOperationException('师傅尚未上传收款凭证，平台核验完成后才能验收', 'COLLECTION_RECEIPT_REQUIRED', 409);
        }

        $platform = (string)($order['order_source_type'] ?? '') === 'platform';
        if ($platform) return $this->acceptPlatform($actor, $order, $autoReviewDays);
        return $this->acceptOrdinary($actor, $order, $autoReviewDays);
    }

    private function acceptOrdinary(array $actor, array $order, int $autoReviewDays): array
    {
        $this->db->transaction(function (Database $db) use ($actor,$order,$autoReviewDays): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (string)$locked['fulfillment_status'] !== 'submitted' || (string)$locked['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            if (!in_array((string)($locked['dispute_status'] ?? 'none'), ['none','resolved'], true)
                || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1 FOR UPDATE", ['order_id'=>$locked['id']])) {
                throw new OrderOperationException('本单正在争议处理中，请等待平台裁决后再验收', 'DISPUTE_IN_PROGRESS', 409);
            }
            $changed = $db->execute(
                "UPDATE orders SET fulfillment_status='accepted',lifecycle_status='completed',accepted_at=UTC_TIMESTAMP(),auto_review_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoReviewDays} DAY),closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id AND fulfillment_status='submitted'",
                ['id'=>$locked['id']]
            );
            if ($changed !== 1) throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            $db->execute("UPDATE completion_submissions SET status='accepted',accepted_at=UTC_TIMESTAMP(),accepted_type='user',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='submitted'", ['id'=>$locked['id']]);
            $this->closeAutoAcceptDeadline($db, (int)$locked['id']);
            if (!empty($locked['assigned_worker_id'])) {
                $db->execute(
                    "UPDATE worker_profiles SET completed_orders=(SELECT COUNT(*) FROM orders o2 WHERE o2.assigned_worker_id=:count_worker_id AND o2.accepted_at IS NOT NULL),updated_at=UTC_TIMESTAMP() WHERE id=:worker_id",
                    ['count_worker_id'=>$locked['assigned_worker_id'],'worker_id'=>$locked['assigned_worker_id']]
                );
            }
            $db->insert(
                "INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at)
                 VALUES(:order_id,'auto_review','pending',:version,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoReviewDays} DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$locked['id'],'version'=>(int)$locked['version'] + 1]
            );
            $this->event($db, (int)$locked['id'], 'completion_accepted_by_user', 'user', (int)$actor['user_id'], ['auto_review_days'=>$autoReviewDays]);
            (new SchedulerService($db))->finalizeCommissionReceivable($db, (int)$locked['id']);
        });
        return ['message'=>'验收完成，感谢使用'];
    }

    private function acceptPlatform(array $actor, array $order, int $autoReviewDays): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$order,$autoReviewDays): array {
            $locked = $db->fetch("SELECT * FROM orders WHERE id=:id AND order_source_type='platform' FOR UPDATE", ['id'=>$order['id']]);
            $terms = $db->fetch('SELECT * FROM order_platform_terms WHERE order_id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || !$terms || (string)$locked['fulfillment_status'] !== 'submitted' || (string)$locked['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            if (!in_array((string)($locked['dispute_status'] ?? 'none'), ['none','resolved'], true)
                || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1 FOR UPDATE", ['order_id'=>$locked['id']])) {
                throw new OrderOperationException('本单正在争议处理中，请等待平台处理', 'DISPUTE_IN_PROGRESS', 409);
            }
            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE id=:id AND worker_id=:worker_id AND status='accepted' FOR UPDATE",
                ['id'=>$locked['current_assignment_id'] ?: 0,'worker_id'=>$locked['assigned_worker_id'] ?: 0]
            );
            if (!$assignment) throw new OrderOperationException('成交师傅关系不存在', 'ASSIGNMENT_CHANGED', 409);

            // Financial settlement is shared with the scheduler automatic acceptance path.
            // All writes below are part of this existing order transaction.
            $calculation = (new PlatformOrderSettlementWriter())->apply($db, $locked, $terms, $assignment);
            $freezeDays = (int)$calculation['freeze_days'];
            $platformReceivable = (int)$calculation['platform_receivable_cents'];
            $workerBalanceCredit = (int)$calculation['worker_balance_credit_cents'];
            $settlementStatus = (string)$calculation['settlement_status'];

            $changed = $db->execute(
                "UPDATE orders SET fulfillment_status='accepted',lifecycle_status='completed',accepted_at=UTC_TIMESTAMP(),auto_review_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoReviewDays} DAY),closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id AND fulfillment_status='submitted'",
                ['id'=>$locked['id']]
            );
            if ($changed !== 1) throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            $db->execute("UPDATE completion_submissions SET status='accepted',accepted_at=UTC_TIMESTAMP(),accepted_type='user',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='submitted'", ['id'=>$locked['id']]);
            $this->closeAutoAcceptDeadline($db, (int)$locked['id']);
            if (!empty($locked['assigned_worker_id'])) {
                $db->execute(
                    "UPDATE worker_profiles SET completed_orders=(SELECT COUNT(*) FROM orders o2 WHERE o2.assigned_worker_id=:count_worker_id AND o2.accepted_at IS NOT NULL),updated_at=UTC_TIMESTAMP() WHERE id=:worker_id",
                    ['count_worker_id'=>$locked['assigned_worker_id'],'worker_id'=>$locked['assigned_worker_id']]
                );
            }
            $db->insert(
                "INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at)
                 VALUES(:order_id,'auto_review','pending',:version,DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoReviewDays} DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['order_id'=>$locked['id'],'version'=>(int)$locked['version'] + 1]
            );
            $this->event($db, (int)$locked['id'], 'platform_completion_accepted', 'user', (int)$actor['user_id'], [
                'customer_total_cents'=>(int)$terms['customer_contract_total_cents'],
                'booking_paid_cents'=>(int)$calculation['booking_paid_cents'],'customer_tail_cents'=>(int)$calculation['customer_tail_cents'],
                'worker_labor_cents'=>(int)$terms['worker_labor_cents'],'platform_receivable_cents'=>$platformReceivable,
                'worker_balance_credit_cents'=>$workerBalanceCredit,'worker_settlement_mode'=>$terms['worker_settlement_mode'],'freeze_days'=>$freezeDays,
            ]);
            return [
                'customer_total_cents'=>(int)$terms['customer_contract_total_cents'],
                'booking_paid_cents'=>(int)$calculation['booking_paid_cents'],'customer_tail_cents'=>(int)$calculation['customer_tail_cents'],
                'worker_labor_cents'=>(int)$terms['worker_labor_cents'],'platform_receivable_cents'=>$platformReceivable,
                'worker_balance_credit_cents'=>$workerBalanceCredit,'worker_settlement_mode'=>(string)$terms['worker_settlement_mode'],
                'settlement_status'=>$settlementStatus,'platform_order'=>true,'message'=>'验收完成，平台承接单结算金额已生成',
            ];
        });
    }

    private function closeAutoAcceptDeadline(Database $db, int $orderId): void
    {
        $db->execute(
            "UPDATE order_deadlines SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE order_id=:id AND deadline_type IN ('auto_accept','auto_accept_completion') AND status IN ('pending','blocked','claimed')",
            ['id'=>$orderId]
        );
    }

    private function event(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventId = $db->insert(
            'INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            ['order_id'=>$orderId,'type'=>$type,'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,'payload'=>is_string($json) ? $json : '{}']
        );
        \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, $orderId, $eventId, $type, $payload);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderMeasurementCompletionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\FulfillmentActor;

/**
 * 平台测量阶段策略装饰器。
 *
 * 普通测量完全委托原仓储；平台测量由师傅提交完整成果后自动确认成果，但不直接进入施工，
 * 而是留在同一订单的 measurement 工作流等待管理员核价。客户绑定与否都不再成为正常履约阻塞点，
 * 已绑定客户在核价前仍可退回一次补测。
 */
final class PlatformPricingAwareOrderMeasurementCompletionRepository implements OrderMeasurementCompletionRepository
{
    private OrderMeasurementCompletionRepository $inner;
    private Database $db;

    public function __construct(OrderMeasurementCompletionRepository $inner, Database $db)
    {
        $this->inner = $inner;
        $this->db = $db;
    }

    public function complete(array $actor, array $order, int $autoAcceptHours): array
    {
        if ((string)($order['order_source_type'] ?? '') !== 'platform') {
            return $this->inner->complete($actor, $order, $autoAcceptHours);
        }
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ((string)($order['workflow_profile'] ?? '') !== 'measurement') {
            throw new OrderOperationException('当前平台订单已不在测量阶段', 'NOT_MEASUREMENT_ORDER', 409);
        }
        if ($workerId < 1 || (int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
            throw new OrderOperationException('只有本单成交师傅可以提交测量成果', 'FORBIDDEN', 403);
        }

        $this->db->transaction(function (Database $db) use ($actor,$order,$workerId): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$locked || !$state || (string)$locked['order_source_type'] !== 'platform'
                || (string)$locked['workflow_profile'] !== 'measurement'
                || (string)$locked['lifecycle_status'] !== 'active'
                || (string)$state['result_status'] !== 'editing') {
                throw new OrderOperationException('平台测量状态已变化，请刷新后重试', 'MEASUREMENT_RESULT_LOCKED', 409);
            }
            if (!in_array((string)($locked['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)
                || !in_array((string)($locked['refund_status'] ?? 'none'), ['', 'none'], true)) {
                throw new OrderOperationException('协商或平台处理尚未结束，请先处理异常事项', 'ORDER_EXCEPTION_PENDING', 409);
            }
            if ((int)($locked['assigned_worker_id'] ?? 0) !== $workerId) {
                throw new OrderOperationException('当前成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            }
            $assignment = $db->fetch(
                "SELECT id,status FROM assignments WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id LIMIT 1 FOR UPDATE",
                ['id'=>$locked['current_assignment_id'] ?: 0,'order_id'=>$locked['id'],'worker_id'=>$workerId]
            );
            if (!$assignment || (string)$assignment['status'] !== 'accepted') {
                throw new OrderOperationException('当前成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            $this->assertNoPendingChange($db, (int)$locked['id']);

            [$spaceCount,$imageCount,$emptySpaceCount] = $this->measurementCounts($db, (int)$locked['id']);
            if ($spaceCount < 1 || $imageCount < 1) {
                throw new OrderOperationException('请至少添加一个空间并上传手写测量单、标注图或现场照片', 'MEASUREMENT_RESULT_REQUIRED', 422);
            }
            if ($emptySpaceCount > 0) {
                throw new OrderOperationException('每个测量空间至少需要一张成果图片，请补充后再提交', 'MEASUREMENT_SPACE_IMAGE_REQUIRED', 422);
            }

            $db->execute(
                "UPDATE order_measurement_states
                 SET result_status='accepted',submitted_at=UTC_TIMESTAMP(),accepted_at=UTC_TIMESTAMP(),auto_accept_at=NULL,
                     settlement_status='not_applicable',gross_amount_cents=0,refunded_amount_cents=0,
                     service_rate_bp_snapshot=0,service_min_cents_snapshot=0,service_fee_cents=0,worker_net_cents=0,
                     hold_until=NULL,max_hold_until=NULL,settled_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id",
                ['order_id'=>$locked['id']]
            );
            $db->execute(
                "UPDATE orders
                 SET fulfillment_status='submitted',completion_submitted_at=UTC_TIMESTAMP(),auto_accept_at=NULL,
                     accepted_at=NULL,closed_at=NULL,lifecycle_status='active',updated_at=UTC_TIMESTAMP(),version=version+1
                 WHERE id=:id AND workflow_profile='measurement' AND lifecycle_status='active'",
                ['id'=>$locked['id']]
            );
            $this->event($db, (int)$locked['id'], 'platform_measurement_auto_confirmed', FulfillmentActor::assisted($actor) ? 'mini_admin' : 'system', FulfillmentActor::assisted($actor) ? FulfillmentActor::id($actor) : 0, [
                'submitted_by_worker_id'=>$workerId,
                'space_count'=>$spaceCount,
                'image_count'=>$imageCount,
                'awaiting_pricing_review'=>true,
                'message'=>FulfillmentActor::assisted($actor) ? '管理员已补录完整测量成果，系统按平台单规则进入待核价阶段' : '师傅已提交完整测量成果，系统自动确认成果；同一平台订单等待平台核价后进入施工',
            ] + FulfillmentActor::metadata($actor));
        });

        return [
            'workflow_profile'=>'measurement',
            'platform_measurement'=>true,
            'advanced_to_construction'=>false,
            'awaiting_pricing_review'=>true,
        ];
    }

    public function accept(array $actor, array $order, int $autoReviewDays, int $freezeDays, int $maxFreezeDays): array
    {
        if ((string)($order['order_source_type'] ?? '') !== 'platform') {
            return $this->inner->accept($actor, $order, $autoReviewDays, $freezeDays, $maxFreezeDays);
        }
        if ((int)($order['user_id'] ?? 0) !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能处理自己的平台订单', 'FORBIDDEN', 403);
        }
        $state = $this->db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:order_id LIMIT 1', ['order_id'=>$order['id']]);
        if ((string)($order['workflow_profile'] ?? '') === 'measurement' && $state && (string)$state['result_status'] === 'accepted') {
            return [
                'workflow_profile'=>'measurement',
                'platform_measurement'=>true,
                'advanced_to_construction'=>false,
                'awaiting_pricing_review'=>true,
            ];
        }
        // 兼容已经处于旧“submitted 等客户确认”状态的平台单：确认后也只进入核价，不直接施工。
        $this->db->transaction(function (Database $db) use ($actor,$order): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$locked || !$state || (string)$locked['order_source_type'] !== 'platform'
                || (string)$locked['workflow_profile'] !== 'measurement'
                || (string)$locked['lifecycle_status'] !== 'active'
                || (string)$state['result_status'] !== 'submitted') {
                throw new OrderOperationException('测量成果状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            $this->assertNoPendingChange($db, (int)$locked['id']);
            $db->execute(
                "UPDATE order_measurement_states SET result_status='accepted',accepted_at=UTC_TIMESTAMP(),auto_accept_at=NULL,
                        settlement_status='not_applicable',updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                ['order_id'=>$locked['id']]
            );
            $db->execute(
                "UPDATE orders SET fulfillment_status='submitted',auto_accept_at=NULL,accepted_at=NULL,closed_at=NULL,
                        lifecycle_status='active',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$locked['id']]
            );
            $this->event($db, (int)$locked['id'], 'platform_measurement_confirmed', 'user', (int)($actor['user_id'] ?? 0), [
                'awaiting_pricing_review'=>true,
                'message'=>'客户已确认平台单测量成果；同一订单等待平台核价后进入施工',
            ]);
        });
        return [
            'workflow_profile'=>'measurement',
            'platform_measurement'=>true,
            'advanced_to_construction'=>false,
            'awaiting_pricing_review'=>true,
        ];
    }

    public function requestRework(array $actor, array $order, string $reason): array
    {
        if ((string)($order['order_source_type'] ?? '') !== 'platform') {
            return $this->inner->requestRework($actor, $order, $reason);
        }
        if ((int)($order['user_id'] ?? 0) !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能处理自己的平台订单', 'FORBIDDEN', 403);
        }
        if (mb_strlen($reason) < 4) {
            throw new OrderOperationException('请至少填写 4 个字说明需要补测或修改的内容', 'MEASUREMENT_REWORK_REASON_REQUIRED', 422);
        }

        $this->db->transaction(function (Database $db) use ($actor,$order,$reason): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$locked || !$state || (string)$locked['order_source_type'] !== 'platform'
                || (string)$locked['workflow_profile'] !== 'measurement'
                || (string)$locked['lifecycle_status'] !== 'active'
                || !in_array((string)$state['result_status'], ['submitted','accepted'], true)) {
                throw new OrderOperationException('当前平台测量成果不能退回补测', 'MEASUREMENT_REWORK_NOT_ALLOWED', 409);
            }
            $used = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM order_events WHERE order_id=:order_id AND event_type='measurement_rework_requested'",
                ['order_id'=>$locked['id']]
            );
            if ($used >= 1) {
                throw new OrderOperationException('平台单最多退回补测一次；如仍有问题，请先协商，无法解决再申请平台介入。', 'PLATFORM_MEASUREMENT_REWORK_LIMIT', 409);
            }
            if ($db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1 FOR UPDATE", ['order_id'=>$locked['id']])) {
                throw new OrderOperationException('本单正在争议处理中，不能同时退回补测', 'DISPUTE_IN_PROGRESS', 409);
            }
            $db->execute(
                "UPDATE order_measurement_states SET result_status='editing',submitted_at=NULL,accepted_at=NULL,auto_accept_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id",
                ['order_id'=>$locked['id']]
            );
            $db->execute(
                "UPDATE orders SET fulfillment_status='in_progress',completion_submitted_at=NULL,auto_accept_at=NULL,
                        updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$locked['id']]
            );
            $this->event($db, (int)$locked['id'], 'measurement_rework_requested', 'user', (int)($actor['user_id'] ?? 0), [
                'reason'=>$reason,
                'platform_order'=>true,
                'rework_limit'=>1,
                'message'=>'客户在平台核价前要求补测/修改测量成果：'.$reason,
            ]);
        });
        return ['platform_measurement'=>true,'remaining_rework'=>0,'awaiting_pricing_review'=>false];
    }

    private function assertNoPendingChange(Database $db, int $orderId): void
    {
        if ($db->fetchValue(
            "SELECT id FROM change_requests WHERE order_id=:order_id
             AND (status='pending' OR (status='accepted' AND payment_status IN ('awaiting_payment','refund_pending'))) LIMIT 1 FOR UPDATE",
            ['order_id'=>$orderId]
        )) {
            throw new OrderOperationException('仍有加价/费用事项待处理，请先完成协商', 'CHANGE_PENDING', 409);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function measurementCounts(Database $db, int $orderId): array
    {
        $spaceCount = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM order_measurement_spaces WHERE order_id=:order_id AND status='active'",
            ['order_id'=>$orderId]
        );
        $imageCount = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM media_links l
             JOIN order_measurement_spaces s ON s.id=l.entity_id AND s.status='active'
             JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
             WHERE l.entity_type='order_measurement_space' AND s.order_id=:order_id",
            ['order_id'=>$orderId]
        );
        $emptySpaceCount = (int)$db->fetchValue(
            "SELECT COUNT(*) FROM order_measurement_spaces s
             WHERE s.order_id=:order_id AND s.status='active'
               AND NOT EXISTS(SELECT 1 FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                              WHERE l.entity_type='order_measurement_space' AND l.entity_id=s.id)",
            ['order_id'=>$orderId]
        );
        return [$spaceCount,$imageCount,$emptySpaceCount];
    }

    /** @param array<string,mixed> $payload */
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


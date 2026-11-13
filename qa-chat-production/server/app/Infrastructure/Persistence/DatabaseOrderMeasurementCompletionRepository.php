<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderMeasurementCompletionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\FulfillmentActor;

final class DatabaseOrderMeasurementCompletionRepository implements OrderMeasurementCompletionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function complete(array $actor, array $order, int $autoAcceptHours): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ((string)($order['workflow_profile'] ?? '') !== 'measurement') {
            throw new OrderOperationException('当前订单不是测量阶段', 'NOT_MEASUREMENT_ORDER', 409);
        }
        if ($workerId < 1 || (int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
            throw new OrderOperationException('只有本单成交师傅可以提交测量成果', 'FORBIDDEN', 403);
        }

        $advancedToConstruction = $this->db->transaction(function (Database $db) use ($actor,$order,$workerId,$autoAcceptHours): bool {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$locked || !$state || (string)$locked['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            if ((string)($locked['workflow_profile'] ?? '') !== 'measurement') {
                throw new OrderOperationException('测量阶段已结束，请刷新订单', 'MEASUREMENT_RESULT_LOCKED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            if (!in_array((string)($locked['dispute_status'] ?? 'none'), ['', 'none','resolved'], true)
                || !in_array((string)($locked['refund_status'] ?? 'none'), ['', 'none'], true)) {
                throw new OrderOperationException('协商或平台处理尚未结束，请先处理异常事项', 'ORDER_EXCEPTION_PENDING', 409);
            }
            if ((int)($locked['assigned_worker_id'] ?? 0) !== $workerId) {
                throw new OrderOperationException('当前成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            }
            $assignment = $db->fetch(
                "SELECT id,status FROM assignments WHERE id=:id AND worker_id=:worker_id FOR UPDATE",
                ['id'=>$locked['current_assignment_id'] ?: 0,'worker_id'=>$workerId]
            );
            if (!$assignment || (string)$assignment['status'] !== 'accepted') {
                throw new OrderOperationException('当前成交师傅关系已变化', 'ASSIGNMENT_CHANGED', 409);
            }
            $isPlatform = (string)($locked['order_source_type'] ?? '') === 'platform';
            if (!$isPlatform && (string)$state['service_payment_status'] !== 'paid') {
                throw new OrderOperationException('测量服务款尚未结清', 'MEASUREMENT_PAYMENT_REQUIRED', 409);
            }
            if ((string)$state['result_status'] !== 'editing') {
                throw new OrderOperationException('测量成果当前不能重复提交', 'MEASUREMENT_RESULT_LOCKED', 409);
            }
            if ($db->fetchValue(
                "SELECT id FROM change_requests WHERE order_id=:order_id AND (status='pending' OR (status='accepted' AND payment_status IN ('awaiting_payment','refund_pending'))) LIMIT 1 FOR UPDATE",
                ['order_id'=>$locked['id']]
            )) throw new OrderOperationException('仍有加价/费用事项待处理，请先完成协商', 'CHANGE_PENDING', 409);

            $spaceCount = (int)$db->fetchValue("SELECT COUNT(*) FROM order_measurement_spaces WHERE order_id=:order_id AND status='active'", ['order_id'=>$locked['id']]);
            $imageCount = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM media_links l
                 JOIN order_measurement_spaces s ON s.id=l.entity_id AND s.status='active'
                 JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                 WHERE l.entity_type='order_measurement_space' AND s.order_id=:order_id",
                ['order_id'=>$locked['id']]
            );
            $emptySpaceCount = (int)$db->fetchValue(
                "SELECT COUNT(*) FROM order_measurement_spaces s
                 WHERE s.order_id=:order_id AND s.status='active'
                   AND NOT EXISTS(SELECT 1 FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                                  WHERE l.entity_type='order_measurement_space' AND l.entity_id=s.id)",
                ['order_id'=>$locked['id']]
            );
            if ($spaceCount < 1 || $imageCount < 1) {
                throw new OrderOperationException('请至少添加一个空间并上传手写测量单、标注图或现场照片', 'MEASUREMENT_RESULT_REQUIRED', 422);
            }
            if ($emptySpaceCount > 0) {
                throw new OrderOperationException('每个测量空间至少需要一张成果图片，请补充后再提交', 'MEASUREMENT_SPACE_IMAGE_REQUIRED', 422);
            }

            if ($isPlatform) {
                $customerStatus = (string)$db->fetchValue('SELECT status FROM users WHERE id=:id LIMIT 1', ['id'=>$locked['user_id']]);
                if ($customerStatus === 'platform_unbound') {
                    $this->advancePlatformToConstruction($db, $locked, 'system', 0, true, $spaceCount, $imageCount);
                    return true;
                }
                // 平台单不进入普通 measurement_accept 调度器：已绑定客户明确确认，或退回一次补测。
                $db->execute(
                    "UPDATE order_measurement_states
                     SET result_status='submitted',submitted_at=UTC_TIMESTAMP(),auto_accept_at=NULL,updated_at=UTC_TIMESTAMP()
                     WHERE order_id=:order_id",
                    ['order_id'=>$locked['id']]
                );
                $db->execute(
                    "UPDATE orders SET fulfillment_status='submitted',completion_submitted_at=UTC_TIMESTAMP(),auto_accept_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$locked['id']]
                );
                $this->event($db, (int)$locked['id'], 'platform_measurement_result_submitted', FulfillmentActor::type($actor), FulfillmentActor::id($actor), [
                    'space_count'=>$spaceCount,'image_count'=>$imageCount,
                    'message'=>FulfillmentActor::assisted($actor) ? '管理员已提交平台测量成果，等待后续确认' : '平台单测量成果已提交，等待客户确认；确认后同一订单自动进入施工阶段',
                ] + FulfillmentActor::metadata($actor));
                return false;
            }

            $db->execute(
                "UPDATE order_measurement_states SET result_status='submitted',submitted_at=UTC_TIMESTAMP(),auto_accept_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoAcceptHours} HOUR),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                ['order_id'=>$locked['id']]
            );
            $db->execute(
                "UPDATE orders SET fulfillment_status='submitted',completion_submitted_at=UTC_TIMESTAMP(),auto_accept_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$autoAcceptHours} HOUR),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$locked['id']]
            );
            $this->event($db, (int)$locked['id'], 'measurement_result_submitted', FulfillmentActor::type($actor), FulfillmentActor::id($actor), [
                'space_count'=>$spaceCount,'image_count'=>$imageCount,'auto_accept_hours'=>$autoAcceptHours,'message'=>FulfillmentActor::assisted($actor) ? '管理员已提交测量图片成果，等待用户确认' : '师傅已提交测量图片成果，等待用户确认',
            ] + FulfillmentActor::metadata($actor));
            return false;
        });
        return [
            'workflow_profile'=>$advancedToConstruction ? 'construction' : 'measurement',
            'platform_measurement'=>(string)($order['order_source_type'] ?? '') === 'platform',
            'advanced_to_construction'=>$advancedToConstruction,
        ];
    }

    public function accept(array $actor, array $order, int $autoReviewDays, int $freezeDays, int $maxFreezeDays): array
    {
        if ((string)($order['workflow_profile'] ?? '') !== 'measurement') {
            throw new OrderOperationException('当前订单不是测量阶段', 'NOT_MEASUREMENT_ORDER', 409);
        }
        if ((string)$order['fulfillment_status'] !== 'submitted' || (string)$order['lifecycle_status'] !== 'active') {
            throw new OrderOperationException('该测量成果当前不能确认', 'MEASUREMENT_NOT_ACCEPTABLE', 409);
        }
        if (!in_array((string)($order['dispute_status'] ?? 'none'), ['none','resolved'], true)
            || $this->db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1", ['order_id'=>$order['id']])) {
            throw new OrderOperationException('本单正在争议处理中，请等待平台处理', 'DISPUTE_IN_PROGRESS', 409);
        }

        $isPlatform = (string)($order['order_source_type'] ?? '') === 'platform';
        $this->db->transaction(function (Database $db) use ($actor,$order,$autoReviewDays,$freezeDays,$maxFreezeDays,$isPlatform): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$locked || !$state || (string)$state['result_status'] !== 'submitted'
                || (string)$locked['fulfillment_status'] !== 'submitted' || (string)$locked['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('测量成果状态已变化，请刷新后重试', 'ORDER_STATE_CHANGED', 409);
            }
            OrderFulfillmentWriteGuard::assertAllowed($db, $locked);
            if (!in_array((string)($locked['dispute_status'] ?? 'none'), ['none','resolved'], true)
                || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1 FOR UPDATE", ['order_id'=>$locked['id']])) {
                throw new OrderOperationException('本单正在争议处理中，请等待平台处理', 'DISPUTE_IN_PROGRESS', 409);
            }
            $assignment = $db->fetch(
                "SELECT * FROM assignments WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted' ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$locked['id'],'worker_id'=>$locked['assigned_worker_id'] ?: 0]
            );
            if (!$assignment) throw new OrderOperationException('测量成交师傅关系不存在', 'ASSIGNMENT_CHANGED', 409);

            if ($isPlatform) {
                if ((int)$locked['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                    throw new OrderOperationException('只能确认自己的平台订单', 'FORBIDDEN', 403);
                }
                if ($db->fetchValue("SELECT id FROM change_requests WHERE order_id=:order_id AND status='pending' LIMIT 1 FOR UPDATE", ['order_id'=>$locked['id']])) {
                    throw new OrderOperationException('仍有加价事项待协商，暂不能确认测量成果', 'CHANGE_PENDING', 409);
                }
                $this->advancePlatformToConstruction(
                    $db,
                    $locked,
                    'user',
                    (int)($actor['user_id'] ?? 0),
                    false,
                    (int)$db->fetchValue("SELECT COUNT(*) FROM order_measurement_spaces WHERE order_id=:order_id AND status='active'", ['order_id'=>$locked['id']]),
                    (int)$db->fetchValue(
                        "SELECT COUNT(*) FROM media_links l JOIN order_measurement_spaces s ON s.id=l.entity_id AND s.status='active'
                         JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                         WHERE l.entity_type='order_measurement_space' AND s.order_id=:order_id",
                        ['order_id'=>$locked['id']]
                    )
                );
                return;
            }

            $gross = (int)$locked['final_amount_cents'];
            $paid = (int)$db->fetchValue(
                "SELECT COALESCE(SUM(amount_cents),0) FROM payment_orders
                 WHERE order_id=:order_id AND business_type IN ('order_booking','measurement_balance','measurement_change') AND status='paid'",
                ['order_id'=>$locked['id']]
            );
            if ($paid < $gross) throw new OrderOperationException('测量服务款尚未全部到账，不能结算', 'MEASUREMENT_PAYMENT_REQUIRED', 409);

            (new OrdinaryMeasurementAcceptanceWriter())->apply(
                $db,
                $locked,
                $assignment,
                $autoReviewDays,
                $freezeDays,
                $maxFreezeDays,
                function(Database $eventDb,int $eventOrder,string $type,int $version,array $payload)use($actor):void{
                    $this->event($eventDb,$eventOrder,$type,'user',(int)$actor['user_id'],$payload);
                },
                'measurement_result_accepted',
                '用户已确认测量成果，师傅净收入进入冻结期'
            );
        });
        if ($isPlatform) {
            return ['workflow_profile'=>'construction','platform_measurement'=>true,'advanced_to_construction'=>true];
        }
        return ['workflow_profile'=>'measurement','freeze_days'=>$freezeDays];
    }

    public function requestRework(array $actor, array $order, string $reason): array
    {
        if ((string)($order['workflow_profile'] ?? '') !== 'measurement') {
            throw new OrderOperationException('当前订单不是测量阶段', 'NOT_MEASUREMENT_ORDER', 409);
        }
        if ((int)($order['user_id'] ?? 0) !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能处理自己的测量订单', 'FORBIDDEN', 403);
        }
        if (mb_strlen($reason) < 4) {
            throw new OrderOperationException('请至少填写 4 个字说明需要补测或修改的内容', 'MEASUREMENT_REWORK_REASON_REQUIRED', 422);
        }

        $platform = (string)($order['order_source_type'] ?? '') === 'platform';
        $this->db->transaction(function (Database $db) use ($actor,$order,$reason,$platform): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$locked || !$state || (string)$state['result_status'] !== 'submitted'
                || (string)$locked['lifecycle_status'] !== 'active' || (string)$locked['fulfillment_status'] !== 'submitted'
                || (string)($locked['workflow_profile'] ?? '') !== 'measurement') {
                throw new OrderOperationException('当前测量成果不在待确认状态', 'MEASUREMENT_REWORK_NOT_ALLOWED', 409);
            }
            if (!in_array((string)($locked['dispute_status'] ?? 'none'), ['none','resolved'], true)
                || $db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1 FOR UPDATE", ['order_id'=>$locked['id']])) {
                throw new OrderOperationException('本单正在争议处理中，不能同时退回补测', 'DISPUTE_IN_PROGRESS', 409);
            }
            {
                // 2026-10-08：普通和平台测量均最多一次补充，不循环退回。
                $reworkCount = (int)$db->fetchValue(
                    "SELECT COUNT(*) FROM order_events WHERE order_id=:order_id AND event_type='measurement_rework_requested'",
                    ['order_id'=>$locked['id']]
                );
                if ($reworkCount >= 1) {
                    throw new OrderOperationException('测量成果最多退回补测一次；如仍有问题，请先与师傅协商，无法解决再申请平台介入。', $platform ? 'PLATFORM_MEASUREMENT_REWORK_LIMIT' : 'MEASUREMENT_REWORK_LIMIT', 409);
                }
            }
            $db->execute(
                "UPDATE order_measurement_states SET result_status='editing',submitted_at=NULL,auto_accept_at=NULL,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id",
                ['order_id'=>$locked['id']]
            );
            $db->execute(
                "UPDATE orders SET fulfillment_status='in_progress',completion_submitted_at=NULL,auto_accept_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$locked['id']]
            );
            $this->event($db, (int)$locked['id'], 'measurement_rework_requested', 'user', (int)($actor['user_id'] ?? 0), [
                'reason'=>$reason,
                'platform_order'=>$platform,
                'rework_limit'=>1,
                'message'=>($platform ? '客户要求平台单补测/修改测量成果：' : '用户要求补测/修改测量图片成果：').$reason,
            ]);
        });
        return [
            'platform_measurement'=>$platform,
            'remaining_rework'=>0,
        ];
    }

    private function advancePlatformToConstruction(
        Database $db,
        array $order,
        string $actorType,
        int $actorId,
        bool $automatic,
        int $spaceCount,
        int $imageCount
    ): void {
        $db->execute(
            "UPDATE order_measurement_states
             SET result_status='accepted',submitted_at=COALESCE(submitted_at,UTC_TIMESTAMP()),accepted_at=UTC_TIMESTAMP(),auto_accept_at=NULL,
                 settlement_status='not_applicable',gross_amount_cents=0,refunded_amount_cents=0,service_rate_bp_snapshot=0,
                 service_min_cents_snapshot=0,service_fee_cents=0,worker_net_cents=0,hold_until=NULL,max_hold_until=NULL,settled_at=NULL,updated_at=UTC_TIMESTAMP()
             WHERE order_id=:order_id",
            ['order_id'=>$order['id']]
        );
        $db->execute(
            "UPDATE orders SET workflow_profile='construction',fulfillment_status='in_progress',completion_submitted_at=NULL,auto_accept_at=NULL,
                    accepted_at=NULL,closed_at=NULL,lifecycle_status='active',updated_at=UTC_TIMESTAMP(),version=version+1
             WHERE id=:id AND lifecycle_status='active'",
            ['id'=>$order['id']]
        );
        $this->event($db, (int)$order['id'], $automatic ? 'platform_measurement_auto_confirmed' : 'platform_measurement_confirmed', $actorType, $actorId, [
            'space_count'=>$spaceCount,
            'image_count'=>$imageCount,
            'automatic'=>$automatic,
            'message'=>$automatic
                ? '客户尚未绑定小程序，平台单测量成果自动确认，同一订单已进入施工阶段'
                : '客户已确认平台单测量成果，同一订单已自动进入施工阶段',
        ]);
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

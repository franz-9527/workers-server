<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderAppointmentRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 统一施工/维修预约持久化；平台测量转施工必须先由客户确认核价结果。 */
final class DatabaseOrderAppointmentRepository implements OrderAppointmentRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function save(array $actor, array $command): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        // assistance_* 只由已鉴权的管理员专用用例构造，绝不从预约请求 body 读取。
        $operatorId = (int)($actor['assistance_operator_id'] ?? 0);
        $assisted = $operatorId > 0 && $operatorId === (int)($actor['user_id'] ?? 0);
        $occurredAt = $assisted ? (string)($actor['assistance_occurred_at'] ?? '') : '';
        return $this->db->transaction(function (Database $db) use ($workerId,$command,$operatorId,$assisted,$occurredAt): array {
            $order = $db->fetch(
                'SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1 FOR UPDATE',
                ['public_no'=>(string)$command['order_id']]
            );
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);

            $contextType = (string)$command['context_type'];
            $afterSaleId = null;
            if ($contextType === 'repair') {
                // 保修维修通常发生在主订单已验收/完成之后。沿用旧 saveAppointment 语义：
                // 维修预约只要求本单成交师傅 + 当前维修申请仍有效，不要求主订单 lifecycle_status=active。
                if ((int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
                    throw new OrderOperationException('只有本单成交师傅可以提交维修预约', 'FORBIDDEN', 403);
                }
                $repair = $db->fetch(
                    "SELECT * FROM after_sales
                     WHERE order_id=:order_id AND public_no=:public_no AND type='warranty_repair'
                       AND status IN ('open','processing') LIMIT 1 FOR UPDATE",
                    ['order_id'=>$order['id'],'public_no'=>(string)$command['after_sale_id']]
                );
                if (!$repair) throw new OrderOperationException('维修申请不存在或已结束', 'REPAIR_NOT_ACTIVE', 409);
                $afterSaleId = (int)$repair['id'];
            } else {
                // 普通施工预约属于主订单履约，订单结束后不再允许新增/修改施工预约。
                if ((string)$order['lifecycle_status'] !== 'active') {
                    throw new OrderOperationException('订单已经结束，不能再提交预约', 'ORDER_NOT_ACTIVE', 409);
                }
                $assignment = $db->fetch(
                    "SELECT id FROM assignments
                     WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted'
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['order_id'=>$order['id'],'worker_id'=>$workerId]
                );
                if (!$assignment || (int)($order['assigned_worker_id'] ?? 0) !== $workerId) {
                    throw new OrderOperationException('请先接单后再操作', 'ASSIGNMENT_REQUIRED', 403);
                }
                if (in_array((string)($order['fulfillment_status'] ?? 'not_started'), ['submitted','accepted'], true)) {
                    throw new OrderOperationException('订单已提交完工或验收，不能再修改施工预约', 'APPOINTMENT_STAGE_INVALID', 409);
                }
                $this->assertPlatformPricingConfirmed($db, $order);
                OrderFulfillmentWriteGuard::assertAllowed($db, $order);
            }

            $waitCustomerNotice = !empty($command['wait_customer_notice']);
            $appointmentAt = $command['appointment_at_utc'] ?: null;
            $note = trim((string)($command['note'] ?? ''));
            $appointmentId = $db->insert(
                "INSERT INTO appointment_records(order_id,worker_id,appointment_at,note,status,context_type,after_sale_id,wait_customer_notice,created_at,updated_at)
                 VALUES(:order_id,:worker_id,:appointment_at,:note,'recorded',:context_type,:after_sale_id,:wait_notice,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                [
                    'order_id'=>$order['id'],'worker_id'=>$workerId,'appointment_at'=>$appointmentAt,'note'=>$note !== '' ? $note : null,
                    'context_type'=>$contextType,'after_sale_id'=>$afterSaleId,'wait_notice'=>$waitCustomerNotice ? 1 : 0,
                ]
            );

            $revealSql = "UPDATE order_worker_contact_reveal_requests
                          SET status='cancelled',review_note='师傅已提交预约或联系响应，无需公开电话',reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                          WHERE order_id=:order_id AND worker_id=:worker_id AND context_type=:context_type AND status='pending'";
            $revealParams = ['order_id'=>$order['id'],'worker_id'=>$workerId,'context_type'=>$contextType];
            if ($contextType === 'repair') {
                $revealSql .= ' AND after_sale_id=:after_sale_id';
                $revealParams['after_sale_id'] = $afterSaleId;
            } else {
                $revealSql .= ' AND after_sale_id IS NULL';
            }
            $db->execute($revealSql, $revealParams);

            if ($contextType === 'service') {
                // 2026-10-08：沿用预约/待通知提交作为已联系事实，不新增师傅端入口。
                if (!$assisted) (new DatabaseOrderCancellationRiskRepository($db))->recordExistingSignal((int)$order['id'], $workerId, 'contacted');
                $db->execute(
                    "UPDATE orders
                     SET fulfillment_status=IF(:has_appointment=1 AND fulfillment_status='not_started','scheduled',fulfillment_status),
                         updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                    ['id'=>$order['id'],'has_appointment'=>$appointmentAt === null ? 0 : 1]
                );
                $eventType = $waitCustomerNotice ? 'appointment_waiting_customer_notice' : 'appointment_recorded';
            } else {
                $db->execute('UPDATE orders SET updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id', ['id'=>$order['id']]);
                $eventType = $waitCustomerNotice ? 'repair_waiting_customer_notice' : 'repair_appointment_recorded';
            }

            $eventPayload = [
                'appointment_at'=>$appointmentAt,
                'context_type'=>$contextType,
                'after_sale_id'=>$afterSaleId,
                'wait_customer_notice'=>$waitCustomerNotice,
            ];
            if ($assisted) $eventPayload += [
                'operator_channel'=>'mini_admin','operator_user_id'=>$operatorId,
                'worker_id'=>$workerId,'occurred_at'=>$occurredAt,'assisted'=>true,
            ];
            $payload = json_encode($eventPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $eventId = $db->insert(
                "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,:event_type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
                [
                    'order_id'=>$order['id'],'event_type'=>$eventType,'actor_type'=>$assisted ? 'mini_admin' : 'worker',
                    'actor_id'=>$assisted ? $operatorId : $workerId,
                    'payload'=>is_string($payload) ? $payload : '{}',
                ]
            );
            \WorkerAdmin\Services\OrderNotificationService::queuePersistedEvent($db, (int)$order['id'], $eventId, $eventType, $eventPayload);

            return [
                'appointment_id'=>$appointmentId,
                'order_id'=>(string)$order['public_no'],
                'context_type'=>$contextType,
                'appointment_at'=>$appointmentAt,
                'wait_customer_notice'=>$waitCustomerNotice,
            ];
        });
    }

    private function assertPlatformPricingConfirmed(Database $db, array $order): void
    {
        if ((string)($order['order_source_type'] ?? '') !== 'platform'
            || (string)($order['workflow_profile'] ?? '') !== 'construction') {
            return;
        }
        $reviewed = (bool)$db->fetchValue(
            "SELECT id FROM order_events
             WHERE order_id=:order_id AND event_type='platform_measurement_pricing_reviewed'
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id']]
        );
        if (!$reviewed) return;
        $confirmed = (bool)$db->fetchValue(
            "SELECT id FROM order_events
             WHERE order_id=:order_id AND event_type='platform_measurement_pricing_confirmed'
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id']]
        );
        if (!$confirmed) {
            throw new OrderOperationException('客户尚未确认核价后的施工价格，请确认后再预约施工', 'PLATFORM_PRICING_CONFIRM_REQUIRED', 409);
        }
    }
}

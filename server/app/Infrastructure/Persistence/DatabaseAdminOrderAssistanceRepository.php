<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Application\Order\OrderAppointment;
use WorkerAdmin\Contracts\AdminOrderAssistanceRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 订单锁串行化补录与改派；预约仍由原领域命令执行，补录事件不额外发送通知。 */
final class DatabaseAdminOrderAssistanceRepository implements AdminOrderAssistanceRepository
{
    private Database $db;
    private OrderAppointment $appointment;

    public function __construct(Database $db, OrderAppointment $appointment)
    {
        $this->db = $db;
        $this->appointment = $appointment;
    }

    public function context(string $orderNo): array
    {
        return $this->db->transaction(function (Database $db) use ($orderNo): array {
            $order = $this->order($db, $orderNo);
            $assignment = $this->assignment($db, $order);
            $repairs = $assignment ? $db->fetchAll(
                "SELECT public_no FROM after_sales WHERE order_id=:id AND type='warranty_repair' AND status IN ('open','processing') ORDER BY id DESC",
                ['id'=>$order['id']]
            ) : [];
            $records = $db->fetchAll(
                "SELECT id,actor_id,payload_json,created_at FROM order_events WHERE order_id=:id AND event_type='admin_assistance_recorded' ORDER BY id DESC LIMIT 20",
                ['id'=>$order['id']]
            );
            return [
                'assignment_id'=>$assignment ? (string)$assignment['public_id'] : '',
                'can_record_contact'=>$assignment !== null,
                'can_record_service_appointment'=>$assignment !== null && (string)$order['lifecycle_status'] === 'active'
                    && !in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true),
                'repairs'=>array_map(static function (array $row): string { return (string)$row['public_no']; }, $repairs),
                'records'=>array_map(static function (array $row): array {
                    $payload = json_decode((string)$row['payload_json'], true);
                    return ['id'=>(int)$row['id'],'operator_user_id'=>(int)$row['actor_id'],
                        'recorded_at'=>(string)$row['created_at'],'fact'=>is_array($payload) ? $payload : []];
                }, $records),
            ];
        });
    }

    public function save(array $operator, array $command): array
    {
        $operatorId = (int)($operator['user_id'] ?? 0);
        if (($operator['channel'] ?? '') !== 'mini_admin' || $operatorId < 1) {
            throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        }
        return $this->db->transaction(function (Database $db) use ($operatorId,$command): array {
            $order = $this->order($db, (string)$command['order_id']);
            $assignment = $this->assignment($db, $order);
            if (!$assignment || (string)$assignment['public_id'] !== (string)$command['assignment_id']) {
                throw new OrderOperationException('当前师傅指派已变化，请刷新后补录', 'ASSIGNMENT_CHANGED', 409);
            }
            $eventKey = 'admin_assist:'.$order['id'].':'.$operatorId.':'.hash('sha256', (string)$command['request_id']);
            $fingerprint = hash('sha256', (string)json_encode($command, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $previous = $db->fetch('SELECT payload_json FROM order_events WHERE event_key=:key LIMIT 1', ['key'=>$eventKey]);
            if ($previous) {
                $payload = json_decode((string)$previous['payload_json'], true);
                if (!is_array($payload) || ($payload['fingerprint'] ?? '') !== $fingerprint) {
                    throw new OrderOperationException('本次请求内容已变化，请重新打开补录', 'ASSISTANCE_REQUEST_CONFLICT', 409);
                }
                return ['saved'=>true,'replayed'=>true];
            }
            $context = (string)$command['context_type'];
            if ($context === 'repair') {
                $repair = $db->fetch(
                    "SELECT id FROM after_sales WHERE order_id=:id AND BINARY public_no=BINARY :no AND type='warranty_repair' AND status IN ('open','processing') LIMIT 1 FOR UPDATE",
                    ['id'=>$order['id'],'no'=>$command['after_sale_id']]
                );
                if (!$repair) throw new OrderOperationException('维修申请已变化，请刷新后补录', 'REPAIR_NOT_ACTIVE', 409);
            }
            $action = (string)$command['action'];
            if (!in_array($action, ['contact_note','appointment'], true)) {
                throw new OrderOperationException('不支持该协助事项', 'ASSISTANCE_NOT_ALLOWED', 422);
            }
            $result = [];
            if ($action === 'appointment') {
                $result = $this->appointment->save([
                    'user_id'=>$operatorId, 'worker_id'=>(int)$assignment['worker_id'],
                    'assistance_operator_id'=>$operatorId, 'assistance_occurred_at'=>$command['occurred_at'],
                ], [
                    'order_id'=>$order['public_no'],'context_type'=>$context,
                    'after_sale_id'=>$command['after_sale_id'],'note'=>$command['note'],
                    'appointment_at'=>$command['appointment_at'],'wait_customer_notice'=>$command['wait_customer_notice'],
                ]);
            }
            $payload = [
                'fingerprint'=>$fingerprint,'action'=>$action,'context_type'=>$context,
                'assignment_id'=>$assignment['public_id'],'worker_id'=>(int)$assignment['worker_id'],
                'operator_channel'=>'mini_admin','operator_user_id'=>$operatorId,
                'after_sale_id'=>$context === 'repair' ? $command['after_sale_id'] : '',
                'occurred_at'=>$command['occurred_at'],'note'=>$command['note'],
                'appointment_id'=>(int)($result['appointment_id'] ?? 0),
                'appointment_at'=>$result['appointment_at'] ?? null,
                'wait_customer_notice'=>!empty($result['wait_customer_notice']),
            ];
            $db->insert(
                "INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'admin_assistance_recorded',:key,'mini_admin',:actor_id,:payload,UTC_TIMESTAMP())",
                ['order_id'=>$order['id'],'key'=>$eventKey,'actor_id'=>$operatorId,
                    'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
            return ['saved'=>true,'replayed'=>false];
        });
    }

    private function order(Database $db, string $orderNo): array
    {
        $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :no LIMIT 1 FOR UPDATE', ['no'=>$orderNo]);
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        return $order;
    }

    private function assignment(Database $db, array $order): ?array
    {
        if (!in_array((string)$order['lifecycle_status'], ['active','completed'], true)
            || (string)$order['assignment_status'] !== 'accepted') return null;
        return $db->fetch(
            "SELECT id,public_id,worker_id FROM assignments WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id AND status='accepted' LIMIT 1 FOR UPDATE",
            ['id'=>(int)($order['current_assignment_id'] ?? 0),'order_id'=>$order['id'],
                'worker_id'=>(int)($order['assigned_worker_id'] ?? 0)]
        );
    }
}

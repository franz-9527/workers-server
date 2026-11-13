<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderRepairInterventionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\RepairInterventionPolicy;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseOrderRepairInterventionRepository implements OrderRepairInterventionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function request(array $actor, string $orderNo, string $afterSaleNo, string $note): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$afterSaleNo,$note): array {
            $order = $db->fetch(
                'SELECT id,public_no,user_id,version FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE',
                ['public_no'=>$orderNo]
            );
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能为自己的维修申请平台介入', 'FORBIDDEN', 403);
            }

            $repair = $db->fetch(
                "SELECT id,public_no,status,resolution,opened_at
                 FROM after_sales
                 WHERE order_id=:order_id AND BINARY public_no=BINARY :public_no AND type='warranty_repair'
                 LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id'],'public_no'=>$afterSaleNo]
            );
            if (!$repair || !in_array((string)$repair['status'], ['open','processing'], true)) {
                throw new OrderOperationException('维修申请不存在或已结束', 'REPAIR_NOT_ACTIVE', 409);
            }

            $eligibility = RepairInterventionPolicy::evaluate(
                (string)$repair['status'],
                $repair['resolution'] === null ? null : (string)$repair['resolution'],
                $repair['opened_at'] === null ? null : (string)$repair['opened_at'],
                'UTC'
            );
            if (!$eligibility['eligible']) {
                if ((string)$eligibility['reason'] === 'already_escalated') {
                    throw new OrderOperationException('已经申请平台介入，请勿重复提交', 'REPAIR_INTERVENTION_ALREADY_REQUESTED', 409);
                }
                $availableAt = (string)($eligibility['available_at'] ?? '');
                throw new OrderOperationException(
                    $availableAt !== ''
                        ? '请先与师傅沟通处理；师傅未明确拒绝时，维修申请满 48 小时后（'.$availableAt.'）才可申请平台介入'
                        : '请先与师傅沟通处理；师傅明确拒绝或维修申请满 48 小时后才可申请平台介入',
                    'REPAIR_INTERVENTION_WAIT_REQUIRED',
                    409,
                    ['available_at'=>$availableAt,'wait_hours'=>RepairInterventionPolicy::WAIT_HOURS]
                );
            }

            $resolution = '用户申请平台介入'.($note !== '' ? '：'.$note : '');
            $db->execute(
                "UPDATE after_sales SET status='processing',resolution=:resolution,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['resolution'=>$resolution,'id'=>$repair['id']]
            );
            $db->execute(
                "UPDATE orders SET updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$order['id']]
            );

            $eventKey = 'repair_platform_intervention_requested:'.$order['id'].':'.bin2hex(random_bytes(6));
            $payload = [
                'after_sale_id'=>(string)$repair['public_no'],
                'message'=>'用户申请平台介入维修',
                'note'=>$note,
                'notify_recipients'=>['worker'],
            ];
            $db->insert(
                'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,\'user\',:actor_id,:payload,UTC_TIMESTAMP())',
                [
                    'order_id'=>$order['id'],'type'=>'repair_platform_intervention_requested','event_key'=>$eventKey,
                    'actor_id'=>$actor['user_id'],'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
            OrderNotificationService::queue($db, (int)$order['id'], $eventKey, 'repair_platform_intervention_requested', $payload);
            OrderNotificationService::queueAdminTask(
                $db,
                'after-sales:'.$repair['public_no'].':user-escalated',
                'repair_platform_intervention_requested',
                '用户申请平台介入维修',
                ['订单号'=>(string)$order['public_no'],'售后编号'=>(string)$repair['public_no'],'用户说明'=>$note]
            );

            return [
                'after_sale_id'=>(string)$repair['public_no'],
                'status'=>'processing',
                'intervention_reason'=>(string)$eligibility['reason'],
            ];
        });
    }
}

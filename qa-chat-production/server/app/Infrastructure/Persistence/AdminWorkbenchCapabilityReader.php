<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class AdminWorkbenchCapabilityReader
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<string,mixed> */
    public function forOrder(string $orderNo): array
    {
        $order = $this->db->fetch(
            "SELECT id,lifecycle_status,fulfillment_status,refund_status,current_assignment_id,assigned_worker_id,workflow_profile
             FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1",
            ['public_no'=>$orderNo]
        );
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);

        $assignment = !empty($order['current_assignment_id']) ? $this->db->fetch(
            "SELECT id,status FROM assignments WHERE id=:id AND order_id=:order_id LIMIT 1",
            ['id'=>$order['current_assignment_id'],'order_id'=>$order['id']]
        ) : null;
        $blockedMoney = !in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)
            || (bool)$this->db->fetchValue("SELECT 1 FROM refunds WHERE order_id=:id AND status NOT IN ('failed','closed','cancelled','rejected') LIMIT 1", ['id'=>$order['id']])
            || (bool)$this->db->fetchValue("SELECT 1 FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing') LIMIT 1", ['id'=>$order['id']])
            || (bool)$this->db->fetchValue("SELECT 1 FROM change_requests WHERE order_id=:id AND status IN ('pending','processing','negotiating') LIMIT 1", ['id'=>$order['id']]);
        $measurementLinked = (bool)$this->db->fetchValue(
            "SELECT 1 FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",
            ['id'=>$order['id']]
        );
        $canReassign = (string)$order['lifecycle_status'] === 'active'
            && !in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true)
            && !$blockedMoney && !$measurementLinked
            && $assignment && in_array((string)$assignment['status'], ['offered','accepted'], true);

        $repair = $this->db->fetch(
            "SELECT id,public_no,status,resolution FROM after_sales
             WHERE order_id=:id AND type='warranty_repair' AND status IN ('open','processing') ORDER BY id DESC LIMIT 1",
            ['id'=>$order['id']]
        );
        $canIntervene = false;
        if ($repair) {
            $already = strpos((string)($repair['resolution'] ?? ''), '平台主动介入：') === 0
                || strpos((string)($repair['resolution'] ?? ''), '用户申请平台介入') === 0
                || (bool)$this->db->fetchValue(
                    "SELECT 1 FROM order_events WHERE order_id=:order_id
                     AND event_type IN ('repair_platform_intervention_requested','repair_platform_intervention_started')
                     AND payload_json LIKE :needle LIMIT 1",
                    ['order_id'=>$order['id'],'needle'=>'%'.(string)$repair['public_no'].'%']
                );
            $canIntervene = !$already;
        }

        return [
            'can_reassign_worker'=>$canReassign,
            'reassign_reason'=>$canReassign ? '' : ($measurementLinked ? '关联测量施工仍绑定原测量师傅' : ($blockedMoney ? '当前存在取消、退款或费用调整事项' : '当前阶段不可直接改派')),
            'can_intervene_repair'=>$canIntervene,
            'active_repair_id'=>$repair ? (string)$repair['public_no'] : '',
        ];
    }
}

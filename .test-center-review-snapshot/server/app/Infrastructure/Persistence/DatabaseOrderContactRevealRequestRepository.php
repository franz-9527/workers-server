<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderContactRevealRequestRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * V2.5.0 联系电话公开申请。
 *
 * 正常履约只看客观事实：成交关系仍有效、等待期已满、师傅没有预约/联系响应，即自动批准。
 * 退款/取消/争议等风险状态才进入管理员异常队列；商家单保持客服保护策略。
 */
final class DatabaseOrderContactRevealRequestRepository implements OrderContactRevealRequestRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function request(array $actor, string $orderNo, string $reason): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$reason): array {
            $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE', ['public_no'=>$orderNo]);
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能为自己的订单申请公开电话', 'FORBIDDEN', 403);
            }
            if ((string)($order['order_source_type'] ?? 'consumer') === 'merchant') {
                throw new OrderOperationException('商家订单不直接公开师傅电话；争议处理确有需要时请联系客服协助核实', 'MERCHANT_CONTACT_PROTECTED', 403);
            }
            $workerId = (int)($order['assigned_worker_id'] ?? 0);
            if ($workerId < 1) throw new OrderOperationException('当前尚未安排成交师傅', 'WORKER_NOT_ASSIGNED', 409);

            $assignment = null;
            $currentAssignmentId = (int)($order['current_assignment_id'] ?? 0);
            if ($currentAssignmentId > 0) {
                $assignment = $db->fetch(
                    "SELECT id,worker_id,status,accepted_at,created_at FROM assignments
                     WHERE id=:id AND order_id=:order_id AND worker_id=:worker_id LIMIT 1 FOR UPDATE",
                    ['id'=>$currentAssignmentId,'order_id'=>$order['id'],'worker_id'=>$workerId]
                );
            }
            if (!$assignment) {
                $assignment = $db->fetch(
                    "SELECT id,worker_id,status,accepted_at,created_at FROM assignments
                     WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted'
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    ['order_id'=>$order['id'],'worker_id'=>$workerId]
                );
            }
            if (!$assignment || !in_array((string)$assignment['status'], ['accepted','offered'], true)) {
                throw new OrderOperationException('当前成交关系已经变化，请刷新订单', 'ASSIGNMENT_CHANGED', 409);
            }

            $repair = $db->fetch(
                "SELECT id,public_no,status,opened_at FROM after_sales
                 WHERE order_id=:order_id AND type='warranty_repair' AND status IN ('open','processing')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id']]
            );
            $context = $repair ? 'repair' : 'service';
            $afterSaleId = $repair ? (int)$repair['id'] : null;
            $completed = (string)$order['lifecycle_status'] === 'completed' || (string)$order['fulfillment_status'] === 'accepted';
            if ($completed && !$repair) {
                throw new OrderOperationException('订单已完成，师傅电话继续受隐私保护；如需维修请先提交维修申请', 'CONTACT_REVEAL_SERVICE_FINISHED', 409);
            }

            $anchor = $repair
                ? (string)($repair['opened_at'] ?? '')
                : ((string)($assignment['accepted_at'] ?? '') ?: (string)($assignment['created_at'] ?? ''));
            $anchorTs = $anchor !== '' ? strtotime($anchor.' UTC') : false;
            if ($anchorTs === false) throw new OrderOperationException('当前联系等待时间无法确认，请联系平台处理', 'CONTACT_REVEAL_ANCHOR_MISSING', 409);
            $waitHours = max(1, min(72, (int)\setting('order.worker_contact_reveal_wait_hours', 2)));
            $eligibleTs = $anchorTs + $waitHours * 3600;

            if ($this->hasAppointmentResponse($db, (int)$order['id'], $workerId, $context, $afterSaleId)) {
                throw new OrderOperationException('师傅已提交预约或联系响应，无需公开电话', 'WORKER_ALREADY_RESPONDED', 409);
            }

            $latest = $this->latestRequest($db, (int)$order['id'], $workerId, $context, $afterSaleId);
            if ($latest && (string)$latest['status'] === 'approved') {
                return [
                    'id'=>(string)$latest['public_no'],'status'=>'approved','auto_approved'=>true,'already_approved'=>true,
                    'message'=>'师傅联系电话已经公开，无需重复申请。',
                ];
            }
            if ($latest && (string)$latest['status'] === 'rejected' && !empty($latest['reviewed_at'])) {
                $reviewedTs = strtotime((string)$latest['reviewed_at'].' UTC');
                if ($reviewedTs !== false) $eligibleTs = max($eligibleTs, $reviewedTs + $waitHours * 3600);
            }
            if (time() < $eligibleTs) {
                throw new OrderOperationException('需等待师傅联系满'.$waitHours.'小时后才能申请公开电话', 'CONTACT_REVEAL_TOO_EARLY', 409, [
                    'eligible_at'=>gmdate('Y-m-d H:i:s',$eligibleTs),
                    'wait_hours'=>$waitHours,
                ]);
            }

            $manual = $this->requiresManualReview($db, $order);
            if ($latest && (string)$latest['status'] === 'pending') {
                if ($manual) {
                    return [
                        'id'=>(string)$latest['public_no'],'status'=>'pending','auto_approved'=>false,'already_pending'=>true,
                        'message'=>'当前订单存在退款、取消或争议状态，电话公开申请正在等待平台审核。',
                    ];
                }
                $db->execute(
                    "UPDATE order_worker_contact_reveal_requests
                     SET status='approved',reviewed_by=NULL,review_note='系统核验等待期已满且师傅无预约/联系响应，自动批准',reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='pending'",
                    ['id'=>$latest['id']]
                );
                $this->approvedEvent($db, $order, $latest, $context, $afterSaleId);
                return [
                    'id'=>(string)$latest['public_no'],'status'=>'approved','auto_approved'=>true,'recovered_pending'=>true,
                    'message'=>'师傅超过规定时间仍未响应，联系电话已自动公开。',
                ];
            }

            $publicNo = \public_id();
            if ($manual) {
                $db->insert(
                    "INSERT INTO order_worker_contact_reveal_requests(
                        public_no,order_id,user_id,worker_id,context_type,after_sale_id,reason,eligible_at,status,requested_at,created_at,updated_at
                     ) VALUES(
                        :public_no,:order_id,:user_id,:worker_id,:context,:after_sale_id,:reason,:eligible_at,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                     )",
                    [
                        'public_no'=>$publicNo,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],'worker_id'=>$workerId,
                        'context'=>$context,'after_sale_id'=>$afterSaleId,'reason'=>$reason !== '' ? $reason : null,
                        'eligible_at'=>gmdate('Y-m-d H:i:s',$eligibleTs),
                    ]
                );
                $this->appendEvent($db, (int)$order['id'], 'worker_contact_reveal_requested', 'user', (int)$actor['user_id'], [
                    'request_id'=>$publicNo,'context_type'=>$context,'after_sale_id'=>$afterSaleId,
                    'message'=>'订单存在异常状态，用户申请公开师傅电话并转平台审核',
                ]);
                OrderNotificationService::queueAdminTask(
                    $db,'contact-reveal:'.$publicNo,'worker_contact_reveal_requested','异常订单申请公开师傅电话',
                    ['订单号'=>$order['public_no'],'场景'=>$context==='repair'?'维修':'施工','申请原因'=>$reason]
                );
                return [
                    'id'=>$publicNo,'status'=>'pending','auto_approved'=>false,
                    'message'=>'当前订单存在退款、取消或争议状态，电话公开申请已转平台审核。',
                ];
            }

            $requestId = $db->insert(
                "INSERT INTO order_worker_contact_reveal_requests(
                    public_no,order_id,user_id,worker_id,context_type,after_sale_id,reason,eligible_at,status,requested_at,
                    reviewed_by,review_note,reviewed_at,created_at,updated_at
                 ) VALUES(
                    :public_no,:order_id,:user_id,:worker_id,:context,:after_sale_id,:reason,:eligible_at,'approved',UTC_TIMESTAMP(),
                    NULL,'系统核验等待期已满且师傅无预约/联系响应，自动批准',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_no'=>$publicNo,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],'worker_id'=>$workerId,
                    'context'=>$context,'after_sale_id'=>$afterSaleId,'reason'=>$reason !== '' ? $reason : null,
                    'eligible_at'=>gmdate('Y-m-d H:i:s',$eligibleTs),
                ]
            );
            $request = ['id'=>$requestId,'public_no'=>$publicNo];
            $this->approvedEvent($db, $order, $request, $context, $afterSaleId);
            return [
                'id'=>$publicNo,'status'=>'approved','auto_approved'=>true,
                'message'=>'师傅超过规定时间仍未响应，联系电话已自动公开。',
            ];
        });
    }

    private function requiresManualReview(Database $db, array $order): bool
    {
        if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) return true;
        if (in_array((string)($order['dispute_status'] ?? 'none'), ['negotiating','open','processing','pending'], true)) return true;
        return (bool)$db->fetchValue(
            "SELECT id FROM order_cancel_requests
             WHERE order_id=:order_id AND status IN ('pending','processing','approved') AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id']]
        );
    }

    private function hasAppointmentResponse(Database $db, int $orderId, int $workerId, string $context, ?int $afterSaleId): bool
    {
        $sql = "SELECT id FROM appointment_records WHERE order_id=:order_id AND worker_id=:worker_id AND context_type=:context";
        $params = ['order_id'=>$orderId,'worker_id'=>$workerId,'context'=>$context];
        if ($context === 'repair') {
            $sql .= ' AND after_sale_id=:after_sale_id';
            $params['after_sale_id'] = $afterSaleId ?: 0;
        } else {
            $sql .= ' AND after_sale_id IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        return (bool)$db->fetchValue($sql, $params);
    }

    /** @return array<string,mixed>|null */
    private function latestRequest(Database $db, int $orderId, int $workerId, string $context, ?int $afterSaleId): ?array
    {
        $sql = "SELECT * FROM order_worker_contact_reveal_requests
                WHERE order_id=:order_id AND worker_id=:worker_id AND context_type=:context";
        $params = ['order_id'=>$orderId,'worker_id'=>$workerId,'context'=>$context];
        if ($context === 'repair') {
            $sql .= ' AND after_sale_id=:after_sale_id';
            $params['after_sale_id'] = $afterSaleId ?: 0;
        } else {
            $sql .= ' AND after_sale_id IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT 1 FOR UPDATE';
        $row = $db->fetch($sql, $params);
        return $row ?: null;
    }

    private function approvedEvent(Database $db, array $order, array $request, string $context, ?int $afterSaleId): void
    {
        $eventKey = 'contact-reveal:'.(string)$request['public_no'].':approved';
        $payload = [
            'request_id'=>(string)$request['public_no'],'context_type'=>$context,'after_sale_id'=>$afterSaleId,
            'message'=>'师傅超过规定时间仍未提交预约/联系响应，系统已自动公开当前履约场景的联系电话。',
            'notify_recipients'=>['user'],
        ];
        $this->appendEvent($db, (int)$order['id'], 'worker_contact_reveal_approved', 'system', 0, $payload);
        OrderNotificationService::queue($db, (int)$order['id'], $eventKey, 'worker_contact_reveal_approved', $payload);
    }

    private function appendEvent(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:event_type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'event_type'=>$type,'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
    }
}

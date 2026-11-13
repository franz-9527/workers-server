<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminRepairResolutionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * 平台介入维修裁定。
 *
 * mini_admin 的 users.id 只写带类型的 order_events，不写 after_sales.handled_by；
 * web_admin 的 admin_users.id 与 handled_by 外键同域，可作为后台处理人落库。
 * 资金补偿/退款继续走退款与争议领域，不在本仓储混合处理。
 */
final class DatabaseAdminRepairResolutionRepository implements AdminRepairResolutionRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function resolve(
        array $operator,
        string $orderNo,
        string $afterSaleNo,
        string $decision,
        string $note
    ): array {
        $channel = trim((string)($operator['channel'] ?? ''));
        $operatorUserId = (int)($operator['user_id'] ?? 0);
        if (!in_array($channel, ['mini_admin','web_admin'], true) || $operatorUserId < 1) {
            throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
        }
        if (!in_array($decision, ['continue','resolved','closed'], true)) {
            throw new OrderOperationException('平台售后处理动作无效', 'REPAIR_PLATFORM_DECISION_INVALID', 422);
        }

        return $this->db->transaction(function (Database $db) use ($channel,$operatorUserId,$orderNo,$afterSaleNo,$decision,$note): array {
            $order = $db->fetch(
                'SELECT id,public_no,version FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE',
                ['public_no'=>$orderNo]
            );
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);

            $afterSale = $db->fetch(
                "SELECT * FROM after_sales
                 WHERE order_id=:order_id AND BINARY public_no=BINARY :public_no AND type='warranty_repair'
                 LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id'],'public_no'=>$afterSaleNo]
            );
            if (!$afterSale) {
                throw new OrderOperationException('维修售后不存在', 'REPAIR_NOT_FOUND', 404);
            }
            if (!in_array((string)$afterSale['status'], ['open','processing'], true)) {
                throw new OrderOperationException('该售后已结束，请刷新', 'REPAIR_PLATFORM_REVIEW_CHANGED', 409);
            }

            $currentResolution = (string)($afterSale['resolution'] ?? '');
            $alreadyContinued = strpos($currentResolution, '平台介入：要求继续维修：') === 0;
            $hasIntervention = strpos($currentResolution, '用户申请平台介入') === 0;
            if (!$hasIntervention) {
                $hasIntervention = (bool)$db->fetchValue(
                    "SELECT 1 FROM order_events
                     WHERE order_id=:order_id AND event_type='repair_platform_intervention_requested'
                       AND payload_json LIKE :needle
                     ORDER BY id DESC LIMIT 1 FOR UPDATE",
                    [
                        'order_id'=>$order['id'],
                        'needle'=>'%"after_sale_id":"'.$afterSaleNo.'"%',
                    ]
                );
            }
            if (!$hasIntervention) {
                throw new OrderOperationException(
                    '请先由用户按规则申请平台介入',
                    'REPAIR_PLATFORM_INTERVENTION_REQUIRED',
                    409
                );
            }
            if ($decision === 'continue' && $alreadyContinued) {
                throw new OrderOperationException(
                    '平台已要求继续维修，请等待处理后再确认解决或关闭售后',
                    'REPAIR_PLATFORM_CONTINUE_ALREADY_REQUESTED',
                    409
                );
            }

            $status = 'processing';
            $resolution = '平台介入：要求继续维修：'.$note;
            $eventType = 'repair_platform_continue';
            $message = '平台介入后要求师傅继续维修：'.$note;
            $closeNow = 0;
            if ($decision === 'resolved') {
                $status = 'resolved';
                $resolution = '平台裁定：问题已解决：'.$note;
                $eventType = 'repair_platform_resolved';
                $message = '平台已确认本次售后问题解决：'.$note;
                $closeNow = 1;
            } elseif ($decision === 'closed') {
                $status = 'closed';
                $resolution = '平台裁定：关闭售后：'.$note;
                $eventType = 'repair_platform_closed';
                $message = '平台已关闭本次售后：'.$note;
                $closeNow = 1;
            }

            $handledBy = $channel === 'web_admin' ? $operatorUserId : 0;
            $changed = $db->execute(
                "UPDATE after_sales
                 SET status=:status,resolution=:resolution,
                     handled_by=IF(:handled_by>0,:handled_by,handled_by),
                     closed_at=IF(:close_now=1,UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status IN ('open','processing')",
                [
                    'status'=>$status,
                    'resolution'=>$resolution,
                    'handled_by'=>$handledBy,
                    'close_now'=>$closeNow,
                    'id'=>$afterSale['id'],
                ]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('该售后状态已变化，请刷新', 'REPAIR_PLATFORM_REVIEW_CHANGED', 409);
            }

            if ($closeNow === 1) {
                if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
                    (new DatabaseChatRepairEpisodes($db,true))->close((int)$order['id'],(int)$afterSale['id']);
                }
                // 售后结束后，尚未处理的维修电话公开申请不再有业务上下文，直接关闭为 rejected。
                $db->execute(
                    "UPDATE order_worker_contact_reveal_requests
                     SET status='rejected',review_note='售后已由平台结案',reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE order_id=:order_id AND after_sale_id=:after_sale_id AND context_type='repair' AND status='pending'",
                    ['order_id'=>$order['id'],'after_sale_id'=>$afterSale['id']]
                );
            }

            $db->execute(
                'UPDATE orders SET version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                ['id'=>$order['id']]
            );

            $actorType = $channel === 'web_admin' ? 'admin' : 'mini_admin';
            $eventKey = $eventType.':'.$order['id'].':'.bin2hex(random_bytes(6));
            $payload = [
                'after_sale_id'=>$afterSaleNo,
                'decision'=>$decision,
                'note'=>$note,
                'message'=>$message,
                'operator_channel'=>$channel,
                'operator_user_id'=>$channel === 'mini_admin' ? $operatorUserId : null,
                'operator_admin_id'=>$channel === 'web_admin' ? $operatorUserId : null,
                'notify_recipients'=>['user','worker'],
            ];
            $db->insert(
                'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,:event_type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
                [
                    'order_id'=>$order['id'],
                    'event_type'=>$eventType,
                    'event_key'=>$eventKey,
                    'actor_type'=>$actorType,
                    'actor_id'=>$operatorUserId,
                    'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
            OrderNotificationService::queue($db, (int)$order['id'], $eventKey, $eventType, $payload);

            return [
                'order_id'=>(string)$order['public_no'],
                'after_sale_id'=>$afterSaleNo,
                'status'=>$status,
                'decision'=>$decision,
                'order_version'=>(int)$order['version'] + 1,
            ];
        });
    }
}

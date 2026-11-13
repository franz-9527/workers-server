<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderMeasurementHoldExtensionRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/**
 * V2.5.0 测量冻结延期仓储。
 *
 * 仅首次、后台自动天数以内、无资金/争议/取消风险且未关联施工单时自动批准；
 * 其他合法请求继续进入管理员审核。任何批准均不得突破订单 max_hold_until。
 */
final class DatabaseOrderMeasurementHoldExtensionRepository implements OrderMeasurementHoldExtensionRepository
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function request(array $actor, string $orderNo, int $days, string $reason): array
    {
        return $this->db->transaction(function (Database $db) use ($actor,$orderNo,$days,$reason): array {
            $order = $db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE', ['public_no'=>$orderNo]);
            if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
            if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
                throw new OrderOperationException('只能为自己的测量订单申请延期', 'FORBIDDEN', 403);
            }
            if ((string)($order['workflow_profile'] ?? 'construction') !== 'measurement') {
                throw new OrderOperationException('当前订单不是测量订单', 'NOT_MEASUREMENT_ORDER', 409);
            }

            $state = $db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$state
                || (string)$state['settlement_status'] !== 'frozen'
                || empty($state['hold_until'])
                || strtotime((string)$state['hold_until'].' UTC') <= time()) {
                throw new OrderOperationException('测量费已解冻或当前不能申请延期', 'MEASUREMENT_HOLD_INACTIVE', 409);
            }
            if (empty($state['max_hold_until'])) {
                throw new OrderOperationException('本单未配置最长冻结期', 'MEASUREMENT_HOLD_MAX_MISSING', 409);
            }
            if ($db->fetchValue(
                "SELECT id FROM order_relations
                 WHERE parent_order_id=:order_id AND relation_type='measurement_construction' AND status='active'
                 LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id']]
            )) {
                throw new OrderOperationException('测量结果已经关联施工订单，无需再延长优惠关联冻结期', 'MEASUREMENT_ALREADY_LINKED', 409);
            }
            if ($db->fetchValue(
                "SELECT id FROM order_measurement_hold_extensions
                 WHERE order_id=:order_id AND status='pending' LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id']]
            )) {
                throw new OrderOperationException('已有延期申请等待平台处理', 'MEASUREMENT_EXTENSION_PENDING', 409);
            }

            $oldTs = strtotime((string)$state['hold_until'].' UTC');
            $maxTs = strtotime((string)$state['max_hold_until'].' UTC');
            if ($oldTs === false || $maxTs === false) {
                throw new OrderOperationException('冻结期限数据异常，请联系平台处理', 'MEASUREMENT_HOLD_TIME_INVALID', 409);
            }
            $requestedTs = min($oldTs + $days * 86400, $maxTs);
            if ($requestedTs <= $oldTs) {
                throw new OrderOperationException('已经达到本单最长冻结期限，不能继续延期', 'MEASUREMENT_HOLD_MAX_REACHED', 409);
            }
            $requested = gmdate('Y-m-d H:i:s', $requestedTs);
            $historyCount = (int)$db->fetchValue(
                'SELECT COUNT(*) FROM order_measurement_hold_extensions WHERE order_id=:order_id',
                ['order_id'=>$order['id']]
            );
            $autoDays = max(0, min(30, $this->settings->int('measurement.auto_hold_extension_days', 5)));
            $risk = $this->hasManualReviewRisk($db, $order);
            $autoApprove = $historyCount === 0 && $autoDays > 0 && $days <= $autoDays && !$risk;
            $publicId = \public_id();

            if ($autoApprove) {
                $db->insert(
                    "INSERT INTO order_measurement_hold_extensions(
                        public_id,order_id,requested_by_user_id,old_hold_until,requested_hold_until,approved_hold_until,
                        reason,status,reviewed_by,review_note,reviewed_at,created_at,updated_at
                     ) VALUES(
                        :public_id,:order_id,:user_id,:old_hold,:requested,:approved,:reason,'approved',NULL,
                        '首次小范围延期符合自动规则，系统自动批准',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                     )",
                    [
                        'public_id'=>$publicId,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],
                        'old_hold'=>$state['hold_until'],'requested'=>$requested,'approved'=>$requested,'reason'=>$reason,
                    ]
                );
                $db->execute(
                    'UPDATE order_measurement_states SET hold_until=:hold_until,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id',
                    ['hold_until'=>$requested,'order_id'=>$order['id']]
                );
                $payload = [
                    'request_id'=>$publicId,'approved_hold_until'=>$requested,'extension_days'=>$days,
                    'message'=>'用户首次小范围申请延长测量冻结期，系统已按规则自动批准。',
                    'notify_recipients'=>['user','worker'],
                ];
                $this->appendEvent($db, (int)$order['id'], 'measurement_hold_extension_approved', 'system', 0, $payload);
                OrderNotificationService::queue(
                    $db,(int)$order['id'],'measurement-hold-extension:'.$publicId.':approved',
                    'measurement_hold_extension_approved',$payload
                );
                return [
                    'id'=>$publicId,'status'=>'approved','auto_approved'=>true,
                    'approved_hold_until'=>$requested,
                    'message'=>'首次小范围延期已自动批准，并已通知师傅。',
                ];
            }

            $db->insert(
                "INSERT INTO order_measurement_hold_extensions(
                    public_id,order_id,requested_by_user_id,old_hold_until,requested_hold_until,reason,status,created_at,updated_at
                 ) VALUES(
                    :public_id,:order_id,:user_id,:old_hold,:requested,:reason,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_id'=>$publicId,'order_id'=>$order['id'],'user_id'=>$actor['user_id'],
                    'old_hold'=>$state['hold_until'],'requested'=>$requested,'reason'=>$reason,
                ]
            );
            $payload = [
                'request_id'=>$publicId,'requested_hold_until'=>$requested,'extension_days'=>$days,'reason'=>$reason,
                'message'=>$risk
                    ? '订单存在退款、取消或争议状态，测量冻结延期申请转平台审核。'
                    : '本单不是首次小范围延期，申请转平台审核。',
            ];
            $this->appendEvent($db, (int)$order['id'], 'measurement_hold_extension_requested', 'user', (int)$actor['user_id'], $payload);
            OrderNotificationService::queueAdminTask(
                $db,'measurement-hold-extension:'.$publicId,'measurement_hold_extension_requested','测量冻结延期申请',
                ['订单号'=>(string)$order['public_no'],'申请延长'=>$days.'天','申请截止'=>$requested,'原因'=>$reason]
            );
            return [
                'id'=>$publicId,'status'=>'pending','auto_approved'=>false,'requested_hold_until'=>$requested,
                'message'=>'本次延期需要平台审核。',
            ];
        });
    }

    public function review(string $requestId, string $decision, string $note, array $operator): array
    {
        $channel = (string)($operator['channel'] ?? '');
        if ($channel === 'mini_admin') {
            $operatorId = max(0, (int)($operator['user_id'] ?? 0));
            $reviewedBy = null;
        } elseif ($channel === 'web_admin') {
            $operatorId = max(0, (int)($operator['admin_user_id'] ?? 0));
            $reviewedBy = $operatorId > 0 ? $operatorId : null;
        } else {
            $operatorId = 0;
            $reviewedBy = null;
        }
        if ($operatorId < 1) {
            throw new OrderOperationException('管理员操作来源无效', 'ADMIN_REQUIRED', 403);
        }

        return $this->db->transaction(function (Database $db) use ($requestId,$decision,$note,$channel,$operatorId,$reviewedBy): array {
            $request = $db->fetch(
                "SELECT e.*,o.public_no
                 FROM order_measurement_hold_extensions e
                 JOIN orders o ON o.id=e.order_id
                 WHERE BINARY e.public_id=BINARY :public_id
                 LIMIT 1 FOR UPDATE",
                ['public_id'=>$requestId]
            );
            if (!$request || (string)$request['status'] !== 'pending') {
                throw new OrderOperationException('延期申请不存在或已处理', 'MEASUREMENT_EXTENSION_CHANGED', 409);
            }

            $state = $db->fetch(
                'SELECT * FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE',
                ['order_id'=>$request['order_id']]
            );
            if (!$state
                || (string)$state['settlement_status'] !== 'frozen'
                || empty($state['hold_until'])
                || strtotime((string)$state['hold_until'].' UTC') <= time()) {
                throw new OrderOperationException('测量费已经解冻，不能再审核延期', 'MEASUREMENT_HOLD_INACTIVE', 409);
            }
            if (empty($state['max_hold_until'])) {
                throw new OrderOperationException('本单未配置最长冻结期', 'MEASUREMENT_HOLD_MAX_MISSING', 409);
            }

            if ($decision === 'approved') {
                $requestedTs = strtotime((string)$request['requested_hold_until'].' UTC');
                $maxTs = strtotime((string)$state['max_hold_until'].' UTC');
                $currentTs = strtotime((string)$state['hold_until'].' UTC');
                if ($requestedTs === false || $maxTs === false || $currentTs === false) {
                    throw new OrderOperationException('冻结期限数据异常，请刷新后重试', 'MEASUREMENT_HOLD_TIME_INVALID', 409);
                }
                $approvedTs = min($requestedTs, $maxTs);
                if ($approvedTs <= $currentTs) {
                    throw new OrderOperationException('批准后的时间必须晚于当前冻结截止时间', 'MEASUREMENT_EXTENSION_NO_LONGER_VALID', 409);
                }
                $approved = gmdate('Y-m-d H:i:s', $approvedTs);
                $db->execute(
                    'UPDATE order_measurement_states SET hold_until=:approved,updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id',
                    ['approved'=>$approved,'order_id'=>$request['order_id']]
                );
                $changed = $db->execute(
                    "UPDATE order_measurement_hold_extensions
                     SET status='approved',approved_hold_until=:approved,reviewed_by=:reviewed_by,review_note=:note,
                         reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status='pending'",
                    ['approved'=>$approved,'reviewed_by'=>$reviewedBy,'note'=>$note !== '' ? $note : null,'id'=>$request['id']]
                );
                if ($changed !== 1) {
                    throw new OrderOperationException('延期申请状态已变化，请刷新', 'MEASUREMENT_EXTENSION_CHANGED', 409);
                }
                $payload = [
                    'request_id'=>$requestId,
                    'approved_hold_until'=>$approved,
                    'message'=>'管理员已批准测量冻结延期',
                    'operator_channel'=>$channel,
                    'operator_user_id'=>$channel === 'mini_admin' ? $operatorId : null,
                    'operator_admin_id'=>$channel === 'web_admin' ? $operatorId : null,
                ];
                $this->appendEvent($db, (int)$request['order_id'], 'measurement_hold_extension_approved', $channel, $operatorId, $payload);
                return [
                    'status'=>'approved','order_id'=>(int)$request['order_id'],'approved_hold_until'=>$approved,
                    'message'=>'延期已批准',
                ];
            }

            $changed = $db->execute(
                "UPDATE order_measurement_hold_extensions
                 SET status='rejected',reviewed_by=:reviewed_by,review_note=:note,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status='pending'",
                ['reviewed_by'=>$reviewedBy,'note'=>$note,'id'=>$request['id']]
            );
            if ($changed !== 1) {
                throw new OrderOperationException('延期申请状态已变化，请刷新', 'MEASUREMENT_EXTENSION_CHANGED', 409);
            }
            $payload = [
                'request_id'=>$requestId,
                'reason'=>$note,
                'message'=>'管理员未批准测量冻结延期',
                'operator_channel'=>$channel,
                'operator_user_id'=>$channel === 'mini_admin' ? $operatorId : null,
                'operator_admin_id'=>$channel === 'web_admin' ? $operatorId : null,
            ];
            $this->appendEvent($db, (int)$request['order_id'], 'measurement_hold_extension_rejected', $channel, $operatorId, $payload);
            return [
                'status'=>'rejected','order_id'=>(int)$request['order_id'],'message'=>'延期申请已驳回',
            ];
        });
    }

    private function hasManualReviewRisk(Database $db, array $order): bool
    {
        if (!in_array((string)($order['refund_status'] ?? 'none'), ['', 'none'], true)) return true;
        if (in_array((string)($order['dispute_status'] ?? 'none'), ['negotiating','open','processing','pending'], true)) return true;
        if (!in_array((string)($order['lifecycle_status'] ?? 'active'), ['active','completed'], true)) return true;
        return (bool)$db->fetchValue(
            "SELECT id FROM order_cancel_requests
             WHERE order_id=:order_id AND status IN ('pending','processing','approved') AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id']]
        );
    }

    private function appendEvent(Database $db, int $orderId, string $type, string $actorType, int $actorId, array $payload): void
    {
        $db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id'=>$orderId,'type'=>$type,'actor_type'=>$actorType,'actor_id'=>$actorId > 0 ? $actorId : null,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
    }
}

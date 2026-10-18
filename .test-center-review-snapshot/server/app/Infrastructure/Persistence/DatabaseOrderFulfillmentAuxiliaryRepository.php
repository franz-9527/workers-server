<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderFulfillmentAuxiliaryRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Services\OrderNotificationService;

/** 成交后履约附属事实的 MySQL 实现。 */
final class DatabaseOrderFulfillmentAuxiliaryRepository implements OrderFulfillmentAuxiliaryRepository
{
    private Database $db;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, SettingsProvider $settings, PrivateValueCipher $cipher)
    {
        $this->db = $db;
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    public function reportArrival(array $actor, array $command): array
    {
        $order = $this->workOrder($actor, (string)$command['order_id']);
        $lat = (float)$command['lat'];
        $lng = (float)$command['lng'];
        if (is_numeric($order['lat'] ?? null) && is_numeric($order['lng'] ?? null)) {
            $distance = $this->distanceKm($lat, $lng, (float)$order['lat'], (float)$order['lng']);
            $maxDistance = max(0.1, min(20.0, (float)$this->settings->get('order.arrival_evidence_max_distance_km', 2)));
            if ($distance > $maxDistance) {
                throw new OrderOperationException(
                    '当前定位距离施工地址约 '.number_format($distance, 1).'km，超过取证允许范围，请到达现场后再提交',
                    'ARRIVAL_DISTANCE_TOO_FAR', 422
                );
            }
        }
        $evidence = $this->arrivalEvidence((int)$order['id'], $lat, $lng);
        $workerId = (int)$actor['worker_id'];

        $this->db->transaction(function (Database $db) use ($actor,$order,$workerId,$command,$lat,$lng,$evidence): void {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (string)$locked['lifecycle_status'] !== 'active' || (int)$locked['assigned_worker_id'] !== $workerId) {
                throw new OrderOperationException('订单状态已变化，请刷新后重试', 'ARRIVAL_ORDER_CHANGED', 409);
            }
            if (in_array((string)$locked['refund_status'], ['pending_review','approved','processing','refunding'], true)) {
                throw new OrderOperationException('订单正在审核退款，暂时不能继续施工操作', 'ORDER_REFUNDING', 409);
            }
            $db->insert(
                "INSERT INTO exceptional_arrivals(
                    order_id,worker_id,exception_type,description,lat,lng,evidence_media_ids_json,
                    watermark_metadata_json,arrived_at,created_at
                 ) VALUES(
                    :order_id,:worker_id,:type,:description,:lat,:lng,:evidence,:watermark,UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'order_id'=>$locked['id'],'worker_id'=>$workerId,'type'=>$command['exception_type'],
                    'description'=>$command['description'],'lat'=>$lat,'lng'=>$lng,
                    'evidence'=>json_encode(array_values(array_column($evidence['rows'], 'id')), JSON_UNESCAPED_UNICODE),
                    'watermark'=>json_encode([
                        'reported_lat'=>$lat,'reported_lng'=>$lng,'photos'=>$evidence['watermarks'],
                    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ]
            );
            (new DatabaseOrderCancellationRiskRepository($db))->recordExistingSignal(
                (int)$locked['id'], $workerId, 'complaint', (string)$command['description']
            );
            $this->event($db, (int)$locked['id'], 'exceptional_arrival_reported', 'worker', $workerId, [
                'type'=>$command['exception_type'],'evidence_count'=>count($evidence['rows']),
                'message'=>'师傅已提交带位置和时间水印的到场取证，等待平台裁决。',
            ]);
        });
        return ['evidence_count'=>count($evidence['rows'])];
    }

    public function saveMeasurementSpace(array $actor, array $command): array
    {
        $order = $this->workOrder($actor, (string)$command['order_id']);
        if ((string)($order['workflow_profile'] ?? 'construction') !== 'measurement') {
            throw new OrderOperationException('当前订单不是测量订单', 'NOT_MEASUREMENT_ORDER', 409);
        }
        return $this->db->transaction(function (Database $db) use ($order,$command): array {
            $state = $db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$state || (string)$state['result_status'] !== 'editing') {
                throw new OrderOperationException('测量成果已提交或锁定，当前不能修改空间', 'MEASUREMENT_RESULT_LOCKED', 409);
            }
            $spacePublicId = (string)$command['space_id'];
            if ($spacePublicId !== '') {
                $space = $db->fetch(
                    "SELECT id FROM order_measurement_spaces
                     WHERE BINARY public_id=BINARY :public_id AND order_id=:order_id AND status='active'
                     LIMIT 1 FOR UPDATE",
                    ['public_id'=>$spacePublicId,'order_id'=>$order['id']]
                );
                if (!$space) throw new OrderOperationException('测量空间不存在或已删除', 'MEASUREMENT_SPACE_NOT_FOUND', 404);
                $db->execute(
                    'UPDATE order_measurement_spaces SET name=:name,sort_order=:sort,updated_at=UTC_TIMESTAMP() WHERE id=:id',
                    ['name'=>$command['name'],'sort'=>$command['sort_order'],'id'=>$space['id']]
                );
                return ['id'=>$spacePublicId,'name'=>$command['name'],'sort_order'=>(int)$command['sort_order']];
            }
            $publicId = \public_id();
            $db->insert(
                "INSERT INTO order_measurement_spaces(public_id,order_id,name,sort_order,status,created_at,updated_at)
                 VALUES(:public_id,:order_id,:name,:sort,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['public_id'=>$publicId,'order_id'=>$order['id'],'name'=>$command['name'],'sort'=>$command['sort_order']]
            );
            return ['id'=>$publicId,'name'=>$command['name'],'sort_order'=>(int)$command['sort_order']];
        });
    }

    public function deleteMeasurementSpace(array $actor, string $orderPublicNo, string $spacePublicId): void
    {
        $order = $this->workOrder($actor, $orderPublicNo);
        if ((string)($order['workflow_profile'] ?? 'construction') !== 'measurement') {
            throw new OrderOperationException('当前订单不是测量订单', 'NOT_MEASUREMENT_ORDER', 409);
        }
        $this->db->transaction(function (Database $db) use ($order,$spacePublicId): void {
            $state = $db->fetch('SELECT result_status FROM order_measurement_states WHERE order_id=:order_id FOR UPDATE', ['order_id'=>$order['id']]);
            if (!$state || (string)$state['result_status'] !== 'editing') {
                throw new OrderOperationException('测量成果已提交或锁定，当前不能删除空间', 'MEASUREMENT_RESULT_LOCKED', 409);
            }
            $space = $db->fetch(
                "SELECT id FROM order_measurement_spaces
                 WHERE BINARY public_id=BINARY :public_id AND order_id=:order_id AND status='active'
                 LIMIT 1 FOR UPDATE",
                ['public_id'=>$spacePublicId,'order_id'=>$order['id']]
            );
            if (!$space) throw new OrderOperationException('测量空间不存在或已经删除', 'MEASUREMENT_SPACE_NOT_FOUND', 404);
            $mediaRows = $db->fetchAll(
                "SELECT m.id FROM media_links l JOIN media_files m ON m.id=l.media_file_id
                 WHERE l.entity_type='order_measurement_space' AND l.entity_id=:space_id FOR UPDATE",
                ['space_id'=>$space['id']]
            );
            $db->execute("DELETE FROM media_links WHERE entity_type='order_measurement_space' AND entity_id=:space_id", ['space_id'=>$space['id']]);
            foreach ($mediaRows as $media) {
                $db->execute(
                    "UPDATE media_files
                     SET reference_count=IF(reference_count>0,reference_count-1,0),
                         status=IF(reference_count<=1,'pending_delete',status),
                         pending_delete_at=IF(reference_count<=1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),pending_delete_at)
                     WHERE id=:id",
                    ['id'=>$media['id']]
                );
            }
            $db->execute("UPDATE order_measurement_spaces SET status='deleted',updated_at=UTC_TIMESTAMP() WHERE id=:id", ['id'=>$space['id']]);
        });
    }

    public function eligibleMeasurementLinks(array $actor): array
    {
        $userId = (int)($actor['user_id'] ?? 0);
        if ($userId < 1) return [];
        $rows = $this->db->fetchAll(
            "SELECT o.public_no,o.city,o.district,o.address_summary,o.lat,o.lng,
                    o.contact_name_ciphertext,o.contact_name_masked,o.contact_mobile_ciphertext,o.contact_mobile_masked,
                    o.final_amount_cents,oa.province,ms.hold_until,w.display_name worker_name
             FROM orders o
             JOIN order_measurement_states ms ON ms.order_id=o.id
             JOIN worker_profiles w ON w.id=o.assigned_worker_id
             LEFT JOIN order_addresses oa ON oa.order_id=o.id
             LEFT JOIN order_relations r ON r.parent_order_id=o.id
                    AND r.relation_type='measurement_construction' AND r.status='active'
             WHERE o.user_id=:user_id AND o.workflow_profile='measurement'
               AND o.fulfillment_status='accepted' AND ms.result_status='accepted'
               AND ms.settlement_status='frozen' AND ms.hold_until IS NOT NULL AND ms.hold_until>UTC_TIMESTAMP()
               AND r.id IS NULL
             ORDER BY ms.accepted_at DESC,o.id DESC LIMIT 30",
            ['user_id'=>$userId]
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'=>(string)$row['public_no'],'worker_name'=>(string)$row['worker_name'],
                'city'=>(string)$row['city'],'district'=>(string)$row['district'],
                'address_summary'=>(string)$row['address_summary'],'measurement_amount_cents'=>(int)$row['final_amount_cents'],
                'hold_until'=>$row['hold_until'],
                'address'=>[
                    'province'=>(string)($row['province'] ?? ''),'city'=>(string)$row['city'],'district'=>(string)$row['district'],
                    'detail'=>(string)$row['address_summary'],'lat'=>$row['lat'] === null ? null : (float)$row['lat'],
                    'lng'=>$row['lng'] === null ? null : (float)$row['lng'],
                    'contact_name'=>$this->cipher->decrypt((string)($row['contact_name_ciphertext'] ?? ''), 'order-contact-name') ?: (string)$row['contact_name_masked'],
                    'contact_mobile'=>$this->cipher->decrypt((string)($row['contact_mobile_ciphertext'] ?? ''), 'order-contact-mobile') ?: (string)$row['contact_mobile_masked'],
                ],
            ];
        }
        return $items;
    }

    public function createRepair(array $actor, array $command): array
    {
        $order = $this->resolveOrder((string)$command['order_id']);
        if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能为自己的订单申请售后维修', 'FORBIDDEN', 403);
        }
        return $this->db->transaction(function (Database $db) use ($actor,$order,$command): array {
            $locked = $db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE', ['id'=>$order['id']]);
            if (!$locked || (int)$locked['user_id'] !== (int)$actor['user_id']) {
                throw new OrderOperationException('订单不存在或无权操作', 'FORBIDDEN', 403);
            }
            if ((string)$locked['fulfillment_status'] !== 'accepted' && (string)$locked['lifecycle_status'] !== 'completed') {
                throw new OrderOperationException('订单验收完成后才能申请售后维修', 'REPAIR_ORDER_NOT_COMPLETED', 409);
            }
            if (empty($locked['assigned_worker_id'])) {
                throw new OrderOperationException('该订单没有成交师傅，请直接联系平台客服处理', 'REPAIR_WORKER_MISSING', 409);
            }
            if ($db->fetchValue(
                "SELECT id FROM after_sales
                 WHERE order_id=:id AND type='warranty_repair' AND status IN ('open','processing')
                 LIMIT 1 FOR UPDATE",
                ['id'=>$locked['id']]
            )) {
                throw new OrderOperationException('该订单已有售后申请正在处理中', 'REPAIR_ALREADY_OPEN', 409);
            }

            $evidenceIds = (array)$command['evidence_ids'];
            $mediaIds = [];
            if ($evidenceIds) {
                $holders = [];
                $params = ['order_id'=>$locked['id']];
                foreach ($evidenceIds as $index=>$mediaPublicId) {
                    $key = 'm'.$index;
                    $holders[] = ':'.$key;
                    $params[$key] = $mediaPublicId;
                }
                $rows = $db->fetchAll(
                    "SELECT m.id FROM media_files m JOIN media_links l ON l.media_file_id=m.id
                     WHERE l.entity_type='order' AND l.entity_id=:order_id AND l.purpose='repair_evidence'
                       AND m.public_id IN (".implode(',', $holders).") AND m.status='active'",
                    $params
                );
                if (count($rows) !== count($evidenceIds)) {
                    throw new OrderOperationException('维修证据图片无效，请重新上传后提交', 'REPAIR_EVIDENCE_INVALID', 422);
                }
                $mediaIds = array_map('intval', array_column($rows, 'id'));
            }

            $publicId = \public_id();
            $db->insert(
                "INSERT INTO after_sales(
                    public_no,order_id,user_id,type,description,warranty_months_snapshot,warranty_expires_at_snapshot,
                    non_human_confirmed,evidence_media_ids_json,status,opened_at,created_at,updated_at
                 ) VALUES(
                    :public_no,:order_id,:user_id,'warranty_repair',:description,:months,:expires,
                    :non_human,:evidence,'processing',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()
                 )",
                [
                    'public_no'=>$publicId,'order_id'=>$locked['id'],'user_id'=>$actor['user_id'],'description'=>$command['description'],
                    'months'=>!empty($locked['warranty_months']) ? (int)$locked['warranty_months'] : null,
                    'expires'=>$locked['warranty_expires_at'] ?: null,'non_human'=>!empty($command['non_human_confirmed']) ? 1 : 0,
                    'evidence'=>$mediaIds ? json_encode($mediaIds, JSON_UNESCAPED_UNICODE) : null,
                ]
            );
            $db->execute('UPDATE orders SET updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id', ['id'=>$locked['id']]);

            if (!$db->fetchValue('SELECT id FROM reviews WHERE order_id=:id LIMIT 1', ['id'=>$locked['id']])) {
                if ((new DatabaseReviewRepository($db))->createAutoForOrder((int)$locked['id'], '系统自动评价')) {
                    $this->event($db, (int)$locked['id'], 'review_auto_created', 'system', null, [
                        'message'=>'订单进入维修流程，系统已生成默认评价','attitude_rating'=>5,'craft_rating'=>5,
                    ]);
                }
                $db->execute(
                    "UPDATE order_deadlines SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                     WHERE order_id=:order_id AND deadline_type='auto_review' AND status IN ('pending','blocked','claimed')",
                    ['order_id'=>$locked['id']]
                );
            }
            $this->event($db, (int)$locked['id'], 'warranty_repair_requested', 'user', (int)$actor['user_id'], [
                'message'=>'用户提交售后维修申请，订单已自动进入维修中并分配给原成交师傅。',
                'after_sale_id'=>$publicId,'warranty_expires_at'=>$locked['warranty_expires_at'],
            ]);
            return ['id'=>$publicId];
        });
    }

    public function withdrawRepair(array $actor, string $orderPublicNo, string $afterSalePublicNo): void
    {
        $order = $this->resolveOrder($orderPublicNo);
        if ((int)$order['user_id'] !== (int)($actor['user_id'] ?? 0)) {
            throw new OrderOperationException('只能撤回自己的维修申请', 'FORBIDDEN', 403);
        }
        $this->db->transaction(function (Database $db) use ($actor,$order,$afterSalePublicNo): void {
            $repair = $db->fetch(
                "SELECT * FROM after_sales
                 WHERE order_id=:order_id AND BINARY public_no=BINARY :public_no AND type='warranty_repair'
                 LIMIT 1 FOR UPDATE",
                ['order_id'=>$order['id'],'public_no'=>$afterSalePublicNo]
            );
            if (!$repair || !in_array((string)$repair['status'], ['open','processing'], true)) {
                throw new OrderOperationException('维修申请不存在或已经结束', 'REPAIR_NOT_ACTIVE', 409);
            }
            $db->execute(
                "UPDATE after_sales
                 SET status='closed',resolution='用户已撤回维修申请',closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id",
                ['id'=>$repair['id']]
            );
            $db->execute(
                "UPDATE order_worker_contact_reveal_requests
                 SET status='cancelled',review_note='维修申请已撤回',reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:order_id AND context_type='repair' AND after_sale_id=:after_sale_id AND status='pending'",
                ['order_id'=>$order['id'],'after_sale_id'=>$repair['id']]
            );
            $db->execute('UPDATE orders SET updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id', ['id'=>$order['id']]);
            $this->event($db, (int)$order['id'], 'warranty_repair_withdrawn', 'user', (int)$actor['user_id'], [
                'after_sale_id'=>$repair['public_no'],'message'=>'用户已撤回维修申请',
            ]);
        });
    }

    /** @return array<string,mixed> */
    private function resolveOrder(string $publicNo): array
    {
        $order = $this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1', ['public_no'=>trim($publicNo)]);
        if (!$order) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        return $order;
    }

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    private function workOrder(array $actor, string $publicNo): array
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if ($workerId < 1) throw new OrderOperationException('你还不是师傅用户', 'WORKER_REQUIRED', 403);
        $order = $this->resolveOrder($publicNo);
        $assignment = $this->db->fetch(
            "SELECT id FROM assignments
             WHERE order_id=:order_id AND worker_id=:worker_id AND status='accepted'
             ORDER BY id DESC LIMIT 1",
            ['order_id'=>$order['id'],'worker_id'=>$workerId]
        );
        if (!$assignment || (string)$order['lifecycle_status'] !== 'active') {
            throw new OrderOperationException('请先接单后再操作', 'ASSIGNMENT_REQUIRED', 403);
        }
        if (in_array((string)$order['refund_status'], ['pending_review','approved','processing','refunding'], true)) {
            throw new OrderOperationException('订单正在审核退款，暂时不能继续施工操作', 'ORDER_REFUNDING', 409);
        }
        return $order;
    }

    /** @return array{rows:array<int,array<string,mixed>>,watermarks:array<int,array<string,mixed>>} */
    private function arrivalEvidence(int $orderId, float $reportedLat, float $reportedLng): array
    {
        $rows = $this->db->fetchAll(
            "SELECT mf.id,mf.public_id,mf.metadata_json
             FROM media_links ml JOIN media_files mf ON mf.id=ml.media_file_id AND mf.status='active'
             WHERE ml.entity_type='order' AND ml.entity_id=:order_id AND ml.purpose='arrival_evidence'
             ORDER BY ml.sort_order,ml.id",
            ['order_id'=>$orderId]
        );
        if (!$rows) {
            throw new OrderOperationException('请先上传带位置和时间水印的到场取证照片', 'ARRIVAL_EVIDENCE_REQUIRED', 422);
        }
        $watermarks = [];
        foreach ($rows as $row) {
            $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
            $lat = is_array($metadata) && is_numeric($metadata['captured_lat'] ?? null) ? (float)$metadata['captured_lat'] : null;
            $lng = is_array($metadata) && is_numeric($metadata['captured_lng'] ?? null) ? (float)$metadata['captured_lng'] : null;
            if (!is_array($metadata) || empty($metadata['watermarked']) || trim((string)($metadata['captured_at'] ?? '')) === ''
                || trim((string)($metadata['location_text'] ?? '')) === '' || !$this->validCoordinate($lat, $lng)) {
                throw new OrderOperationException(
                    '到场取证照片必须带有位置和时间水印及有效坐标，请重新上传',
                    'ARRIVAL_EVIDENCE_WATERMARK_REQUIRED', 422
                );
            }
            if ($this->distanceKm($reportedLat, $reportedLng, (float)$lat, (float)$lng) > 1.0) {
                throw new OrderOperationException('提交定位与取证照片坐标不一致，请重新拍摄现场照片', 'ARRIVAL_EVIDENCE_LOCATION_MISMATCH', 422);
            }
            $watermarks[] = [
                'id'=>$row['public_id'],'captured_at'=>$metadata['captured_at'] ?? null,'lat'=>$lat,'lng'=>$lng,
                'location_text'=>$metadata['location_text'] ?? '',
            ];
        }
        return ['rows'=>$rows,'watermarks'=>$watermarks];
    }

    /** @param array<string,mixed> $payload */
    private function event(Database $db, int $orderId, string $type, string $actorType, ?int $actorId, array $payload): void
    {
        $eventKey = $type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $db->insert(
            'INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',
            [
                'order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>$actorType,'actor_id'=>$actorId,
                'payload'=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]
        );
        OrderNotificationService::queue($db, $orderId, $eventKey, $type, $payload);
    }

    private function validCoordinate(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

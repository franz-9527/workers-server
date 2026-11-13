<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Application\Order\OrderAppointment;
use WorkerAdmin\Application\Order\OrderFulfillment;
use WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary;
use WorkerAdmin\Application\Order\OrderNegotiation;
use WorkerAdmin\Contracts\AdminOrderAssistanceMediaRepository;
use WorkerAdmin\Contracts\AdminOrderAssistanceRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 订单锁串行化补录与改派；预约仍由原领域命令执行，补录事件不额外发送通知。 */
final class DatabaseAdminOrderAssistanceRepository implements AdminOrderAssistanceRepository, AdminOrderAssistanceMediaRepository
{
    private Database $db;
    private OrderAppointment $appointment;

    private ?OrderFulfillment $fulfillment;
    private ?OrderFulfillmentAuxiliary $auxiliary;
    private ?OrderNegotiation $negotiation;
    private ?DatabaseMediaRepository $media;

    public function __construct(Database $db, OrderAppointment $appointment, ?OrderFulfillment $fulfillment = null,
        ?OrderFulfillmentAuxiliary $auxiliary = null, ?OrderNegotiation $negotiation = null, ?DatabaseMediaRepository $media = null)
    {
        $this->db = $db;
        $this->appointment = $appointment;
        $this->fulfillment=$fulfillment; $this->auxiliary=$auxiliary; $this->negotiation=$negotiation; $this->media=$media;
    }

    public function context(string $orderNo): array
    {
        return $this->db->transaction(function (Database $db) use ($orderNo): array {
            $order = $this->order($db, $orderNo);
            $assignment = $this->assignment($db, $order);
            $repairs = $assignment ? $db->fetchAll(
                "SELECT * FROM after_sales WHERE order_id=:id AND type='warranty_repair' AND status IN ('open','processing') ORDER BY id DESC",
                ['id'=>$order['id']]
            ) : [];
            $records = $db->fetchAll(
                "SELECT id,actor_id,payload_json,created_at FROM order_events WHERE order_id=:id AND event_type='admin_assistance_recorded' ORDER BY id DESC LIMIT 20",
                ['id'=>$order['id']]
            );
            $editable = $assignment !== null && (string)$order['lifecycle_status']==='active'
                && !in_array((string)$order['fulfillment_status'], ['submitted','accepted'], true);
            if ($editable) {
                try { OrderFulfillmentWriteGuard::assertAllowed($db,$order); }
                catch (OrderOperationException $exception) { $editable=false; }
            }
            $measurement=null; $spaces=[]; $media=[];
            if ($this->media !== null) {
                $measurement=$db->fetch('SELECT result_status,service_payment_status FROM order_measurement_states WHERE order_id=:id', ['id'=>$order['id']]);
                $spaces=$db->fetchAll("SELECT public_id id,name,sort_order FROM order_measurement_spaces WHERE order_id=:id AND status='active' ORDER BY sort_order,id",['id'=>$order['id']]);
                $media=$db->fetchAll("SELECT m.public_id id,l.purpose,l.entity_type,l.entity_id,s.public_id space_id,m.metadata_json,l.created_by_type
                    FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active'
                    LEFT JOIN order_measurement_spaces s ON l.entity_type='order_measurement_space' AND s.id=l.entity_id
                    WHERE (l.entity_type='order' AND l.entity_id=:id AND l.purpose IN ('before_optional','completion','fulfillment_document','collection_material','repair_result'))
                        OR (l.entity_type='order_measurement_space' AND s.order_id=:id AND s.status='active') ORDER BY l.id",['id'=>$order['id']]);
            }
            $measurementEditable=$editable && (string)($order['workflow_profile'] ?? '')==='measurement' && $measurement
                && (string)$measurement['result_status']==='editing'
                && ((string)($order['order_source_type'] ?? '')==='platform' || (string)$measurement['service_payment_status']==='paid');
            return [
                'order_db_id'=>(int)$order['id'],
                'can_edit_measurement'=>(bool)$measurementEditable,
                'can_submit_measurement'=>(bool)$measurementEditable,
                'can_submit_completion'=>$editable && (string)($order['workflow_profile'] ?? 'construction')==='construction',
                'can_upload_fulfillment'=>$editable && (string)($order['workflow_profile'] ?? 'construction')==='construction',
                'can_upload_collection_material'=>$assignment !== null,
                'repair_results'=>array_values(array_map(static function(array $row): string { return (string)$row['public_no']; },
                    array_filter($repairs,static function(array $row): bool {
                        return !preg_match('/^(用户申请平台介入|平台主动介入|师傅拒绝维修)/u',(string)($row['resolution'] ?? ''));
                    }))),
                'spaces'=>$spaces,'media'=>$media,
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
            [$order,$assignment,$eventKey,$fingerprint,$previous] = $this->lockedContext($db,$operatorId,$command);
            if ($previous !== null) return ['saved'=>true,'replayed'=>true,'result'=>$previous['result'] ?? []];
            $context = (string)$command['context_type'];
            if ($context === 'repair') {
                $repair = $db->fetch(
                    "SELECT id FROM after_sales WHERE order_id=:id AND BINARY public_no=BINARY :no AND type='warranty_repair' AND status IN ('open','processing') LIMIT 1 FOR UPDATE",
                    ['id'=>$order['id'],'no'=>$command['after_sale_id']]
                );
                if (!$repair) throw new OrderOperationException('维修申请已变化，请刷新后补录', 'REPAIR_NOT_ACTIVE', 409);
            }
            $action = (string)$command['action'];
            if (!in_array($action, ['contact_note','appointment','measurement_space','space_delete','measurement_submit','completion_submit','repair_result','media_delete'], true)) {
                throw new OrderOperationException('不支持该协助事项', 'ASSISTANCE_NOT_ALLOWED', 422);
            }
            $result = [];
            $actor=['user_id'=>$operatorId,'worker_id'=>(int)$assignment['worker_id'],
                'assistance_operator_id'=>$operatorId,'assistance_occurred_at'=>$command['occurred_at'],
                'assistance_assignment_id'=>$assignment['public_id']];
            if (in_array($action,['measurement_space','space_delete','measurement_submit'],true)) $this->assertMeasurementEditing($db,$order);
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
            if ($action === 'measurement_space' || $action === 'space_delete') {
                if (!$this->auxiliary) throw new OrderOperationException('履约服务不可用','ASSISTANCE_SERVICE_UNAVAILABLE',503);
                $route=$action==='measurement_space' ? 'orders.measurement.space.save' : 'orders.measurement.space.delete';
                $response=$this->auxiliary->execute($route,$actor,$command);
                $result=$response['data'] ?? [];
            }
            if ($action === 'measurement_submit' || $action === 'completion_submit') {
                if (!$this->fulfillment) throw new OrderOperationException('履约服务不可用','ASSISTANCE_SERVICE_UNAVAILABLE',503);
                if ($action==='completion_submit' && (string)($order['workflow_profile'] ?? '')!=='construction') {
                    throw new OrderOperationException('请通过测量成果入口提交','NOT_CONSTRUCTION_ORDER',409);
                }
                $response=$this->fulfillment->execute($action==='measurement_submit' ? 'orders.measurement.submit' : 'fulfillment.complete',
                    $actor,['order_id'=>$order['public_no'],'description'=>$command['note']]);
                $result=$response['data'] ?? [];
            }
            if ($action === 'repair_result') {
                $this->repair($db,$order,(string)$command['after_sale_id']);
                if (!$this->negotiation) throw new OrderOperationException('维修服务不可用','ASSISTANCE_SERVICE_UNAVAILABLE',503);
                $response=$this->negotiation->execute('after_sales.repair.respond',$actor,
                    ['order_id'=>$order['public_no'],'after_sale_id'=>$command['after_sale_id'],'decision'=>'complete','note'=>$command['note']]);
                $result=$response['data'] ?? [];
            }
            if ($action === 'media_delete') {
                $this->assertMediaStage($db,$order,$command);
                if (!$this->media) throw new OrderOperationException('图片服务不可用','ASSISTANCE_SERVICE_UNAVAILABLE',503);
                $this->media->deleteAssistedMedia($order,$command,(string)$assignment['public_id']);
            }
            $payload = [
                'result'=>$result,'space_id'=>$command['space_id'] ?? '',
                'media_id'=>$command['media_id'] ?? '',
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
            return ['saved'=>true,'replayed'=>false,'result'=>$result];
        });
    }

    /** 首次权限检查不持有压缩和云上传期间的订单锁。 */
    public function prepareMedia(array $operator, array $command): ?array
    {
        $operatorId=$this->operatorId($operator);
        return $this->db->transaction(function(Database $db) use($operatorId,$command): ?array {
            [$order,$assignment,$key,$fingerprint,$previous]=$this->lockedContext($db,$operatorId,$command);
            if ($previous !== null) return ($previous['result'] ?? []) + ['replayed'=>true];
            $this->assertMediaStage($db,$order,$command);
            return null;
        });
    }

    public function storeMedia(array $operator, array $command, array $optimized): array
    {
        $operatorId=$this->operatorId($operator);
        return $this->db->transaction(function(Database $db) use($operatorId,$command,$optimized): array {
            [$order,$assignment,$key,$fingerprint,$previous]=$this->lockedContext($db,$operatorId,$command);
            if ($previous !== null) return ($previous['result'] ?? []) + ['replayed'=>true];
            $this->assertMediaStage($db,$order,$command);
            if (!$this->media) throw new OrderOperationException('图片服务不可用','ASSISTANCE_SERVICE_UNAVAILABLE',503);
            $publicId=$this->media->newPublicId();
            $purpose=(string)$command['purpose'];
            $metadata=['assisted'=>true,'operator_channel'=>'mini_admin','operator_user_id'=>$operatorId,
                'worker_id'=>(int)$assignment['worker_id'],'assistance_assignment_id'=>$assignment['public_id'],
                'occurred_at'=>$command['occurred_at'],'after_sale_id'=>$command['after_sale_id'],
                'watermarked'=>false,'purpose'=>$purpose];
            $entityType='order'; $entityId=(int)$order['id'];
            if (strpos($purpose,'measurement_')===0) {
                $space=$db->fetch("SELECT * FROM order_measurement_spaces WHERE order_id=:id AND BINARY public_id=BINARY :space AND status='active' LIMIT 1 FOR UPDATE",
                    ['id'=>$order['id'],'space'=>$command['space_id']]);
                if (!$space) throw new OrderOperationException('请选择当前测量空间','MEASUREMENT_SPACE_NOT_FOUND',409);
                $entityType='order_measurement_space'; $entityId=(int)$space['id'];
                $this->media->storeMeasurementMedia(['order'=>$order,'space'=>$space,'purpose'=>$purpose,
                    'worker_id'=>$assignment['worker_id'],'creator_type'=>'mini_admin','creator_id'=>$operatorId,'metadata'=>$metadata],$optimized,$publicId);
            } else {
                $this->media->storeOrderMedia(['order'=>$order,'purpose'=>$purpose,'image_rule'=>['max'=>6],
                    'is_admin'=>true,'creator_type'=>'mini_admin','creator_id'=>$operatorId],$optimized,$metadata,$publicId);
            }
            $result=['id'=>$publicId,'entity_type'=>$entityType,'entity_id'=>$entityId,'order_id'=>(int)$order['id'],'purpose'=>$purpose];
            $this->recordMedia($db,$operatorId,$order,$assignment,$command,$key,$fingerprint,$result);
            return $result + ['replayed'=>false];
        });
    }

    public function markMediaStored(string $mediaId, string $key): bool
    {
        return $this->media !== null && $this->media->markStoredOnQiniu($mediaId,$key);
    }

    private function recordMedia(Database $db,int $operatorId,array $order,array $assignment,array $command,string $key,string $fingerprint,array $result): void
    {
        $payload=['fingerprint'=>$fingerprint,'action'=>'upload_media','purpose'=>$command['purpose'],
            'assignment_id'=>$assignment['public_id'],'worker_id'=>(int)$assignment['worker_id'],
            'operator_channel'=>'mini_admin','operator_user_id'=>$operatorId,'occurred_at'=>$command['occurred_at'],
            'note'=>$command['note'],'after_sale_id'=>$command['after_sale_id'],'space_id'=>$command['space_id'],'result'=>$result];
        $db->insert("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at)
            VALUES(:order_id,'admin_assistance_recorded',:key,'mini_admin',:actor,:payload,UTC_TIMESTAMP())",
            ['order_id'=>$order['id'],'key'=>$key,'actor'=>$operatorId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    private function operatorId(array $operator): int
    {
        $id=(int)($operator['user_id'] ?? 0);
        if (($operator['channel'] ?? '')!=='mini_admin' || $id<1) throw new OrderOperationException('管理员身份无效','ADMIN_REQUIRED',403);
        return $id;
    }

    private function lockedContext(Database $db,int $operatorId,array $command): array
    {
        $order=$this->order($db,(string)$command['order_id']);
        $assignment=$this->assignment($db,$order);
        if (!$assignment || (string)$assignment['public_id']!==(string)$command['assignment_id']) {
            throw new OrderOperationException('当前师傅指派已变化，请刷新后办理','ASSIGNMENT_CHANGED',409);
        }
        $key='admin_assist:'.$order['id'].':'.$operatorId.':'.hash('sha256',(string)$command['request_id']);
        $fingerprint=hash('sha256',(string)json_encode($command,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $previous=$db->fetch('SELECT payload_json FROM order_events WHERE event_key=:key LIMIT 1',['key'=>$key]);
        $payload=$previous ? json_decode((string)$previous['payload_json'],true) : null;
        if ($previous && (!is_array($payload) || ($payload['fingerprint'] ?? '')!==$fingerprint)) {
            throw new OrderOperationException('本次请求内容已变化，请重新打开办理','ASSISTANCE_REQUEST_CONFLICT',409);
        }
        return [$order,$assignment,$key,$fingerprint,$payload];
    }

    private function assertMeasurementEditing(Database $db,array $order): void
    {
        OrderFulfillmentWriteGuard::assertAllowed($db,$order);
        $state=$db->fetch('SELECT result_status,service_payment_status FROM order_measurement_states WHERE order_id=:id FOR UPDATE',['id'=>$order['id']]);
        if ((string)$order['lifecycle_status']!=='active' || (string)$order['workflow_profile']!=='measurement'
            || !$state || (string)$state['result_status']!=='editing'
            || ((string)$order['order_source_type']!=='platform' && (string)$state['service_payment_status']!=='paid')) {
            throw new OrderOperationException('测量成果已提交、锁定或服务款未付清','MEASUREMENT_RESULT_LOCKED',409);
        }
    }

    private function assertMediaStage(Database $db,array $order,array $command): void
    {
        $purpose=(string)($command['purpose'] ?? '');
        if ($purpose==='repair_result') { $this->repair($db,$order,(string)$command['after_sale_id']); return; }
        if (strpos($purpose,'measurement_')===0) { $this->assertMeasurementEditing($db,$order); return; }
        if ($purpose==='collection_material' && ($command['action'] ?? '')!=='media_delete') return;
        OrderFulfillmentWriteGuard::assertAllowed($db,$order);
        if (!in_array($purpose,['before_optional','completion','fulfillment_document','collection_material'],true)
            || (string)$order['lifecycle_status']!=='active'
            || in_array((string)$order['fulfillment_status'],['submitted','accepted'],true)
            || (string)$order['workflow_profile']!=='construction') {
            throw new OrderOperationException('当前阶段不能整理履约图片','ASSISTANCE_MEDIA_LOCKED',409);
        }
    }

    private function repair(Database $db,array $order,string $publicNo): array
    {
        $repair=$db->fetch("SELECT * FROM after_sales WHERE order_id=:id AND BINARY public_no=BINARY :no AND type='warranty_repair' AND status IN ('open','processing') LIMIT 1 FOR UPDATE",['id'=>$order['id'],'no'=>$publicNo]);
        if (!$repair || preg_match('/^(用户申请平台介入|平台主动介入|师傅拒绝维修)/u',(string)($repair['resolution'] ?? ''))) {
            throw new OrderOperationException('维修已结束或需要平台裁定，请刷新','REPAIR_NOT_ACTIVE',409);
        }
        return $repair;
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

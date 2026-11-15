<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 平台单测量阶段装饰器；公共施工事实由外层 RepairGuardedPlatformOrderAdminRepository 统一写入。 */
final class MeasurementAwarePlatformOrderAdminRepository implements PlatformOrderAdminRepository
{
    private PlatformOrderAdminRepository $inner;
    private Database $db;

    public function __construct(PlatformOrderAdminRepository $inner, Database $db){$this->inner=$inner;$this->db=$db;}

    public function dashboard(): array
    {
        $data=$this->inner->dashboard();
        foreach(($data['rows']??[]) as $index=>$row){
            $names=$this->db->fetchAll('SELECT category_name_snapshot FROM order_categories WHERE order_id=:id ORDER BY sort_order,category_id',['id'=>(int)$row['id']]);
            if($names)$data['rows'][$index]['category_names_snapshot']=implode('、',array_values(array_filter(array_map(static fn(array $item):string=>trim((string)($item['category_name_snapshot']??'')),$names))));
        }
        return $data;
    }

    public function create(array $command, int $adminId): int
    {
        $orderId = $this->inner->create($command, $adminId);
        if ((string)($command['fulfillment_mode'] ?? 'direct_install') !== 'measure_then_install') {
            return $orderId;
        }

        $this->db->transaction(function (Database $db) use ($orderId,$adminId,$command): void {
            $order = $db->fetch("SELECT id,order_source_type,workflow_profile,lifecycle_status FROM orders WHERE id=:id FOR UPDATE", ['id'=>$orderId]);
            $state = $db->fetch("SELECT order_id,result_status FROM order_measurement_states WHERE order_id=:id FOR UPDATE", ['id'=>$orderId]);
            if (!$order || (string)$order['order_source_type'] !== 'platform' || !$state) {
                throw new OrderOperationException('平台测量阶段初始化不完整，请刷新后重试。', 'PLATFORM_MEASUREMENT_INIT_FAILED', 500);
            }
            if ((string)$order['lifecycle_status'] !== 'active') {
                throw new OrderOperationException('平台订单状态已变化，不能进入测量阶段。', 'PLATFORM_MEASUREMENT_INIT_STATE_CHANGED', 409);
            }
            if ((string)$state['result_status'] !== 'editing') {
                throw new OrderOperationException('平台测量成果状态异常，请刷新后重试。', 'PLATFORM_MEASUREMENT_INIT_STATE_CHANGED', 409);
            }

            $db->execute(
                "UPDATE orders SET workflow_profile='measurement',fulfillment_status='not_started',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['id'=>$orderId]
            );
            // 平台测量没有独立测量费；paid 仅表示测量工具无需再等待普通测量服务款。
            $db->execute(
                "UPDATE order_measurement_states
                 SET service_payment_status='paid',settlement_status='not_applicable',gross_amount_cents=0,refunded_amount_cents=0,
                     service_rate_bp_snapshot=0,service_min_cents_snapshot=0,service_fee_cents=0,worker_net_cents=0,
                     hold_until=NULL,max_hold_until=NULL,settled_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE order_id=:id",
                ['id'=>$orderId]
            );
            $payload = json_encode([
                'operator_channel'=>(string)($command['operator_channel'] ?? 'web_admin'),
                'operator_user_id'=>(int)($command['operator_user_id'] ?? 0),
                'mode'=>'measure_then_install',
                'same_order'=>true,
                'message'=>'平台单已进入测量前置阶段，测量确认后原订单直接进入施工',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $db->insert(
                "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
                 VALUES(:order_id,'platform_measurement_stage_activated',:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
                [
                    'order_id'=>$orderId,
                    'actor_type'=>($command['operator_channel'] ?? '') === 'mini_admin' ? 'mini_admin' : 'admin',
                    'actor_id'=>($command['operator_channel'] ?? '') === 'mini_admin' ? (int)$command['operator_user_id'] : ($adminId > 0 ? $adminId : null),
                    'payload'=>is_string($payload) ? $payload : '{}',
                ]
            );
        });
        return$orderId;
    }

    public function publish(int $orderId,int $adminId,int $quoteDays):void{$this->inner->publish($orderId,$adminId,$quoteDays);}
    public function selectQuote(int $orderId,int $quoteId,int $adminId):void{$this->inner->selectQuote($orderId,$quoteId,$adminId);}
    public function createInvite(int $orderId,int $adminId,int $validDays):array{return$this->inner->createInvite($orderId,$adminId,$validDays);}
    public function revokeInvite(int $orderId,int $inviteId,int $adminId):void{$this->inner->revokeInvite($orderId,$inviteId,$adminId);}
}

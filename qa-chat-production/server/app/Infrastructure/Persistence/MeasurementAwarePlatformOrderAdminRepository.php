<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 平台单公共施工事实 + 测量阶段装饰器。 */
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
        $this->alignCommonFacts($orderId, $command);
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

    /** @param array<string,mixed> $command */
    private function alignCommonFacts(int $orderId,array $command):void
    {
        $ids=is_array($command['category_ids']??null)?array_values(array_unique(array_filter(array_map('intval',$command['category_ids']),static fn(int $id):bool=>$id>0))):[];
        if(!$ids&&isset($command['category_id']))$ids=[(int)$command['category_id']];
        $ids=array_slice($ids,0,6);
        if(!$ids)throw new OrderOperationException('平台承接单缺少有效服务类目。','PLATFORM_CATEGORY_REQUIRED',422);
        $answers=is_array($command['answers']??null)?$command['answers']:[];
        $this->db->transaction(function(Database $db)use($orderId,$ids,$answers):void{
            $order=$db->fetch("SELECT id,order_source_type FROM orders WHERE id=:id FOR UPDATE",['id'=>$orderId]);
            if(!$order||(string)$order['order_source_type']!=='platform')throw new OrderOperationException('平台承接单不存在或来源异常。','PLATFORM_ORDER_NOT_FOUND',404);
            $schemaVersionId=(int)$db->fetchValue("SELECT v.id FROM form_schema_versions v INNER JOIN form_schemas s ON s.id=v.schema_id WHERE BINARY s.schema_key=BINARY 'order_submit' AND v.status='published' ORDER BY v.version_no DESC,v.id DESC LIMIT 1");
            if($schemaVersionId<1)throw new OrderOperationException('当前没有已发布的普通下单表单，平台承接单不能继续创建。','FORM_NOT_PUBLISHED',503);
            $marks=[];$params=[];foreach($ids as $index=>$id){$marks[]=':c'.$index;$params['c'.$index]=$id;}
            $rows=$db->fetchAll("SELECT id,name FROM service_categories WHERE parent_id IS NULL AND status='active' AND id IN (".implode(',',$marks).')',$params);
            $by=[];foreach($rows as $row)$by[(int)$row['id']]=$row;
            $categories=[];foreach($ids as $id)if(isset($by[$id]))$categories[]=$by[$id];
            if(count($categories)!==count($ids))throw new OrderOperationException('服务类目已变化，请刷新后重新选择。','PLATFORM_CATEGORY_NOT_FOUND',422);
            $primary=$categories[0];$names=implode('、',array_map(static fn(array $row):string=>(string)$row['name'],$categories));
            $db->execute("UPDATE orders SET form_schema_version_id=:schema,category_id=:category,category_name_snapshot=:primary_name,category_names_snapshot=:all_names,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['schema'=>$schemaVersionId,'category'=>(int)$primary['id'],'primary_name'=>(string)$primary['name'],'all_names'=>$names,'id'=>$orderId]);
            $db->execute('DELETE FROM order_categories WHERE order_id=:order_id',['order_id'=>$orderId]);
            foreach($categories as $index=>$category)$db->insert("INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at) VALUES(:order_id,:category_id,:name,:sort,UTC_TIMESTAMP())",['order_id'=>$orderId,'category_id'=>(int)$category['id'],'name'=>(string)$category['name'],'sort'=>$index]);
            (new PlatformOrderCommonFactsWriter($db))->write($orderId,$schemaVersionId,$ids,$answers);
        });
    }

    public function publish(int $orderId,int $adminId,int $quoteDays):void{$this->inner->publish($orderId,$adminId,$quoteDays);}
    public function selectQuote(int $orderId,int $quoteId,int $adminId):void{$this->inner->selectQuote($orderId,$quoteId,$adminId);}
    public function createInvite(int $orderId,int $adminId,int $validDays):array{return$this->inner->createInvite($orderId,$adminId,$validDays);}
    public function revokeInvite(int $orderId,int $inviteId,int $adminId):void{$this->inner->revokeInvite($orderId,$inviteId,$adminId);}
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;

/** 通用订单异常任务只读来源；只从现有业务事实派生任务。 */
final class OrderExceptionWorkbenchTaskSource
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function tasks(?int $onlyOrderId=null):array{return $this->tasksByOrderIds($onlyOrderId===null?null:[$onlyOrderId]);}
    public function tasksForOrderIds(array $orderIds):array{$ids=$this->normalizeOrderIds($orderIds);return $ids?$this->tasksByOrderIds($ids):[];}

    private function tasksByOrderIds(?array $orderIds):array
    {
        $tasks=[];$filter=$this->orderFilter($orderIds);

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,cr.public_id source_id,cr.reason,cr.requested_refund_cents,cr.requested_at task_created_at FROM order_cancel_requests cr JOIN orders o ON o.id=cr.order_id WHERE cr.status='pending' AND cr.reason_code NOT IN ('bilateral_refund_pending','bilateral_refund_rejected','bilateral_refund_execution_required')".$filter);
        foreach($rows as $row){$amount=number_format(((int)$row['requested_refund_cents'])/100,2,'.','');$summary=trim((string)$row['reason']);if((int)$row['requested_refund_cents']>0)$summary.=($summary!==''?' · ':'').'申请退款 ¥'.$amount;$task=$this->task($row,'refund_review','cancel_request',(string)$row['source_id'],'退款申请待审核',$summary!==''?$summary:'用户申请取消并退款','P0',true,'decision',['refund_approve','refund_reject']);$task['facts']=['requested_refund_cents'=>max(0,(int)$row['requested_refund_cents'])];$tasks[]=$task;}

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,cr.public_id source_id,cr.reason,cr.requested_refund_cents,cr.updated_at task_created_at FROM order_cancel_requests cr JOIN orders o ON o.id=cr.order_id WHERE cr.status='pending' AND cr.reason_code='bilateral_refund_execution_required'".$filter);
        foreach($rows as $row){$amount=number_format(((int)$row['requested_refund_cents'])/100,2,'.','');$summary='双方已接受退款方案';if((int)$row['requested_refund_cents']>0)$summary.=' · 协商金额 ¥'.$amount;$reason=trim((string)$row['reason']);if($reason!=='')$summary.=' · '.$reason;$summary.=' · 请到 Web 后台核对线上可退金额、平台工费、跑空费、已入账余额等资金事实后执行';$tasks[]=$this->task($row,'refund_execution_attention','cancel_request',(string)$row['source_id'],'双方已同意退款，待财务核账执行',$summary,'P0',true,'attention',[]);}

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,d.public_no source_id,d.title,d.description,d.status,d.dispute_type,d.refund_amount_cents,d.run_empty_fee_cents,d.opened_at task_created_at FROM disputes d JOIN orders o ON o.id=d.order_id WHERE d.status IN ('open','processing')".$filter);
        foreach($rows as $row){$summary=trim((string)($row['description']?:$row['title']));$needsWeb=(int)$row['refund_amount_cents']>0||(int)$row['run_empty_fee_cents']>0;if($needsWeb)$summary.=($summary!==''?' · ':'').'涉及金额处理，请到 Web 后台裁定';$task=$this->task($row,'dispute_open','dispute',(string)$row['source_id'],$needsWeb?'订单争议需 Web 后台处理':'订单争议待处理',$summary!==''?$summary:'订单存在未结争议','P0',true,$needsWeb?'attention':'decision',$needsWeb?[]:['dispute_resolve']);$task['facts']=['refund_amount_cents'=>max(0,(int)$row['refund_amount_cents']),'run_empty_fee_cents'=>max(0,(int)$row['run_empty_fee_cents'])];$tasks[]=$task;}

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,r.public_no source_id,r.context_type,r.reason,r.eligible_at,r.requested_at task_created_at,w.display_name worker_name,af.public_no after_sale_public_no FROM order_worker_contact_reveal_requests r JOIN orders o ON o.id=r.order_id JOIN worker_profiles w ON w.id=r.worker_id LEFT JOIN after_sales af ON af.id=r.after_sale_id WHERE r.status='pending' AND COALESCE(o.order_source_type,'consumer')<>'merchant' AND o.assigned_worker_id=r.worker_id AND r.eligible_at<=UTC_TIMESTAMP() AND ((r.context_type='service' AND r.after_sale_id IS NULL AND o.lifecycle_status<>'completed' AND o.fulfillment_status<>'accepted') OR (r.context_type='repair' AND af.id IS NOT NULL AND af.type='warranty_repair' AND af.status IN ('open','processing')))".$filter);
        foreach($rows as $row){$context=(string)$row['context_type']==='repair'?'维修':'施工';$summary=$context.'场景 · '.((string)$row['worker_name']?:'当前师傅');if(!empty($row['reason']))$summary.=' · '.trim((string)$row['reason']);$tasks[]=$this->task($row,'contact_reveal_review','contact_reveal',(string)$row['source_id'],'师傅电话公开申请',$summary,'P0',false,'decision',['contact_reveal_approve','contact_reveal_reject']);}

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,e.public_id source_id,e.reason,e.requested_hold_until,e.created_at task_created_at FROM order_measurement_hold_extensions e JOIN orders o ON o.id=e.order_id JOIN order_measurement_states ms ON ms.order_id=o.id WHERE e.status='pending' AND ms.settlement_status='frozen' AND ms.hold_until IS NOT NULL AND ms.hold_until>UTC_TIMESTAMP()".$filter);
        foreach($rows as $row){$summary=trim((string)$row['reason']);if(!empty($row['requested_hold_until']))$summary.=($summary!==''?' · ':'').'申请延至 '.(string)$row['requested_hold_until'];$tasks[]=$this->task($row,'measurement_hold_review','measurement_hold',(string)$row['source_id'],'测量冻结延期待审核',$summary!==''?$summary:'用户申请延长测量费冻结期','P1',true,'decision',['measurement_hold_approve','measurement_hold_reject']);}

        $rows=$this->db->fetchAll(
            "SELECT o.id order_id,o.public_no order_public_no,o.version order_version,af.public_no source_id,af.description,af.resolution,af.opened_at task_created_at
             FROM after_sales af JOIN orders o ON o.id=af.order_id
             WHERE af.type='warranty_repair' AND af.status IN ('open','processing')
               AND (
                    af.resolution LIKE '用户申请平台介入%'
                    OR af.resolution LIKE '平台主动介入：%'
                    OR EXISTS(
                        SELECT 1 FROM order_events oe WHERE oe.order_id=af.order_id
                          AND oe.event_type IN ('repair_platform_intervention_requested','repair_platform_intervention_started')
                          AND oe.payload_json LIKE CONCAT('%\"after_sale_id\":\"',af.public_no,'\"%')
                    )
               )".$filter
        );
        foreach($rows as $row){$summary=trim((string)($row['resolution']?:$row['description']));$alreadyContinued=strpos((string)($row['resolution']??''),'平台介入：要求继续维修：')===0;$allowedActions=$alreadyContinued?['repair_platform_resolve','repair_platform_close']:['repair_platform_continue','repair_platform_resolve','repair_platform_close'];$tasks[]=$this->task($row,'after_sale_intervention','after_sale',(string)$row['source_id'],$alreadyContinued?'售后平台介入处理中':'售后平台介入待处理',$summary!==''?$summary:'平台已介入，请根据售后事实作出处理','P1',true,'decision',$allowedActions);}

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,r.public_no source_id,r.failure_message,r.updated_at task_created_at FROM refunds r JOIN orders o ON o.id=r.order_id WHERE r.status='failed'".$filter);
        foreach($rows as $row)$tasks[]=$this->task($row,'refund_execution_attention','refund',(string)$row['source_id'],'退款执行异常',trim((string)($row['failure_message']?:'微信退款执行失败，需要到 Web 后台核对')),'P0',true,'attention',[]);
        return $tasks;
    }

    private function normalizeOrderIds(array $orderIds):array{$ids=array_values(array_unique(array_filter(array_map('intval',$orderIds),static fn(int $id):bool=>$id>0)));sort($ids,SORT_NUMERIC);return $ids;}
    private function orderFilter(?array $orderIds):string{if($orderIds===null)return '';if(!$orderIds)return ' AND 1=0';return ' AND o.id IN ('.implode(',',array_map('intval',$orderIds)).')';}
    private function task(array $row,string $type,string $sourceType,string $sourceId,string $title,string $summary,string $priority,bool $blocking,string $mode,array $allowedActions):array{$createdAt=(string)($row['task_created_at']??'');$createdTs=$createdAt!==''?strtotime($createdAt.' UTC'):false;return ['_order_id'=>(int)$row['order_id'],'task_id'=>$type.':'.$sourceId,'task_type'=>$type,'source_type'=>$sourceType,'source_id'=>$sourceId,'title'=>$title,'summary'=>$summary,'priority'=>$priority,'tone'=>$priority==='P0'?'danger':($priority==='P1'?'warning':'info'),'created_at'=>$createdAt,'waiting_seconds'=>$createdTs===false?0:max(0,time()-$createdTs),'blocking'=>$blocking,'mode'=>$mode,'allowed_actions'=>array_values($allowedActions),'order_version'=>(int)($row['order_version']??0)];}
}

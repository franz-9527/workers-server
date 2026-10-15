<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;

final class PlatformOrderWorkbenchTaskSource
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function tasks(?int $onlyOrderId=null):array{return $this->tasksByOrderIds($onlyOrderId===null?null:[$onlyOrderId]);}
    public function tasksForOrderIds(array $orderIds):array{$ids=$this->normalizeOrderIds($orderIds);return $ids?$this->tasksByOrderIds($ids):[];}

    private function tasksByOrderIds(?array $orderIds):array
    {
        $tasks=[];$filter=$this->orderFilter($orderIds);
        $managed="t.customer_flow_mode='platform_managed' AND t.settlement_status='pending' AND t.customer_contract_total_cents>0 AND t.booking_paid_cents<=t.customer_contract_total_cents AND o.booking_fee_cents=0 AND NOT EXISTS(SELECT 1 FROM payment_orders pp WHERE pp.order_id=o.id AND pp.business_type IN ('order_booking','booking_fee') AND pp.status='paid')";

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,ms.accepted_at task_created_at FROM orders o JOIN order_measurement_states ms ON ms.order_id=o.id JOIN order_platform_terms t ON t.order_id=o.id WHERE o.order_source_type='platform' AND {$managed} AND o.workflow_profile='measurement' AND o.lifecycle_status='active' AND ms.result_status='accepted' AND COALESCE(o.refund_status,'none') IN ('','none') AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing')) AND NOT EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id AND r.status NOT IN ('failed','closed','cancelled','rejected','succeeded')) AND NOT EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing')) AND NOT EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND c.status IN ('pending','processing','negotiating')) AND NOT EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing'))".$filter);
        foreach($rows as $row)$tasks[]=$this->task($row,'platform_pricing_review','order',(string)$row['order_public_no'],'平台单测量后待核价','测量成果已确认，请核定实际数量、客户最终金额和师傅施工工费后进入施工。','decision',['platform_pricing_review']);

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,f.id source_id,f.error_message,f.created_at task_created_at FROM scheduled_job_failures f JOIN (SELECT business_key,MAX(id) latest_id FROM scheduled_job_failures WHERE job_name='platform_auto_publish' AND resolved_at IS NULL GROUP BY business_key) latest ON latest.latest_id=f.id JOIN orders o ON f.business_key=CONCAT('order:',o.id) JOIN order_platform_terms t ON t.order_id=o.id WHERE f.job_name='platform_auto_publish' AND f.resolved_at IS NULL AND o.order_source_type='platform' AND {$managed} AND o.lifecycle_status='active' AND o.publish_status<>'published' AND COALESCE(o.refund_status,'none') IN ('','none') AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing')) AND NOT EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id AND r.status NOT IN ('failed','closed','cancelled','rejected')) AND NOT EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing')) AND NOT EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND c.status IN ('pending','processing','negotiating')) AND NOT EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing'))".$filter);
        foreach($rows as $row)$tasks[]=$this->task($row,'platform_auto_publish_failed','scheduler_failure',(string)$row['source_id'],'平台单自动发布失败',trim((string)($row['error_message']?:'托管条件已满足，但系统自动审核/发布失败。手动发布仅可作为异常修复入口。')),'attention',[],'P0');

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,o.updated_at task_created_at FROM orders o JOIN order_platform_terms t ON t.order_id=o.id WHERE o.order_source_type='platform' AND {$managed} AND o.lifecycle_status='active' AND o.publish_status<>'published' AND o.audit_status IN ('pending','approved') AND COALESCE(o.refund_status,'none') IN ('','none') AND o.selected_quote_id IS NULL AND o.assigned_worker_id IS NULL AND o.current_assignment_id IS NULL AND o.updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) AND NOT EXISTS(SELECT 1 FROM scheduled_job_failures f WHERE f.job_name='platform_auto_publish' AND f.business_key=CONCAT('order:',o.id) AND f.resolved_at IS NULL) AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing')) AND NOT EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id AND r.status NOT IN ('failed','closed','cancelled','rejected')) AND NOT EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing')) AND NOT EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND c.status IN ('pending','processing','negotiating')) AND NOT EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing'))".$filter);
        foreach($rows as $row)$tasks[]=$this->task($row,'platform_auto_publish_stuck','order',(string)$row['order_public_no'],'平台单自动发布延迟','托管条件满足超过 5 分钟仍未公开；请先检查统一计划任务，仅在确认自动化异常后使用手动修复发布。','attention',[],'P0');

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,v.id source_id,v.status review_status,v.refund_amount_cents,v.worker_recovery_cents,v.recovered_cents,COALESCE(v.updated_at,v.created_at,o.updated_at) task_created_at FROM platform_order_refund_reviews v JOIN orders o ON o.id=v.order_id WHERE o.order_source_type='platform' AND v.status IN ('open','awaiting_recovery')".$filter);
        foreach($rows as $row){$refund=number_format(((int)$row['refund_amount_cents'])/100,2,'.','');if((string)$row['review_status']==='awaiting_recovery'){$remaining=max(0,(int)$row['worker_recovery_cents']-(int)$row['recovered_cents']);$tasks[]=$this->task($row,'platform_refund_recovery_verification','platform_refund_review',(string)$row['source_id'],'退款追偿款待核实到账','验收后退款 ¥'.$refund.'；仍有 ¥'.number_format($remaining/100,2,'.','').' 需核对真实线下到账流水，禁止自动扣师傅余额。','attention',[],'P0');continue;}$tasks[]=$this->task($row,'platform_refund_finance_review','platform_refund_review',(string)$row['source_id'],'验收后退款资金待判责','已真实退款 ¥'.$refund.'，需要在 Web 财务核账中判定平台/师傅责任及追偿金额。','attention',[],'P0');}

        $risk=$this->financeRiskSql();
        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,o.updated_at task_created_at,t.settlement_status,t.platform_receivable_cents FROM orders o JOIN order_platform_terms t ON t.order_id=o.id WHERE o.order_source_type='platform' AND t.settlement_status IN ('awaiting_platform_income','settled') AND {$risk} AND NOT EXISTS(SELECT 1 FROM platform_order_refund_reviews v WHERE v.order_id=o.id AND v.status IN ('open','awaiting_recovery'))".$filter);
        foreach($rows as $row)$tasks[]=$this->task($row,'platform_finance_risk','order',(string)$row['order_public_no'],'平台单资金状态异常','本单结算存在退款、争议、取消或售后资金风险。请先在 Web 财务页核对真实资金/退款/台账，禁止直接确认收入款结清。','attention',[],'P0');

        $rows=$this->db->fetchAll("SELECT o.id order_id,o.public_no order_public_no,o.version order_version,COALESCE(o.accepted_at,o.updated_at) task_created_at,t.platform_receivable_cents FROM orders o JOIN order_platform_terms t ON t.order_id=o.id WHERE o.order_source_type='platform' AND o.lifecycle_status='completed' AND o.fulfillment_status='accepted' AND o.accepted_at IS NOT NULL AND t.settlement_status='awaiting_platform_income' AND t.platform_receivable_cents>0 AND NOT {$risk}".$filter);
        foreach($rows as $row){
            $due=(int)$row['platform_receivable_cents'];
            $task=$this->task($row,'platform_income_verification','order',(string)$row['order_public_no'],'线下收入款待核实','平台应收 ¥'.number_format($due/100,2,'.','').'。请核对真实到账金额与交易流水后确认结清。','decision',['platform_income_confirm'],'P1',false);
            $task['action_order_id']=(int)$row['order_id'];
            $task['income_amount_cents']=$due;
            $task['native_path']='/pages/adminWorkbench/platformIncome?id='.rawurlencode((string)$row['order_public_no']);
            $tasks[]=$task;
        }
        return $tasks;
    }

    private function normalizeOrderIds(array $orderIds):array{$ids=array_values(array_unique(array_filter(array_map('intval',$orderIds),static fn(int $id):bool=>$id>0)));sort($ids,SORT_NUMERIC);return $ids;}
    private function orderFilter(?array $orderIds):string{if($orderIds===null)return '';if(!$orderIds)return ' AND 1=0';return ' AND o.id IN ('.implode(',',array_map('intval',$orderIds)).')';}
    private function financeRiskSql():string{return "(COALESCE(o.dispute_status,'none') NOT IN ('none','resolved') OR COALESCE(o.refund_status,'none') NOT IN ('none','failed') OR EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','pending','processing')) OR EXISTS(SELECT 1 FROM refunds r WHERE r.order_id=o.id AND r.status NOT IN ('failed','closed','cancelled','rejected')) OR EXISTS(SELECT 1 FROM refunds br INNER JOIN payment_orders bp ON bp.id=br.payment_order_id WHERE bp.order_id=o.id AND bp.business_type IN ('order_booking','booking_fee') AND br.status NOT IN ('failed','closed','cancelled','rejected')) OR EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing')) OR EXISTS(SELECT 1 FROM after_sales a WHERE a.order_id=o.id AND a.status IN ('open','processing')))";}
    private function task(array $row,string $type,string $sourceType,string $sourceId,string $title,string $summary,string $mode='attention',array $allowedActions=[],string $priority='P1',bool $blocking=true):array{$created=(string)($row['task_created_at']??'');$ts=$created!==''?strtotime($created.' UTC'):false;return ['_order_id'=>(int)$row['order_id'],'task_id'=>$type.':'.$sourceId,'task_type'=>$type,'source_type'=>$sourceType,'source_id'=>$sourceId,'title'=>$title,'summary'=>$summary,'priority'=>$priority,'tone'=>$priority==='P0'?'danger':($priority==='P1'?'warning':'info'),'created_at'=>$created,'waiting_seconds'=>$ts===false?0:max(0,time()-$ts),'blocking'=>$blocking,'mode'=>$mode,'allowed_actions'=>array_values($allowedActions),'order_version'=>(int)($row['order_version']??0)];}
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;

/** 到期内部跟进从管理员私有 order_revisions 派生，不进入客户/师傅时间线。 */
final class AdminFollowupWorkbenchTaskSource
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function tasks(?int $onlyOrderId=null):array{return $this->tasksByOrderIds($onlyOrderId===null?null:[$onlyOrderId]);}
    public function tasksForOrderIds(array $orderIds):array{$ids=array_values(array_unique(array_filter(array_map('intval',$orderIds),static fn(int $id):bool=>$id>0)));return $ids?$this->tasksByOrderIds($ids):[];}

    private function tasksByOrderIds(?array $orderIds):array
    {
        $filter='';if($orderIds!==null)$filter=$orderIds?' AND o.id IN ('.implode(',',array_map('intval',$orderIds)).')':' AND 1=0';
        $rows=$this->db->fetchAll(
            "SELECT r.id source_id,r.order_id,o.public_no order_public_no,o.version order_version,r.changes_json,r.created_at task_created_at
             FROM order_revisions r JOIN orders o ON o.id=r.order_id
             WHERE r.operator_type='mini_admin'
               AND JSON_UNQUOTE(JSON_EXTRACT(r.changes_json,'$.kind'))='admin_internal_followup'
               AND JSON_UNQUOTE(JSON_EXTRACT(r.changes_json,'$.follow_up_at')) IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT(r.changes_json,'$.follow_up_at'))<>''
               AND STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(r.changes_json,'$.follow_up_at')),'%Y-%m-%d %H:%i:%s')<=UTC_TIMESTAMP()
               AND NOT EXISTS(
                   SELECT 1 FROM order_revisions c
                   WHERE c.order_id=r.order_id AND c.operator_type='mini_admin'
                     AND CAST(JSON_UNQUOTE(JSON_EXTRACT(c.changes_json,'$.completed_of')) AS UNSIGNED)=r.id
               ){$filter}
             ORDER BY r.created_at,r.id"
        );
        $tasks=[];
        foreach($rows as $row){
            $payload=json_decode((string)$row['changes_json'],true);if(!is_array($payload))$payload=[];$note=trim((string)($payload['note']??''));
            $tasks[]=['_order_id'=>(int)$row['order_id'],'task_id'=>'admin_followup_due:'.(int)$row['source_id'],'task_type'=>'admin_followup_due','source_type'=>'admin_followup','source_id'=>(string)$row['source_id'],'title'=>'运营跟进到期','summary'=>$note!==''?$note:'该订单有一项内部跟进已到期','priority'=>'P1','tone'=>'warning','created_at'=>(string)$row['task_created_at'],'waiting_seconds'=>max(0,time()-(strtotime((string)$row['task_created_at'].' UTC')?:time())),'blocking'=>false,'mode'=>'decision','allowed_actions'=>['admin_followup_complete'],'order_version'=>(int)$row['order_version']];
        }
        return $tasks;
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\AdminWorkbenchOperationsRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Infrastructure\Scheduling\PlatformOrderAutoPublishTask;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseAdminWorkbenchOperationsRepository implements AdminWorkbenchOperationsRepository
{
    private Database $db;

    public function __construct(Database $db){$this->db=$db;}

    public function reassignWorker(array $actor,string $orderNo,int $workerId,string $reason):array
    {
        $miniUserId=(int)($actor['user_id']??0);
        return $this->db->transaction(function(Database $db)use($orderNo,$workerId,$reason,$miniUserId):array{
            $order=$db->fetch("SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE",['public_no'=>$orderNo]);
            if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);
            if((string)$order['lifecycle_status']!=='active'||in_array((string)$order['fulfillment_status'],['submitted','accepted'],true))throw new OrderOperationException('完工已提交、已验收或已结束的订单不能直接改派','ADMIN_REASSIGN_STAGE_BLOCKED',409);
            if(!in_array((string)($order['refund_status']??'none'),['','none'],true)
                ||(bool)$db->fetchValue("SELECT 1 FROM refunds WHERE order_id=:id AND status NOT IN ('failed','closed','cancelled','rejected') LIMIT 1",['id'=>$order['id']])
                ||(bool)$db->fetchValue("SELECT 1 FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing') LIMIT 1",['id'=>$order['id']]))throw new OrderOperationException('订单正在取消或退款处理中，不能直接改派','ADMIN_REASSIGN_FINANCE_BLOCKED',409);
            if((bool)$db->fetchValue("SELECT 1 FROM change_requests WHERE order_id=:id AND status IN ('pending','processing','negotiating') LIMIT 1",['id'=>$order['id']]))throw new OrderOperationException('订单存在待确认的费用调整，请先处理后再改派','ADMIN_REASSIGN_CHANGE_BLOCKED',409);
            if((string)($order['order_source_type']??'')==='platform'){$settlement=(string)$db->fetchValue('SELECT settlement_status FROM order_platform_terms WHERE order_id=:id LIMIT 1',['id'=>$order['id']]);if($settlement!==''&&$settlement!=='pending')throw new OrderOperationException('平台单已进入结算阶段，不能直接改派','ADMIN_REASSIGN_SETTLEMENT_BLOCKED',409);}

            $assignmentId=(int)($order['current_assignment_id']??0);
            $old=$assignmentId>0?$db->fetch("SELECT a.*,w.user_id old_worker_user_id,w.display_name old_worker_name FROM assignments a JOIN worker_profiles w ON w.id=a.worker_id WHERE a.id=:id AND a.order_id=:order_id FOR UPDATE",['id'=>$assignmentId,'order_id'=>$order['id']]):null;
            if(!$old||!in_array((string)$old['status'],['offered','accepted'],true))throw new OrderOperationException('当前没有可改派的有效师傅指派','ADMIN_REASSIGN_ASSIGNMENT_REQUIRED',409);
            if((int)$old['worker_id']===$workerId)throw new OrderOperationException('请选择另一位师傅','ADMIN_REASSIGN_SAME_WORKER',409);

            $worker=$db->fetch("SELECT w.id,w.user_id,w.public_id,w.display_name,w.verification_status,w.cloud_record_mode,u.status user_status FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:id LIMIT 1 FOR UPDATE",['id'=>$workerId]);
            if(!$worker||(string)$worker['verification_status']!=='approved'||(string)$worker['user_status']!=='active')throw new OrderOperationException('新师傅不存在、未审核通过或账号不可用','ADMIN_REASSIGN_WORKER_UNAVAILABLE',422);
            $orderIsDemo=!empty($order['is_demo']);$workerIsDemo=(string)($worker['cloud_record_mode']??'')==='demo_local';if($orderIsDemo!==$workerIsDemo)throw new OrderOperationException('演示与真实数据环境不能互相改派','DEMO_DATA_BOUNDARY',403);
            $measurementWorker=$db->fetchValue("SELECT worker_id FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$order['id']]);
            if($measurementWorker!==false&&(int)$measurementWorker!==$workerId)throw new OrderOperationException('关联测量单施工仍绑定原测量师傅；如需更换请新建普通施工单','MEASUREMENT_WORKER_BOUND',409);

            $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status IN ('offered','accepted')",['id'=>$old['id']]);
            $db->insert("INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,snapshot_json,created_at) VALUES(:assignment_id,:order_id,:worker_id,'reassigned',:reason,'mini_admin',:actor_id,:snapshot,UTC_TIMESTAMP())",[
                'assignment_id'=>$old['id'],'order_id'=>$order['id'],'worker_id'=>$old['worker_id'],'reason'=>$reason,'actor_id'=>$miniUserId,'snapshot'=>$this->json(['reassigned_to_worker_id'=>$workerId])
            ]);
            $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND user_id=:user_id AND status='active'",['order_id'=>$order['id'],'user_id'=>$old['old_worker_user_id']]);
            $db->execute("UPDATE order_worker_contact_reveal_requests SET status='cancelled',review_note='订单已由管理员改派其他师傅',reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:order_id AND worker_id=:worker_id AND status='pending'",['order_id'=>$order['id'],'worker_id'=>$old['worker_id']]);
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:order_id AND status IN ('active','claimed')",['order_id'=>$order['id']]);

            $acceptHours=max(1,min(72,(int)(function_exists('setting')?\setting('order.assignment_accept_hours',24):24)));
            $newAssignmentId=$db->insert("INSERT INTO assignments(public_id,order_id,worker_id,quote_id,assignment_type,commission_rule_version_id,final_amount_cents_snapshot,booking_fee_cents_snapshot,commission_rate_bp_snapshot,commission_min_cents_snapshot,estimated_commission_cents,estimated_offline_collect_cents,estimated_net_income_cents,status,offered_at,accept_deadline_at,created_by_admin_id,created_at,updated_at) VALUES(:public_id,:order_id,:worker_id,NULL,'admin',:rule_id,:final_amount,:booking_fee,:rate,:minimum,:commission,:offline,:net,'offered',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL {$acceptHours} HOUR),NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[
                'public_id'=>\public_id(),'order_id'=>$order['id'],'worker_id'=>$workerId,'rule_id'=>$old['commission_rule_version_id']?:null,'final_amount'=>$old['final_amount_cents_snapshot'],'booking_fee'=>$old['booking_fee_cents_snapshot'],'rate'=>$old['commission_rate_bp_snapshot'],'minimum'=>$old['commission_min_cents_snapshot'],'commission'=>$old['estimated_commission_cents'],'offline'=>$old['estimated_offline_collect_cents'],'net'=>$old['estimated_net_income_cents']
            ]);
            $db->insert("INSERT INTO assignment_history(assignment_id,order_id,worker_id,action,reason,actor_type,actor_id,snapshot_json,created_at) VALUES(:assignment_id,:order_id,:worker_id,'offered',:reason,'mini_admin',:actor_id,:snapshot,UTC_TIMESTAMP())",[
                'assignment_id'=>$newAssignmentId,'order_id'=>$order['id'],'worker_id'=>$workerId,'reason'=>$reason,'actor_id'=>$miniUserId,'snapshot'=>$this->json(['reassigned_from_worker_id'=>(int)$old['worker_id'],'requires_worker_acceptance'=>true])
            ]);
            $db->execute("UPDATE orders SET current_assignment_id=:assignment_id,assigned_worker_id=:worker_id,assignment_status='offered',fulfillment_status='not_started',contact_revealed_at=NULL,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['assignment_id'=>$newAssignmentId,'worker_id'=>$workerId,'id'=>$order['id']]);

            $eventKey='admin-reassign:'.$order['id'].':'.$newAssignmentId;
            $payload=['message'=>'平台已改派师傅；新师傅仍需本人确认接单','old_worker_id'=>(int)$old['worker_id'],'old_worker_name'=>(string)$old['old_worker_name'],'new_worker_id'=>$workerId,'new_worker_name'=>(string)$worker['display_name'],'reason'=>$reason,'operator_user_id'=>$miniUserId,'notify_recipients'=>['user','worker']];
            $db->insert("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,'worker_replaced_by_admin',:event_key,'mini_admin',:actor_id,:payload,UTC_TIMESTAMP())",['order_id'=>$order['id'],'event_key'=>substr($eventKey,0,128),'actor_id'=>$miniUserId,'payload'=>$this->json($payload)]);
            OrderNotificationService::queue($db,(int)$order['id'],$eventKey,'worker_replaced_by_admin',$payload);
            return ['order_id'=>(string)$order['public_no'],'assignment_id'=>$newAssignmentId,'old_worker'=>['id'=>(int)$old['worker_id'],'name'=>(string)$old['old_worker_name']],'new_worker'=>['id'=>$workerId,'name'=>(string)$worker['display_name']],'assignment_status'=>'offered','requires_worker_acceptance'=>true];
        });
    }

    public function interveneRepair(array $actor,string $orderNo,string $afterSaleNo,string $reason):array
    {
        $miniUserId=(int)($actor['user_id']??0);
        return $this->db->transaction(function(Database $db)use($orderNo,$afterSaleNo,$reason,$miniUserId):array{
            $order=$db->fetch('SELECT id,public_no,version FROM orders WHERE BINARY public_no=BINARY :public_no FOR UPDATE',['public_no'=>$orderNo]);if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);
            $repair=$db->fetch("SELECT id,public_no,status,resolution FROM after_sales WHERE order_id=:order_id AND BINARY public_no=BINARY :public_no AND type='warranty_repair' LIMIT 1 FOR UPDATE",['order_id'=>$order['id'],'public_no'=>$afterSaleNo]);
            if(!$repair||!in_array((string)$repair['status'],['open','processing'],true))throw new OrderOperationException('维修申请不存在或已结束','REPAIR_NOT_ACTIVE',409);
            $already=strpos((string)($repair['resolution']??''),'平台主动介入：')===0||(bool)$db->fetchValue("SELECT 1 FROM order_events WHERE order_id=:order_id AND event_type='repair_platform_intervention_started' AND payload_json LIKE :needle LIMIT 1",['order_id'=>$order['id'],'needle'=>'%\"after_sale_id\":\"'.$afterSaleNo.'\"%']);
            if($already)return ['order_id'=>$orderNo,'after_sale_id'=>$afterSaleNo,'status'=>'processing','already_intervened'=>true];
            $db->execute("UPDATE after_sales SET status='processing',resolution=:resolution,updated_at=UTC_TIMESTAMP() WHERE id=:id",['resolution'=>'平台主动介入：'.$reason,'id'=>$repair['id']]);
            $db->execute('UPDATE orders SET updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id',['id'=>$order['id']]);
            $eventKey='admin-repair-intervention:'.$repair['id'];$payload=['after_sale_id'=>$afterSaleNo,'reason'=>$reason,'message'=>'平台已主动介入本次维修售后','operator_user_id'=>$miniUserId,'notify_recipients'=>['user','worker']];
            $db->insert("INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,'repair_platform_intervention_started',:event_key,'mini_admin',:actor_id,:payload,UTC_TIMESTAMP())",['order_id'=>$order['id'],'event_key'=>$eventKey,'actor_id'=>$miniUserId,'payload'=>$this->json($payload)]);
            OrderNotificationService::queue($db,(int)$order['id'],$eventKey,'repair_platform_intervention_started',$payload);
            return ['order_id'=>$orderNo,'after_sale_id'=>$afterSaleNo,'status'=>'processing','already_intervened'=>false];
        });
    }

    public function retryPlatformAutoPublish(array $actor,string $orderNo,string $sourceId):array
    {
        $order=$this->db->fetch("SELECT id,public_no,order_source_type,publish_status FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1",['public_no'=>$orderNo]);
        if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);if((string)$order['order_source_type']!=='platform')throw new OrderOperationException('只有平台承接单支持自动发布恢复','AUTO_PUBLISH_RETRY_NOT_PLATFORM',409);
        $failure=$this->db->fetch("SELECT id FROM scheduled_job_failures WHERE job_name='platform_auto_publish' AND business_key=:business_key AND resolved_at IS NULL ORDER BY id DESC LIMIT 1",['business_key'=>'order:'.(int)$order['id']]);
        if($sourceId!==''&&$failure&&ctype_digit($sourceId)&&(int)$failure['id']!==(int)$sourceId)throw new OrderOperationException('该失败事项已经变化，请刷新后重试','ADMIN_RETRY_STALE_TASK',409);
        $result=(new PlatformOrderAutoPublishTask($this->db,1))->retryOrder((int)$order['id']);if(empty($result['ok']))throw new OrderOperationException((string)($result['message']??'当前条件仍不满足自动发布'),'ADMIN_RETRY_NOT_READY',409);
        $this->internalRevision((int)$order['id'],'admin_internal_recovery',(int)($actor['user_id']??0),['note'=>'管理员手动重新检查平台自动发布','result'=>(string)($result['status']??'')]);
        return ['order_id'=>$orderNo]+$result;
    }

    public function addInternalFollowup(array $actor,string $orderNo,string $note,?string $followUpAt):array
    {
        $order=$this->db->fetch('SELECT id,public_no FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);
        $utc=$this->normalizeFollowupTime($followUpAt);$revisionId=$this->internalRevision((int)$order['id'],'admin_internal_followup',(int)($actor['user_id']??0),['note'=>$note,'follow_up_at'=>$utc]);
        return ['order_id'=>$orderNo,'followup_event_id'=>$revisionId,'note'=>$note,'follow_up_at'=>$utc,'completed'=>false];
    }

    public function completeInternalFollowup(array $actor,string $orderNo,int $followupEventId,string $note):array
    {
        $order=$this->db->fetch('SELECT id,public_no FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);
        $followup=$this->db->fetch("SELECT id FROM order_revisions WHERE id=:id AND order_id=:order_id AND operator_type='mini_admin' AND JSON_UNQUOTE(JSON_EXTRACT(changes_json,'$.kind'))='admin_internal_followup' LIMIT 1",['id'=>$followupEventId,'order_id'=>$order['id']]);
        if(!$followup)throw new OrderOperationException('跟进事项不存在','ADMIN_FOLLOWUP_NOT_FOUND',404);
        $completed=(bool)$this->db->fetchValue("SELECT 1 FROM order_revisions WHERE order_id=:order_id AND operator_type='mini_admin' AND JSON_EXTRACT(changes_json,'$.completed_of')=:completed_of LIMIT 1",['order_id'=>$order['id'],'completed_of'=>$followupEventId]);
        if(!$completed)$this->internalRevision((int)$order['id'],'admin_internal_followup_completed',(int)($actor['user_id']??0),['completed_of'=>$followupEventId,'note'=>$note]);
        return ['order_id'=>$orderNo,'followup_event_id'=>$followupEventId,'completed'=>true];
    }

    public function workerOptions(string $orderNo,string $keyword,int $limit):array
    {
        $order=$this->db->fetch('SELECT id,is_demo,assigned_worker_id FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);if(!$order)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);
        $limit=max(1,min(50,$limit));$params=[];$where=["w.verification_status='approved'","u.status='active'",!empty($order['is_demo'])?"w.cloud_record_mode='demo_local'":"COALESCE(w.cloud_record_mode,'')<>'demo_local'"];
        if($keyword!==''){$where[]='(w.display_name LIKE :q_name OR w.public_id LIKE :q_public OR w.mobile_masked LIKE :q_mobile)';$like='%'.mb_substr($keyword,0,50).'%';$params=['q_name'=>$like,'q_public'=>$like,'q_mobile'=>$like];}
        $rows=$this->db->fetchAll("SELECT w.id,w.public_id,w.display_name,w.mobile_masked,w.verification_status FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE ".implode(' AND ',$where)." ORDER BY (w.id=:current_worker) DESC,w.id DESC LIMIT {$limit}",$params+['current_worker'=>(int)($order['assigned_worker_id']??0)]);
        foreach($rows as &$row){$row['id']=(int)$row['id'];$row['is_current']=$row['id']===(int)($order['assigned_worker_id']??0);}unset($row);return $rows;
    }

    public function internalFollowups(string $orderNo,int $limit):array
    {
        $orderId=(int)$this->db->fetchValue('SELECT id FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$orderNo]);if($orderId<1)throw new OrderOperationException('订单不存在','ORDER_NOT_FOUND',404);$limit=max(1,min(50,$limit));
        $rows=$this->db->fetchAll("SELECT r.id,r.changes_json,r.created_at,r.operator_id,EXISTS(SELECT 1 FROM order_revisions c WHERE c.order_id=r.order_id AND c.operator_type='mini_admin' AND JSON_EXTRACT(c.changes_json,'$.completed_of')=r.id) completed FROM order_revisions r WHERE r.order_id=:order_id AND r.operator_type='mini_admin' AND JSON_UNQUOTE(JSON_EXTRACT(r.changes_json,'$.kind'))='admin_internal_followup' ORDER BY r.id DESC LIMIT {$limit}",['order_id'=>$orderId]);
        $items=[];$now=time();foreach($rows as $row){$payload=json_decode((string)$row['changes_json'],true);if(!is_array($payload))$payload=[];$followAt=trim((string)($payload['follow_up_at']??''));$ts=$followAt!==''?strtotime($followAt.' UTC'):false;$items[]=['id'=>(int)$row['id'],'note'=>(string)($payload['note']??''),'follow_up_at'=>$followAt?:null,'created_at'=>(string)$row['created_at'],'operator_user_id'=>(int)($row['operator_id']??0),'completed'=>(bool)$row['completed'],'due'=>!(bool)$row['completed']&&$ts!==false&&$ts<=$now];}return $items;
    }

    private function normalizeFollowupTime(?string $value):?string
    {
        $value=trim((string)$value);if($value==='')return null;try{$timezone=(string)(function_exists('app_config')?\app_config('app.timezone','Asia/Shanghai'):'Asia/Shanghai');return(new DateTimeImmutable($value,new DateTimeZone($timezone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){throw new OrderOperationException('跟进时间格式不正确','ADMIN_FOLLOWUP_TIME_INVALID',422);}
    }

    private function internalRevision(int $orderId,string $kind,int $actorId,array $payload):int
    {
        $version=(int)$this->db->fetchValue('SELECT version FROM orders WHERE id=:id LIMIT 1',['id'=>$orderId]);$changes=['kind'=>$kind]+$payload+['visibility'=>'internal'];
        return $this->db->insert("INSERT INTO order_revisions(order_id,version_before,version_after,changes_json,reason,operator_type,operator_id,created_at) VALUES(:order_id,:version_before,:version_after,:changes,:reason,'mini_admin',:operator_id,UTC_TIMESTAMP())",['order_id'=>$orderId,'version_before'=>$version,'version_after'=>$version,'changes'=>$this->json($changes),'reason'=>$kind==='admin_internal_followup'?'管理员内部跟进':($kind==='admin_internal_followup_completed'?'管理员完成内部跟进':'管理员恢复操作'),'operator_id'=>$actorId]);
    }

    private function json(array $payload):string{$json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return is_string($json)?$json:'{}';}
}

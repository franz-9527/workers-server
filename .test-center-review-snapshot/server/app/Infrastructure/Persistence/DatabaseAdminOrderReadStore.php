<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;

final class DatabaseAdminOrderReadStore
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }

    public function index(array $filters): array
    {
        $search=trim((string)($filters['q']??''));$auditStatus=trim((string)($filters['audit_status']??''));$lifecycleStatus=trim((string)($filters['lifecycle_status']??''));
        $page=max(1,(int)($filters['page']??1));$perPage=20;$offset=($page-1)*$perPage;
        $where=['1=1'];$params=[];
        if(!$this->demoEnabled())$where[]="o.is_demo=0 AND o.public_no NOT LIKE 'WO-DEMO-%'";
        if($search!==''){$where[]='(o.public_no LIKE :q_no OR o.category_name_snapshot LIKE :q_category OR o.category_names_snapshot LIKE :q_category_all OR o.city LIKE :q_city OR o.district LIKE :q_district OR u.nickname LIKE :q_user)';$like='%'.$search.'%';$params=['q_no'=>$like,'q_category'=>$like,'q_category_all'=>$like,'q_city'=>$like,'q_district'=>$like,'q_user'=>$like];}
        if($auditStatus!==''){$where[]='o.audit_status=:audit_status';$params['audit_status']=$auditStatus;}
        if($lifecycleStatus!==''){$where[]='o.lifecycle_status=:lifecycle_status';$params['lifecycle_status']=$lifecycleStatus;}
        $condition=implode(' AND ',$where);
        $total=(int)$this->db->fetchValue('SELECT COUNT(*) FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE '.$condition,$params);
        $rows=$this->db->fetchAll("SELECT o.*,u.nickname,u.mobile_masked AS user_mobile,u.mobile_ciphertext AS user_mobile_ciphertext,
            (SELECT m.id FROM media_links ml INNER JOIN media_files m ON m.id=ml.media_file_id WHERE ml.entity_type='order' AND ml.entity_id=o.id AND m.status='active' ORDER BY ml.sort_order,ml.id LIMIT 1) AS cover_media_id,
            (SELECT COUNT(*) FROM media_links ml2 INNER JOIN media_files m2 ON m2.id=ml2.media_file_id WHERE ml2.entity_type='order' AND ml2.entity_id=o.id AND m2.status='active') AS media_count,
            (SELECT COUNT(*) FROM invite_tokens it WHERE it.order_id=o.id) AS invite_count,
            (SELECT q.quote_type FROM quotes q WHERE q.id=o.selected_quote_id LIMIT 1) AS selected_quote_type,
            (SELECT q.amount_cents FROM quotes q WHERE q.id=o.selected_quote_id LIMIT 1) AS selected_quote_amount_cents,
            (SELECT wq.display_name FROM quotes q LEFT JOIN worker_profiles wq ON wq.id=q.worker_id WHERE q.id=o.selected_quote_id LIMIT 1) AS selected_quote_worker_name,
            (SELECT wa.display_name FROM worker_profiles wa WHERE wa.id=o.assigned_worker_id LIMIT 1) AS assigned_worker_name,
            (SELECT cr.id FROM order_cancel_requests cr WHERE cr.order_id=o.id ORDER BY cr.id DESC LIMIT 1) AS cancel_request_id,
            (SELECT cr.status FROM order_cancel_requests cr WHERE cr.order_id=o.id ORDER BY cr.id DESC LIMIT 1) AS cancel_request_status,
            (SELECT cr.reason FROM order_cancel_requests cr WHERE cr.order_id=o.id ORDER BY cr.id DESC LIMIT 1) AS cancel_request_reason,
            (SELECT cr.requested_refund_cents FROM order_cancel_requests cr WHERE cr.order_id=o.id ORDER BY cr.id DESC LIMIT 1) AS cancel_requested_refund_cents,
            (SELECT c.status FROM commission_receivables c WHERE c.order_id=o.id LIMIT 1) AS commission_status,
            (SELECT c.amount_due_cents FROM commission_receivables c WHERE c.order_id=o.id LIMIT 1) AS commission_due_cents,
            (SELECT c.amount_paid_cents FROM commission_receivables c WHERE c.order_id=o.id LIMIT 1) AS commission_paid_cents
            FROM orders o LEFT JOIN users u ON u.id=o.user_id WHERE {$condition} ORDER BY o.id DESC LIMIT {$perPage} OFFSET {$offset}",$params);
        foreach($rows as &$row){$full=\decrypt_private_value((string)($row['user_mobile_ciphertext']??''),'user-mobile');if($full!=='')$row['user_mobile']=$full;$row['contact_name_full']=\decrypt_private_value((string)($row['contact_name_ciphertext']??''),'order-contact-name')?:($row['contact_name_masked']??'');$row['contact_mobile_full']=\decrypt_private_value((string)($row['contact_mobile_ciphertext']??''),'order-contact-mobile')?:($row['contact_mobile_masked']??'');$row['address_full']=\decrypt_private_value((string)($row['address_ciphertext']??''),'order-address')?:($row['address_summary']??'');unset($row['user_mobile_ciphertext']);}unset($row);
        $categories=$this->db->fetchAll("SELECT id,name,order_code FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id");
        $users=$this->db->fetchAll("SELECT id,nickname,mobile_masked,mobile_ciphertext,public_id FROM users WHERE status='active'".($this->demoEnabled()?'':" AND public_id NOT LIKE '%DEMO%'").' ORDER BY id DESC LIMIT 500');
        foreach($users as &$u){$full=\decrypt_private_value((string)($u['mobile_ciphertext']??''),'user-mobile');if($full!=='')$u['mobile_masked']=$full;unset($u['mobile_ciphertext']);}unset($u);
        $workers=$this->workerOptions(300);
        $mediaByOrder=[];$invitesByOrder=[];$quotesByOrder=[];$assignmentsByOrder=[];$categoryIdsByOrder=[];$contactRevealByOrder=[];
        if($rows){$paramsIn=[];$marks=[];foreach($rows as $i=>$row){$key='oid'.$i;$marks[]=':'.$key;$paramsIn[$key]=(int)$row['id'];}$in=implode(',',$marks);
            foreach($this->db->fetchAll("SELECT l.entity_id,m.id,m.mime_type,m.width_px,m.height_px,l.purpose,l.sort_order FROM media_links l INNER JOIN media_files m ON m.id=l.media_file_id WHERE l.entity_type='order' AND m.status='active' AND l.entity_id IN ({$in}) ORDER BY l.entity_id,l.purpose,l.sort_order,l.id",$paramsIn) as $r)$mediaByOrder[(int)$r['entity_id']][]=$r;
            foreach($this->db->fetchAll("SELECT i.id,i.order_id,i.status,i.claim_count,i.max_claims,i.expires_at,i.created_at,r.rate_bp,r.min_fee_cents,w.display_name AS claimed_worker_name FROM invite_tokens i LEFT JOIN commission_rule_versions r ON r.id=i.commission_rule_version_id LEFT JOIN worker_profiles w ON w.id=i.claimed_worker_id WHERE i.order_id IN ({$in}) ORDER BY i.id DESC",$paramsIn) as $r)$invitesByOrder[(int)$r['order_id']][]=$r;
            foreach($this->db->fetchAll("SELECT q.id,q.public_id,q.order_id,q.quote_type,q.amount_cents,q.status,q.created_at,w.display_name AS worker_name FROM quotes q LEFT JOIN worker_profiles w ON w.id=q.worker_id WHERE q.order_id IN ({$in}) ORDER BY q.id DESC",$paramsIn) as $r)$quotesByOrder[(int)$r['order_id']][]=$r;
            foreach($this->db->fetchAll("SELECT a.order_id,a.assignment_type,a.commission_rate_bp_snapshot,a.status,a.created_at,w.display_name AS worker_name FROM assignments a LEFT JOIN worker_profiles w ON w.id=a.worker_id WHERE a.order_id IN ({$in}) ORDER BY a.id DESC",$paramsIn) as $r)$assignmentsByOrder[(int)$r['order_id']][]=$r;
            foreach($this->db->fetchAll("SELECT order_id,category_id FROM order_categories WHERE order_id IN ({$in}) ORDER BY sort_order,category_id",$paramsIn) as $r)$categoryIdsByOrder[(int)$r['order_id']][]=(int)$r['category_id'];
            foreach($this->db->fetchAll("SELECT r.*,w.display_name AS worker_name,w.mobile_masked AS worker_mobile,af.public_no AS after_sale_public_no FROM order_worker_contact_reveal_requests r JOIN worker_profiles w ON w.id=r.worker_id LEFT JOIN after_sales af ON af.id=r.after_sale_id WHERE r.status='pending' AND r.order_id IN ({$in}) ORDER BY r.requested_at ASC,r.id ASC",$paramsIn) as $r)$contactRevealByOrder[(int)$r['order_id']][]=$r;
        }
        $statsCondition=$this->demoEnabled()?'1=1':"o.is_demo=0 AND o.public_no NOT LIKE 'WO-DEMO-%'";
        $stats=$this->db->fetch("SELECT COUNT(*) total_count,SUM(CASE WHEN o.lifecycle_status='active' AND o.selected_quote_id IS NULL THEN 1 ELSE 0 END) quoting_count,SUM(CASE WHEN o.lifecycle_status='active' AND (o.assigned_worker_id IS NOT NULL OR o.fulfillment_status IN ('scheduled','in_progress','submitted')) THEN 1 ELSE 0 END) working_count,SUM(CASE WHEN o.lifecycle_status='completed' OR o.fulfillment_status='accepted' THEN 1 ELSE 0 END) completed_count,SUM(CASE WHEN o.refund_status='succeeded' OR o.payment_status='refunded' THEN 1 ELSE 0 END) refunded_count FROM orders o WHERE ".$statsCondition)?:[];
        $stats['booking_paid_cents']=(int)$this->db->fetchValue("SELECT COALESCE(SUM(p.amount_cents),0) FROM payment_orders p LEFT JOIN orders o ON o.id=p.order_id WHERE p.status IN ('paid','refunded') AND p.business_type IN ('order_booking','booking_fee') AND ".$statsCondition);
        $stats['contact_reveal_pending_count']=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_worker_contact_reveal_requests r JOIN orders o ON o.id=r.order_id WHERE r.status='pending' AND ".$statsCondition);
        return ['result'=>['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage)),'per_page'=>$perPage],'filters'=>['search'=>$search,'auditStatus'=>$auditStatus,'lifecycleStatus'=>$lifecycleStatus],'categories'=>$categories,'users'=>$users,'workers'=>$workers,'mediaByOrder'=>$mediaByOrder,'invitesByOrder'=>$invitesByOrder,'quotesByOrder'=>$quotesByOrder,'assignmentsByOrder'=>$assignmentsByOrder,'categoryIdsByOrder'=>$categoryIdsByOrder,'contactRevealByOrder'=>$contactRevealByOrder,'orderStats'=>$stats];
    }

    public function detail(int $id): array
    {
        $sql="SELECT o.*,u.nickname,u.mobile_masked AS user_mobile,u.mobile_ciphertext AS user_mobile_ciphertext,w.display_name AS worker_name,w.mobile_masked AS worker_mobile,w.mobile_ciphertext AS worker_mobile_ciphertext,(SELECT q0.quote_type FROM quotes q0 WHERE q0.id=o.selected_quote_id LIMIT 1) AS selected_quote_type,(SELECT q0.amount_cents FROM quotes q0 WHERE q0.id=o.selected_quote_id LIMIT 1) AS selected_quote_amount_cents,(SELECT wq.display_name FROM quotes q0 LEFT JOIN worker_profiles wq ON wq.id=q0.worker_id WHERE q0.id=o.selected_quote_id LIMIT 1) AS selected_quote_worker_name FROM orders o LEFT JOIN users u ON u.id=o.user_id LEFT JOIN worker_profiles w ON w.id=o.assigned_worker_id WHERE o.id=:id";
        if(!$this->demoEnabled())$sql.=" AND o.is_demo=0 AND o.public_no NOT LIKE 'WO-DEMO-%'";
        $order=$this->db->fetch($sql,['id'=>$id]);if(!$order)throw new RuntimeException('订单不存在。');
        $order['contact_name_full']=\decrypt_private_value((string)($order['contact_name_ciphertext']??''),'order-contact-name')?:($order['contact_name_masked']??'');$order['contact_mobile_full']=\decrypt_private_value((string)($order['contact_mobile_ciphertext']??''),'order-contact-mobile')?:($order['contact_mobile_masked']??'');$order['address_full']=\decrypt_private_value((string)($order['address_ciphertext']??''),'order-address')?:($order['address_summary']??'');$order['user_mobile']=\decrypt_private_value((string)($order['user_mobile_ciphertext']??''),'user-mobile')?:($order['user_mobile']??'');$order['worker_mobile']=\decrypt_private_value((string)($order['worker_mobile_ciphertext']??''),'worker-mobile')?:($order['worker_mobile']??'');unset($order['user_mobile_ciphertext'],$order['worker_mobile_ciphertext']);
        $quotes=$this->db->fetchAll('SELECT q.*,q.quote_type AS source,q.amount_cents AS total_amount_cents,q.expires_at AS valid_until,w.display_name AS worker_name,a.real_name AS admin_name FROM quotes q LEFT JOIN worker_profiles w ON w.id=q.worker_id LEFT JOIN admin_users a ON a.id=q.created_by_admin_id WHERE q.order_id=:id ORDER BY q.id DESC',['id'=>$id]);
        $assignments=$this->db->fetchAll('SELECT s.*,s.assignment_type AS source,s.commission_rate_bp_snapshot AS commission_rate_bp,s.accepted_at AS info_released_at,w.display_name AS worker_name,a.real_name AS admin_name FROM assignments s LEFT JOIN worker_profiles w ON w.id=s.worker_id LEFT JOIN admin_users a ON a.id=s.created_by_admin_id WHERE s.order_id=:id ORDER BY s.id DESC',['id'=>$id]);
        $invites=$this->db->fetchAll('SELECT i.id,i.status,i.max_claims,i.claim_count,i.expires_at,i.claimed_at,i.created_at,r.rate_bp,r.min_fee_cents,a.real_name AS admin_name,w.display_name AS claimed_worker_name FROM invite_tokens i LEFT JOIN commission_rule_versions r ON r.id=i.commission_rule_version_id LEFT JOIN admin_users a ON a.id=i.created_by_admin_id LEFT JOIN worker_profiles w ON w.id=i.claimed_worker_id WHERE i.order_id=:id ORDER BY i.id DESC',['id'=>$id]);
        $events=$this->db->fetchAll('SELECT * FROM order_events WHERE order_id=:id ORDER BY id DESC LIMIT 50',['id'=>$id]);foreach($events as &$event){$payload=json_decode((string)$event['payload_json'],true);$event['title']=\order_event_text((string)$event['event_type']);$event['content']=is_array($payload)&&isset($payload['message'])?(string)$payload['message']:'';}unset($event);
        $answers=[];foreach($this->db->fetchAll('SELECT field_key_snapshot,field_label_snapshot,display_value,value_json FROM order_answers WHERE order_id=:id ORDER BY sort_order,id',['id'=>$id]) as $answer){if($answer['field_key_snapshot']==='is_renovation')continue;if($answer['field_key_snapshot']==='wall_images')$answer['field_label_snapshot']='现场环境/墙面图片';unset($answer['field_key_snapshot']);$answers[]=$answer;}
        $media=$this->db->fetchAll("SELECT m.id,m.public_id,m.mime_type,m.extension,m.size_bytes,m.width_px,m.height_px,l.purpose,l.sort_order FROM media_links l INNER JOIN media_files m ON m.id=l.media_file_id WHERE l.entity_type='order' AND l.entity_id=:id AND m.status='active' ORDER BY l.purpose,l.sort_order,l.id",['id'=>$id]);
        $categories=$this->db->fetchAll("SELECT id,name FROM service_categories WHERE parent_id IS NULL AND status='active' ORDER BY sort_order,id");$selected=array_map('intval',array_column($this->db->fetchAll('SELECT category_id FROM order_categories WHERE order_id=:id ORDER BY sort_order,category_id',['id'=>$id]),'category_id'));
        $contact=$this->db->fetchAll("SELECT r.*,w.display_name AS worker_name,w.mobile_masked AS worker_mobile,af.public_no AS after_sale_public_no FROM order_worker_contact_reveal_requests r JOIN worker_profiles w ON w.id=r.worker_id LEFT JOIN after_sales af ON af.id=r.after_sale_id WHERE r.order_id=:id AND r.status='pending' ORDER BY r.requested_at ASC,r.id ASC",['id'=>$id]);
        $canReview=$order['audit_status']==='approved'&&$order['lifecycle_status']==='active'&&empty($order['selected_quote_id'])&&(bool)$this->db->fetchValue("SELECT 1 FROM order_events WHERE order_id=:id AND event_type='order_auto_preapproved' LIMIT 1",['id'=>$id]);
        return compact('order','quotes','assignments','invites','events','answers','media','categories','contact')+['selectedCategoryIds'=>$selected,'workers'=>$this->workerOptions(200),'contactRevealRequests'=>$contact,'canReviewAutoPreapproval'=>$canReview];
    }

    public function media(int $id): array
    {
        $media=$this->db->fetch("SELECT m.* FROM media_files m WHERE m.id=:id AND m.status='active' AND (EXISTS(SELECT 1 FROM media_links l WHERE l.media_file_id=m.id) OR EXISTS(SELECT 1 FROM review_media rm WHERE rm.media_file_id=m.id)) LIMIT 1",['id'=>$id]);if(!$media)throw new RuntimeException('图片不存在。');return $media;
    }

    private function workerOptions(int $limit): array
    {
        $rows=$this->db->fetchAll("SELECT w.id,w.display_name,w.mobile_masked,w.mobile_ciphertext,w.verification_status FROM worker_profiles w INNER JOIN users u ON u.id=w.user_id WHERE w.verification_status='approved' AND u.status='active'".($this->demoEnabled()?'':" AND w.public_id NOT LIKE '%DEMO%'").' ORDER BY w.id DESC LIMIT '.max(1,$limit));foreach($rows as &$row){$full=\decrypt_private_value((string)($row['mobile_ciphertext']??''),'worker-mobile');if($full!=='')$row['mobile_masked']=$full;unset($row['mobile_ciphertext']);}unset($row);return $rows;
    }
    private function demoEnabled(): bool { return (bool)\setting('demo.data_enabled',true); }
}

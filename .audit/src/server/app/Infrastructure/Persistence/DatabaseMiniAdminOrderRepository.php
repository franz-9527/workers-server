<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\MiniAdminOrderRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\MiniAdminOperationException;
use WorkerAdmin\Services\MiniProgramCodeService;
use WorkerAdmin\Services\OrderNotificationService;
use WorkerAdmin\Services\WeChatPayService;

final class DatabaseMiniAdminOrderRepository implements MiniAdminOrderRepository
{
    private Database $db;
    private string $timezone;

    public function __construct(Database $db,string $timezone='Asia/Shanghai')
    {
        $this->db=$db;$this->timezone=$timezone;
    }

    public function findOrder(string $publicNo): array
    {
        $row=$this->db->fetch('SELECT * FROM orders WHERE public_no=:id LIMIT 1',['id'=>trim($publicNo)]);
        if(!$row)throw new MiniAdminOperationException('订单不存在','ORDER_NOT_FOUND',404);
        return $row;
    }

    public function performOrderAction(array $actor,array $order,array $command): array
    {
        $action=(string)$command['action'];$reason=(string)$command['reason'];$title=(string)$command['title'];$description=(string)$command['description'];
        $editValues=(array)$command['edit_values'];$autoCancelDays=(int)$command['auto_cancel_days'];$supplementHours=(int)$command['supplement_hours'];
        $refundId=$this->db->transaction(function(Database $db)use($actor,$order,$action,$reason,$title,$description,$editValues,$autoCancelDays,$supplementHours){
            $locked=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$order['id']]);
            if(!$locked)throw new RuntimeException('订单不存在',404);
            $refundId=null;
            if(in_array($action,['approve','supplement','reject'],true)){
                if($locked['payment_status']!=='paid')throw new RuntimeException('预约费尚未支付，不能审核该订单',409);
                if(!in_array($locked['audit_status'],['pending','supplement_required'],true))throw new RuntimeException('该订单当前不在待审核队列',409);
            }
            if($action==='approve'){
                $linked=(bool)$db->fetchValue("SELECT 1 FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$order['id']]);
                $db->execute("UPDATE orders SET audit_status='approved',supplement_due_at=NULL,publish_status=IF(:linked=1,'hidden',IF(selected_quote_id IS NULL,'published','hidden')),published_at=IF(:linked=1,NULL,IF(selected_quote_id IS NULL,COALESCE(published_at,UTC_TIMESTAMP()),published_at)),quote_deadline_at=IF(selected_quote_id IS NULL,COALESCE(quote_deadline_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$autoCancelDays." DAY)),quote_deadline_at),audited_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND audit_status IN ('pending','supplement_required')",['linked'=>$linked?1:0,'id'=>$order['id']]);
                $db->execute("UPDATE order_deadlines SET status='completed',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND deadline_type='audit_supplement_timeout' AND status IN ('pending','blocked','claimed')",['id'=>$order['id']]);
                $this->event($db,(int)$order['id'],'order_approved',(int)$actor['user_id'],['reason'=>$reason,'source'=>'mini_admin']);
            }elseif($action==='supplement'){
                $db->execute("UPDATE orders SET audit_status='supplement_required',publish_status='hidden',audit_note=:reason,supplement_due_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$supplementHours." HOUR),audited_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND lifecycle_status='active'",['reason'=>$reason,'id'=>$order['id']]);
                $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND deadline_type='audit_supplement_timeout' AND status IN ('pending','blocked','claimed')",['id'=>$order['id']]);
                $db->execute("INSERT INTO order_deadlines(order_id,deadline_type,status,version,due_at,created_at,updated_at) SELECT id,'audit_supplement_timeout','pending',version,supplement_due_at,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM orders WHERE id=:id",['id'=>$order['id']]);
                $this->event($db,(int)$order['id'],'order_supplement_required',(int)$actor['user_id'],['reason'=>$reason,'source'=>'mini_admin']);
            }elseif($action==='reject'){
                if(!empty($locked['selected_quote_id']))throw new RuntimeException('用户已选择报价的订单不能审核驳回，请使用取消或争议处理流程',409);
                $payment=$db->fetch("SELECT id,amount_cents FROM payment_orders WHERE order_id=:id AND business_type IN ('order_booking','booking_fee') AND status='paid' ORDER BY id DESC LIMIT 1 FOR UPDATE",['id'=>$order['id']]);
                $bookingCap=$payment?((int)$locked['booking_fee_cents']>0?min((int)$payment['amount_cents'],(int)$locked['booking_fee_cents']):(int)$payment['amount_cents']):0;
                $allocations=$payment?$this->bookingFeeAllocations($db,(int)$payment['id'],(int)$order['id']):['refund_cents'=>0,'outstanding_refund_cents'=>0,'runner_cents'=>0];
                $refundAmount=max(0,$bookingCap-$allocations['refund_cents']-$allocations['runner_cents']);$outstanding=$allocations['outstanding_refund_cents']+$refundAmount;
                $db->execute("UPDATE orders SET audit_status='rejected',audit_note=:reason,supplement_due_at=NULL,lifecycle_status=IF(:has_refund_lifecycle=1,'cancel_approved_pending_refund','cancelled'),publish_status='hidden',quote_status='cancelled',assignment_status='cancelled',fulfillment_status='cancelled',refund_status=IF(:has_refund_status=1,'pending_review','none'),cancelled_at=UTC_TIMESTAMP(),closed_at=IF(:close_now=1,COALESCE(closed_at,UTC_TIMESTAMP()),closed_at),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id AND lifecycle_status='active'",['reason'=>$reason,'has_refund_lifecycle'=>$outstanding>0?1:0,'has_refund_status'=>$outstanding>0?1:0,'close_now'=>$outstanding>0?0:1,'id'=>$order['id']]);
                $db->execute("UPDATE quotes SET status='cancelled',updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('active','selected')",['id'=>$order['id']]);
                $db->execute("UPDATE assignments SET status='cancelled',cancelled_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('offered','accepted')",['id'=>$order['id']]);
                $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'",['id'=>$order['id']]);
                $db->execute("UPDATE order_role_grants SET status='revoked',revoked_at=UTC_TIMESTAMP() WHERE order_id=:id AND status='active'",['id'=>$order['id']]);
                $db->execute("UPDATE order_deadlines SET status='cancelled',processed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE order_id=:id AND status IN ('pending','blocked','claimed')",['id'=>$order['id']]);
                $requestId=$db->insert("INSERT INTO order_cancel_requests(public_id,order_id,applicant_type,applicant_id,request_type,order_state_snapshot_json,reason_code,reason,status,requested_refund_cents,requested_at,closed_at,created_at,updated_at) VALUES(:public_id,:order_id,'admin',NULL,'audit_reject',:snapshot,'audit_rejected',:reason,'approved',:refund,UTC_TIMESTAMP(),IF(:close_now=1,UTC_TIMESTAMP(),NULL),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\public_id(),'order_id'=>$order['id'],'snapshot'=>json_encode($locked,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'reason'=>$reason,'refund'=>$refundAmount,'close_now'=>$outstanding>0?0:1]);
                if($payment&&$refundAmount>0)$refundId=$db->insert("INSERT INTO refunds(public_no,payment_order_id,order_id,cancel_request_id,amount_cents,reason,status,requested_by_admin_id,requested_at,created_at,updated_at) VALUES(:public_id,:payment_id,:order_id,:request_id,:amount,:reason,'pending_review',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>\business_no('RF'),'payment_id'=>$payment['id'],'order_id'=>$order['id'],'request_id'=>$requestId,'amount'=>$refundAmount,'reason'=>'审核驳回，订单自动取消：'.$reason]);
                $db->insert("INSERT INTO order_cancel_decisions(cancel_request_id,order_id,decision,responsibility,reason,refund_amount_cents,run_empty_fee_cents,refund_id,decided_by,decided_at,created_at) VALUES(:request_id,:order_id,'approved','platform',:reason,:refund,0,:refund_id,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['request_id'=>$requestId,'order_id'=>$order['id'],'reason'=>$reason,'refund'=>$refundAmount,'refund_id'=>$refundId]);
                $this->event($db,(int)$order['id'],'admin_cancelled',(int)$actor['user_id'],['reason'=>$reason,'refund_amount_cents'=>$refundAmount,'message'=>'管理员审核驳回，订单已取消并进入退款流程']);
            }elseif($action==='publish'){
                if($locked['audit_status']!=='approved'||$locked['lifecycle_status']!=='active'||!empty($locked['selected_quote_id']))throw new RuntimeException('订单未通过审核、已结束或已接受报价，禁止公开到大厅',409);
                if($db->fetchValue("SELECT 1 FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$order['id']]))throw new RuntimeException('关联测量单的施工订单仅向原测量师傅开放，不能公开到接单大厅',409);
                $db->execute("UPDATE orders SET publish_status='published',published_at=COALESCE(published_at,UTC_TIMESTAMP()),quote_deadline_at=COALESCE(quote_deadline_at,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$autoCancelDays." DAY)),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$order['id']]);
                $this->event($db,(int)$order['id'],'order_published',(int)$actor['user_id'],['reason'=>$reason,'source'=>'mini_admin']);
            }elseif($action==='edit'){
                $address=$editValues['address']!==''?$editValues['address']:(\decrypt_private_value((string)$locked['address_ciphertext'],'order-address')?:$locked['address_summary']);
                $contactName=$editValues['contact_name']!==''?$editValues['contact_name']:(\decrypt_private_value((string)$locked['contact_name_ciphertext'],'order-contact-name')?:$locked['contact_name_masked']);
                $contactMobile=$editValues['contact_mobile']!==''?$editValues['contact_mobile']:(\decrypt_private_value((string)$locked['contact_mobile_ciphertext'],'order-contact-mobile')?:$locked['contact_mobile_masked']);
                $params=['title'=>$title,'description'=>$description?:null,'quantity'=>$editValues['quantity_text']?:null,'height'=>$editValues['wall_height_text']?:null,'renovation'=>$editValues['is_renovation'],'expected'=>$editValues['expected_visit_at'],'city'=>$editValues['city']!==''?$editValues['city']:$locked['city'],'district'=>$editValues['district']!==''?$editValues['district']:$locked['district'],'address_summary'=>$address,'address_cipher'=>$address===''?null:\encrypt_private_value($address,'order-address'),'address_hmac'=>$address===''?null:\private_value_hmac($address,'order-address'),'contact_name_masked'=>$contactName,'contact_name_cipher'=>$contactName===''?null:\encrypt_private_value($contactName,'order-contact-name'),'contact_mobile_masked'=>$contactMobile,'contact_mobile_cipher'=>$contactMobile===''?null:\encrypt_private_value($contactMobile,'order-contact-mobile'),'contact_mobile_hmac'=>$contactMobile===''?null:\private_value_hmac($contactMobile,'order-contact-mobile'),'id'=>$order['id']];
                $db->execute("UPDATE orders SET title=:title,description=:description,quantity_text=:quantity,wall_height_text=:height,is_renovation=:renovation,expected_visit_at=:expected,city=:city,district=:district,address_summary=:address_summary,address_ciphertext=:address_cipher,address_hmac=:address_hmac,contact_name_masked=:contact_name_masked,contact_name_ciphertext=:contact_name_cipher,contact_mobile_masked=:contact_mobile_masked,contact_mobile_ciphertext=:contact_mobile_cipher,contact_mobile_hmac=:contact_mobile_hmac,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",$params);
                $db->execute("INSERT INTO order_addresses(order_id,province,city,district,address_summary,address_ciphertext,address_hmac,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,lat,lng,coordinate_system,created_at,updated_at) SELECT id,'',city,district,address_summary,address_ciphertext,address_hmac,contact_name_masked,contact_name_ciphertext,contact_mobile_masked,contact_mobile_ciphertext,contact_mobile_hmac,lat,lng,coordinate_system,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM orders WHERE id=:id ON DUPLICATE KEY UPDATE city=VALUES(city),district=VALUES(district),address_summary=VALUES(address_summary),address_ciphertext=VALUES(address_ciphertext),address_hmac=VALUES(address_hmac),contact_name_masked=VALUES(contact_name_masked),contact_name_ciphertext=VALUES(contact_name_ciphertext),contact_mobile_masked=VALUES(contact_mobile_masked),contact_mobile_ciphertext=VALUES(contact_mobile_ciphertext),contact_mobile_hmac=VALUES(contact_mobile_hmac),updated_at=UTC_TIMESTAMP()",['id'=>$order['id']]);
                $this->event($db,(int)$order['id'],'order_edited',(int)$actor['user_id'],['reason'=>$reason,'source'=>'mini_admin']);
            }else{
                $db->execute("UPDATE orders SET publish_status='hidden',quote_status=IF(selected_quote_id IS NULL,'hidden',quote_status),updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$order['id']]);
                $this->event($db,(int)$order['id'],'order_hidden',(int)$actor['user_id'],['reason'=>$reason,'source'=>'mini_admin']);
            }
            return $refundId;
        });
        $rejectMessage='订单已取消，预约费已进入自动退款流程';
        if($action==='reject'&&$refundId)$rejectMessage=$this->tryStartRefund((int)$refundId);
        $messages=['approve'=>'审核已通过，订单已自动公开','supplement'=>'已要求用户补充订单资料','reject'=>$rejectMessage,'publish'=>'订单已公开到接单大厅','hide'=>'订单已从接单大厅隐藏','edit'=>'订单资料已保存'];
        return ['ok'=>true,'message'=>$messages[$action]??'订单处理完成'];
    }

    public function savePlatformQuote(array $actor,array $order,int $amountCents,string $description): array
    {
        if($this->db->fetchValue("SELECT id FROM order_relations WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['order_id'=>$order['id']]))throw new MiniAdminOperationException('已关联测量单，仅原测量师傅可报价','MEASUREMENT_WORKER_BOUND',409);
        return $this->db->transaction(function(Database $db)use($actor,$order,$amountCents,$description):array{
            $locked=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$order['id']]);
            if(!$locked||(string)($locked['workflow_profile']??'construction')!=='construction'||$locked['audit_status']!=='approved'||$locked['lifecycle_status']!=='active'||!empty($locked['selected_quote_id'])||!empty($locked['current_assignment_id'])||!in_array($locked['quote_status'],['waiting','quoting','collecting'],true))throw new RuntimeException('只有普通施工订单在报价阶段且未进入履约时允许平台报价',409);
            if(!in_array((string)($locked['refund_status']??'none'),['','none'],true)||$db->fetchValue("SELECT 1 FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing') LIMIT 1",['id'=>$locked['id']])||$db->fetchValue("SELECT 1 FROM disputes WHERE order_id=:id AND status IN ('open','processing') LIMIT 1",['id'=>$locked['id']]))throw new RuntimeException('订单正在退款或争议处理中，不能新增平台报价',409);
            $rate=max(0,min(10000,(int)$locked['commission_rate_bp']));$minimum=max(1,(int)$locked['commission_min_cents']);
            $current=$db->fetch("SELECT * FROM quotes WHERE order_id=:id AND quote_type='platform' AND status='active' ORDER BY id DESC LIMIT 1 FOR UPDATE",['id'=>$locked['id']]);
            if($current){$version=(int)$current['current_version']+1;$quoteId=(int)$current['id'];$publicId=(string)$current['public_id'];$db->execute('UPDATE quotes SET amount_cents=:amount,booking_fee_cents=:booking,description=:description,current_version=:version,expires_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id',['amount'=>$amountCents,'booking'=>$locked['booking_fee_cents'],'description'=>$description?:null,'version'=>$version,'id'=>$quoteId]);}
            else{$version=1;$publicId=\public_id();$quoteId=$db->insert("INSERT INTO quotes(public_id,order_id,quote_type,created_by_admin_id,amount_cents,booking_fee_cents,description,status,current_version,expires_at,created_at,updated_at) VALUES(:public_id,:order_id,'platform',NULL,:amount,:booking,:description,'active',1,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['public_id'=>$publicId,'order_id'=>$locked['id'],'amount'=>$amountCents,'booking'=>$locked['booking_fee_cents'],'description'=>$description?:null]);}
            $db->insert("INSERT INTO quote_versions(quote_id,version_no,amount_cents,booking_fee_cents,commission_rate_bp_snapshot,commission_min_cents_snapshot,rule_acknowledged_at,description,change_reason,operator_type,operator_id,created_at) VALUES(:quote_id,:version,:amount,:booking,:rate,:minimum,UTC_TIMESTAMP(),:description,:reason,'mini_admin',:operator,UTC_TIMESTAMP())",['quote_id'=>$quoteId,'version'=>$version,'amount'=>$amountCents,'booking'=>$locked['booking_fee_cents'],'rate'=>$rate,'minimum'=>$minimum,'description'=>$description?:null,'reason'=>$version>1?'小程序管理员修改平台报价':'小程序管理员新增平台报价','operator'=>$actor['user_id']]);
            $db->execute("UPDATE orders SET quote_status='quoting',commission_rate_bp=:rate,commission_min_cents=:minimum,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['rate'=>$rate,'minimum'=>$minimum,'id'=>$locked['id']]);
            $this->event($db,(int)$locked['id'],'platform_quoted',(int)$actor['user_id'],['quote_id'=>$publicId,'amount_cents'=>$amountCents,'source'=>'mini_admin']);
            return ['id'=>$publicId,'amount_cents'=>$amountCents,'version'=>$version];
        });
    }

    public function createShare(array $actor,array $order,array $command): array
    {
        $type=(string)$command['share_type'];$validDays=(int)$command['valid_days'];$expiresAt=gmdate('Y-m-d H:i:s',time()+$validDays*86400);$plainToken=rtrim(strtr(base64_encode(random_bytes($type==='ordinary'?24:32)),'+/','-_'),'=');
        $result=$this->db->transaction(function(Database $db)use($actor,$order,$type,$expiresAt,$plainToken,$command):array{
            $locked=$db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$order['id']]);
            if(!$locked||$locked['audit_status']!=='approved'||$locked['lifecycle_status']!=='active')throw new RuntimeException('只有审核通过且进行中的订单可以分享',409);
            if($type==='worker_invite'){
                if((string)($locked['workflow_profile']??'construction')!=='construction')throw new RuntimeException('测量单不支持平台派单邀请',409);
                if($db->fetchValue("SELECT id FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$locked['id']]))throw new RuntimeException('关联测量施工仅由原测量师傅处理，不能创建平台派单邀请',409);
                if(!in_array((string)($locked['refund_status']??'none'),['','none'],true)||$db->fetchValue("SELECT 1 FROM order_cancel_requests WHERE order_id=:id AND status IN ('pending','processing') LIMIT 1",['id'=>$locked['id']])||$db->fetchValue("SELECT 1 FROM disputes WHERE order_id=:id AND status IN ('open','processing') LIMIT 1",['id'=>$locked['id']]))throw new RuntimeException('订单正在退款或争议处理中，不能创建平台派单邀请',409);
            }
            if($type==='ordinary'){
                if($db->fetchValue("SELECT id FROM order_relations WHERE child_order_id=:id AND relation_type='measurement_construction' AND status='active' LIMIT 1",['id'=>$locked['id']]))throw new RuntimeException('已关联测量单的施工订单不可普通分享，请由原测量师傅处理',409);
                if($locked['publish_status']!=='published'||$locked['payment_status']!=='paid'||!in_array((string)($locked['refund_status']??'none'),['','none'],true)||!in_array($locked['quote_status'],['waiting','quoting','collecting'],true)||!empty($locked['selected_quote_id'])||!empty($locked['assigned_worker_id'])||!empty($locked['current_assignment_id']))throw new RuntimeException('普通分享仅适用于当前仍公开报价且未进入退款/履约的订单',409);
                $db->execute("UPDATE share_contexts SET status='revoked' WHERE order_id=:id AND context_type='ordinary_order' AND status='active'",['id'=>$locked['id']]);
                $shareId=$db->insert("INSERT INTO share_contexts(order_id,context_type,token_hash,created_by_type,created_by_id,snapshot_json,status,expires_at,created_at) VALUES(:order_id,'ordinary_order',:token_hash,'mini_admin',:creator,:snapshot,'active',:expires,NOW())",['order_id'=>$locked['id'],'token_hash'=>hash('sha256',$plainToken),'creator'=>$actor['user_id'],'snapshot'=>json_encode(['public_no'=>$locked['public_no']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'expires'=>$expiresAt]);
                return ['target_id'=>$shareId,'path'=>'/pages/workerOrder/detail?id='.rawurlencode($locked['public_no']).'&share=ordinary&share_token='.rawurlencode($plainToken)];
            }
            $quote=!empty($locked['selected_quote_id'])?$db->fetch("SELECT q.id,q.quote_type,s.amount_cents_snapshot,s.booking_fee_cents_snapshot FROM quotes q JOIN quote_selections s ON s.quote_id=q.id AND s.order_id=q.order_id WHERE q.id=:quote_id AND s.status='confirmed' ORDER BY s.id DESC LIMIT 1 FOR UPDATE",['quote_id'=>$locked['selected_quote_id']]):null;
            $payment=$db->fetch("SELECT id,amount_cents FROM payment_orders WHERE order_id=:id AND business_type IN ('order_booking','booking_fee') AND status='paid' ORDER BY id DESC LIMIT 1 FOR UPDATE",['id'=>$locked['id']]);
            if(!$quote||$quote['quote_type']!=='platform'||!$payment||$locked['payment_status']!=='paid')throw new RuntimeException('用户接受平台报价且预约费到账后才能邀请师傅',409);
            if($db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:id AND status IN ('offered','accepted','completed')",['id'=>$locked['id']]))throw new RuntimeException('已有师傅领取或接单，不能重新邀请',409);
            $rawRate=$command['commission_rate'];$rate=max(0,min(10000,(int)round(((float)($rawRate===null?($locked['commission_rate_bp']/100):$rawRate))*100)));$minimum=max(1,(int)$locked['commission_min_cents']);$reason=(string)$command['reason'];
            $db->execute("UPDATE invite_tokens SET status='revoked' WHERE order_id=:id AND status='active'",['id'=>$locked['id']]);$db->execute("UPDATE commission_rule_versions SET status='superseded' WHERE order_id=:id AND status='active'",['id'=>$locked['id']]);
            $version=(int)$db->fetchValue('SELECT COALESCE(MAX(version_no),0)+1 FROM commission_rule_versions WHERE order_id=:id',['id'=>$locked['id']]);
            $ruleId=$db->insert("INSERT INTO commission_rule_versions(order_id,version_no,rate_bp,min_fee_cents,adjustment_type,adjustment_reason,created_by_admin_id,status,created_at) VALUES(:order_id,:version,:rate,:minimum,'invite_adjustment',:reason,NULL,'active',UTC_TIMESTAMP())",['order_id'=>$locked['id'],'version'=>$version,'rate'=>$rate,'minimum'=>$minimum,'reason'=>$reason]);
            $inviteId=$db->insert("INSERT INTO invite_tokens(order_id,token_hash,purpose,created_by_admin_id,commission_rule_version_id,status,max_claims,claim_count,expires_at,created_at) VALUES(:order_id,:hash,'worker_assignment',NULL,:rule_id,'active',1,0,:expires,UTC_TIMESTAMP())",['order_id'=>$locked['id'],'hash'=>hash('sha256',$plainToken),'rule_id'=>$ruleId,'expires'=>$expiresAt]);
            $db->execute("UPDATE orders SET commission_rate_bp=:rate,commission_min_cents=:minimum,commission_rule_version_id=:rule,updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['rate'=>$rate,'minimum'=>$minimum,'rule'=>$ruleId,'id'=>$locked['id']]);
            $this->event($db,(int)$locked['id'],'worker_invite_created',(int)$actor['user_id'],['source'=>'mini_admin','expires_at'=>$expiresAt]);
            return ['target_id'=>$inviteId,'path'=>'/pages/workerOrder/detail?invite='.rawurlencode($plainToken)];
        });
        $codeData='';$codeError='';
        try{$codeData=(new MiniProgramCodeService())->generate((int)$order['id'],$type,$result['path'])['data_uri'];}catch(Throwable $exception){$codeError=$exception->getMessage();}
        return ['ok'=>true,'message'=>$type==='ordinary'?'普通订单分享已生成':'邀请师傅分享已生成','data'=>[
            'share_type'=>$type,'path'=>$result['path'],'code_data_uri'=>$codeData,'code_error'=>$codeError,'expires_at'=>$this->localTime($expiresAt),
        ]];
    }

    private function tryStartRefund(int $refundId): string
    {
        $message='订单已取消，预约费已进入自动退款流程';
        $refund=$this->db->fetch("SELECT r.*,p.public_no payment_no,p.amount_cents payment_total,p.api_version FROM refunds r JOIN payment_orders p ON p.id=r.payment_order_id WHERE r.id=:id AND r.status='pending_review' LIMIT 1",['id'=>$refundId]);
        if(!$refund)return $message;
        $pay=new WeChatPayService((string)($refund['api_version']??'v3'));
        if(!$pay->enabled())return $message;
        $claimed=$this->db->execute("UPDATE refunds SET status='approved',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending_review'",['id'=>$refundId]);
        try{
            if($claimed!==1)throw new RuntimeException('退款单已被其他任务领取');
            $response=$pay->createRefund((string)$refund['payment_no'],(string)$refund['public_no'],(int)$refund['amount_cents'],(int)$refund['payment_total'],(string)$refund['reason']);
            $this->db->transaction(function(Database $db)use($refundId,$refund,$response):void{
                $db->execute("UPDATE refunds SET status='processing',wechat_refund_id=:wechat_id,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='approved'",['wechat_id'=>$response['refund_id']??null,'id'=>$refundId]);
                if($refund['order_id'])$db->execute("UPDATE orders SET refund_status='processing',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",['id'=>$refund['order_id']]);
            });
            return '订单已取消，微信已受理预约费退款，最终以微信退款通知为准';
        }catch(Throwable $exception){
            if($claimed===1)$this->db->execute("UPDATE refunds SET status='pending_review',updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='approved'",['id'=>$refundId]);
            return '订单已取消，退款已登记；微信接口暂未受理，系统将自动重试';
        }
    }

    private function bookingFeeAllocations(Database $db,int $paymentId,int $orderId): array
    {
        $rows=$db->fetchAll("SELECT id,amount_cents,status FROM refunds WHERE payment_order_id=:payment_id AND status NOT IN ('failed','closed','cancelled','rejected') FOR UPDATE",['payment_id'=>$paymentId]);
        $refund=0;$outstanding=0;
        foreach($rows as $row){$amount=max(0,(int)$row['amount_cents']);$refund+=$amount;if($row['status']!=='succeeded')$outstanding+=$amount;}
        $runner=(int)$db->fetchValue("SELECT COALESCE(SUM(l.amount_cents),0) FROM worker_balance_ledger l INNER JOIN disputes d ON d.id=l.business_id WHERE l.business_type='dispute' AND l.entry_type='run_empty_fee' AND l.status='effective' AND d.order_id=:order_id",['order_id'=>$orderId]);
        return ['refund_cents'=>$refund,'outstanding_refund_cents'=>$outstanding,'runner_cents'=>max(0,$runner)];
    }

    private function event(Database $db,int $orderId,string $type,int $actorId,array $payload): void
    {
        if (function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true) {
            (new DatabaseOrderConversationBridge($db,true))->schedule($orderId);
        }
        $payload['operator_channel']='mini_admin';$payload['operator_user_id']=$actorId;
        $eventKey=$type.':'.$orderId.':'.bin2hex(random_bytes(6));
        $db->insert('INSERT INTO order_events(order_id,event_type,event_key,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,:type,:event_key,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())',[
            'order_id'=>$orderId,'type'=>$type,'event_key'=>$eventKey,'actor_type'=>'mini_admin','actor_id'=>$actorId,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        OrderNotificationService::queue($db,$orderId,$eventKey,$type,$payload);
    }

    private function localTime($value): ?string
    {
        if(!$value)return null;
        try{return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');}
        catch(Throwable $exception){return (string)$value;}
    }
}

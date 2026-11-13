<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PlatformOrderAdminRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;

/**
 * 平台托管单公共资料与人工发布守卫。
 *
 * 创建时把多类目和当前 order_submit 动态答案写成与普通/代下单一致的施工事实；
 * 平台客户资金来自外部托管渠道，orders.payment_status 固定为 not_required，不伪装普通微信预约费已支付；
 * 正常创建后的审核/发布由 platform_auto_publish 自动推进；publish 仅用于自动任务失败或超过容忍窗口。
 * 人工修复不能绕过公共 order_submit 资料/必传图片、托管条款、外部预付款、资金串线和业务阻断。
 */
final class RepairGuardedPlatformOrderAdminRepository implements PlatformOrderAdminRepository
{
    private const STUCK_MINUTES=5;
    private PlatformOrderAdminRepository $inner;
    private Database $db;
    private PlatformOrderBookingPaymentVerifier $prepaymentVerifier;
    private PlatformOrderCommonFactsWriter $commonFacts;

    public function __construct(PlatformOrderAdminRepository $inner,Database $db)
    {
        $this->inner=$inner;
        $this->db=$db;
        $this->prepaymentVerifier=new PlatformOrderBookingPaymentVerifier();
        $timezone=(string)(function_exists('app_config')?\app_config('app.timezone','Asia/Shanghai'):'Asia/Shanghai');
        $this->commonFacts=new PlatformOrderCommonFactsWriter($db,$timezone!==''?$timezone:'Asia/Shanghai');
    }

    public function dashboard():array{return $this->inner->dashboard();}

    public function create(array $command,int $adminId):int
    {
        $categoryIds=is_array($command['category_ids']??null)?$command['category_ids']:[$command['category_id']??0];
        $categoryIds=array_values(array_unique(array_filter(array_map('intval',$categoryIds),static fn(int $id):bool=>$id>0)));
        if(!$categoryIds)throw new OrderOperationException('请选择至少一个有效服务类目。','PLATFORM_CATEGORY_REQUIRED',422);
        if(count($categoryIds)>6)throw new OrderOperationException('一个订单最多选择 6 个类目。','TOO_MANY_CATEGORIES',422);

        $categories=[];
        foreach($categoryIds as $id){
            $row=$this->db->fetch(
                "SELECT id,category_key,name,order_code FROM service_categories WHERE id=:id AND parent_id IS NULL AND status='active' LIMIT 1",
                ['id'=>$id]
            );
            if(!$row)throw new OrderOperationException('所选类目已停用，请重新选择。','CATEGORY_INVALID',422);
            $categories[]=$row;
        }

        $answers=is_array($command['answers']??null)?$command['answers']:[];
        $answers['workflow_profile']=(string)($command['fulfillment_mode']??'direct_install')==='measure_then_install'?'measurement':'construction';
        $answers['category_ids']=$categoryIds;
        $answers['category_mode']='listed';
        $answers['category_other']='';
        $answers['contact_name']=(string)($command['contact_name']??'');
        $answers['contact_mobile']=(string)($command['contact_mobile']??'');
        $answers['requirement_note']=(string)($command['description']??'');
        $command['category_id']=$categoryIds[0];
        $command['category_ids']=$categoryIds;
        $command['answers']=$answers;

        return $this->db->transaction(function(Database $db)use($command,$adminId,$categoryIds,$categories,$answers):int{
            $orderId=$this->inner->create($command,$adminId);
            $order=$db->fetch('SELECT form_schema_version_id FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderId]);
            $schemaVersionId=(int)($order['form_schema_version_id']??0);
            if($schemaVersionId<1||(int)$db->fetchValue(
                "SELECT COUNT(*) FROM form_schema_versions v INNER JOIN form_schemas s ON s.id=v.schema_id
                 WHERE v.id=:id AND v.status='published' AND BINARY s.schema_key=BINARY 'order_submit'",
                ['id'=>$schemaVersionId]
            )!==1){
                throw new OrderOperationException('平台单未绑定当前有效 order_submit 表单版本。','FORM_VERSION_EXPIRED',409);
            }

            $categoryNames=implode('、',array_map(static fn(array $row):string=>(string)$row['name'],$categories));
            $db->execute('DELETE FROM order_categories WHERE order_id=:order_id',['order_id'=>$orderId]);
            foreach($categories as $index=>$category){
                $db->insert(
                    'INSERT INTO order_categories(order_id,category_id,category_name_snapshot,sort_order,created_at) VALUES(:order_id,:category_id,:name,:sort,UTC_TIMESTAMP())',
                    ['order_id'=>$orderId,'category_id'=>(int)$category['id'],'name'=>(string)$category['name'],'sort'=>$index]
                );
            }
            $db->execute(
                "UPDATE orders SET category_id=:category_id,category_name_snapshot=:primary_name,category_names_snapshot=:names,
                        payment_status='not_required',updated_at=UTC_TIMESTAMP(),version=version+1 WHERE id=:id",
                ['category_id'=>(int)$categories[0]['id'],'primary_name'=>(string)$categories[0]['name'],'names'=>$categoryNames,'id'=>$orderId]
            );
            $this->commonFacts->write($orderId,$schemaVersionId,$categoryIds,$answers);
            return $orderId;
        });
    }

    public function publish(int $orderId,int $adminId,int $quoteDays):void
    {
        $this->db->transaction(function(Database $db)use($orderId,$adminId,$quoteDays):void{
            $order=$db->fetch(
                "SELECT o.id,o.public_no,o.form_schema_version_id,o.workflow_profile,o.audit_status,o.publish_status,o.lifecycle_status,o.refund_status,
                        o.selected_quote_id,o.assigned_worker_id,o.current_assignment_id,o.lat,o.lng,o.updated_at,o.booking_fee_cents,
                        t.customer_flow_mode,t.customer_contract_total_cents,t.booking_paid_cents,t.settlement_status
                 FROM orders o INNER JOIN order_platform_terms t ON t.order_id=o.id
                 WHERE o.id=:id AND o.order_source_type='platform' FOR UPDATE",
                ['id'=>$orderId]
            );
            if(!$order)throw new OrderOperationException('平台承接单不存在。','PLATFORM_ORDER_NOT_FOUND',404);
            if(!$this->isAutoPublishBaseCandidate($order))throw new OrderOperationException('订单状态或托管资金口径已不满足自动发布条件，不能使用人工修复发布。','PLATFORM_PUBLISH_REPAIR_STATE_CHANGED',409);
            if($order['lat']===null||$order['lng']===null)throw new OrderOperationException('施工地址缺少地图坐标，请先修复地址数据后再重新等待自动发布。','PLATFORM_LOCATION_REQUIRED',409);
            $media=OrderFormMediaRules::readiness($db,$orderId,(int)($order['form_schema_version_id']??0),(string)($order['workflow_profile']??'construction'));
            if(empty($media['ready']))throw new OrderOperationException('订单公共资料尚未完整：'.(string)$media['reason'].'。请先在订单详情补充后再等待自动发布。','PLATFORM_FORM_MEDIA_REQUIRED',409,$media);
            $this->prepaymentVerifier->verifiedPaidCents($db,$orderId,(int)$order['customer_contract_total_cents'],(int)$order['booking_paid_cents'],'platform_managed');
            if($this->hasBlockingBusiness($db,$orderId))throw new OrderOperationException('订单存在退款、争议、取消、变更或售后阻断，不能通过人工修复发布绕过业务状态。','PLATFORM_PUBLISH_REPAIR_BLOCKED',409);

            $failure=$db->fetch("SELECT id,error_message FROM scheduled_job_failures WHERE job_name='platform_auto_publish' AND business_key=:business_key AND resolved_at IS NULL ORDER BY id DESC LIMIT 1",['business_key'=>'order:'.$orderId]);
            $stuck=$this->isStuck($order);
            if(!$failure&&!$stuck)throw new OrderOperationException('当前仍属于系统自动发布正常窗口，请等待自动任务处理；手动发布仅用于自动发布失败或超时后的异常修复。','PLATFORM_PUBLISH_REPAIR_NOT_READY',409);

            $this->inner->publish($orderId,$adminId,$quoteDays);
            $db->execute("UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP() WHERE job_name='platform_auto_publish' AND business_key=:business_key AND resolved_at IS NULL",['business_key'=>'order:'.$orderId]);
            $db->execute("UPDATE orders SET audit_note='自动发布异常人工修复' WHERE id=:id AND publish_status='published'",['id'=>$orderId]);
            $payload=json_encode(['reason'=>$failure?'scheduler_failure':'publish_stuck','scheduler_failure_id'=>$failure?(int)$failure['id']:null,'scheduler_error'=>$failure?mb_substr((string)($failure['error_message']??''),0,500):null,'stuck_minutes'=>self::STUCK_MINUTES,'quote_days'=>$quoteDays],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $db->insert("INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at) VALUES(:order_id,'platform_auto_publish_repaired','admin',:actor_id,:payload,UTC_TIMESTAMP())",['order_id'=>$orderId,'actor_id'=>$adminId>0?$adminId:null,'payload'=>is_string($payload)?$payload:'{}']);
        });
    }

    public function selectQuote(int $orderId,int $quoteId,int $adminId):void{$this->inner->selectQuote($orderId,$quoteId,$adminId);}
    public function createInvite(int $orderId,int $adminId,int $validDays):array{return $this->inner->createInvite($orderId,$adminId,$validDays);}
    public function revokeInvite(int $orderId,int $inviteId,int $adminId):void{$this->inner->revokeInvite($orderId,$inviteId,$adminId);}

    private function isAutoPublishBaseCandidate(array $order):bool
    {
        if((string)($order['customer_flow_mode']??'')!=='platform_managed')return false;
        if((string)($order['settlement_status']??'')!=='pending')return false;
        if((int)($order['booking_fee_cents']??0)!==0)return false;
        if((int)($order['customer_contract_total_cents']??0)<=0)return false;
        if((int)($order['booking_paid_cents']??0)<0||(int)$order['booking_paid_cents']>(int)$order['customer_contract_total_cents'])return false;
        if((string)($order['lifecycle_status']??'')!=='active')return false;
        if((string)($order['publish_status']??'')==='published')return false;
        if(!in_array((string)($order['audit_status']??''),['pending','approved'],true))return false;
        if(!in_array((string)($order['refund_status']??'none'),['','none'],true))return false;
        return empty($order['selected_quote_id'])&&empty($order['assigned_worker_id'])&&empty($order['current_assignment_id']);
    }
    private function isStuck(array $order):bool{$updated=trim((string)($order['updated_at']??''));$ts=$updated!==''?strtotime($updated.' UTC'):false;return$ts!==false&&$ts<=time()-self::STUCK_MINUTES*60;}
    private function hasBlockingBusiness(Database $db,int $orderId):bool{return(int)$db->fetchValue("SELECT (EXISTS(SELECT 1 FROM disputes WHERE order_id=:id1 AND status IN ('open','pending','processing')) OR EXISTS(SELECT 1 FROM refunds WHERE order_id=:id2 AND status NOT IN ('failed','closed','cancelled','rejected')) OR EXISTS(SELECT 1 FROM order_cancel_requests WHERE order_id=:id3 AND status IN ('pending','processing')) OR EXISTS(SELECT 1 FROM change_requests WHERE order_id=:id4 AND status IN ('pending','processing','negotiating')) OR EXISTS(SELECT 1 FROM after_sales WHERE order_id=:id5 AND status IN ('open','processing'))) AS blocked",['id1'=>$orderId,'id2'=>$orderId,'id3'=>$orderId,'id4'=>$orderId,'id5'=>$orderId])===1;}
}

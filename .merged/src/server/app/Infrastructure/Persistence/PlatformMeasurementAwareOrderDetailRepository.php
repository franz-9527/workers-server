<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Application\Order\WorkerQuoteEligibility;
use WorkerAdmin\Contracts\OrderDetailRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;

/**
 * 平台单测量阶段只读修饰器。
 * 平台测量是同一托管订单的前置履约阶段；核价由平台完成即生效，不存在客户二次确认价格状态。
 */
final class PlatformMeasurementAwareOrderDetailRepository implements OrderDetailRepository
{
    private OrderDetailRepository $inner;
    private Database $db;
    private SettingsProvider $settings;
    private OrderMediaUrlSigner $mediaSigner;
    private string $timezone;

    public function __construct(OrderDetailRepository $inner,Database $db,SettingsProvider $settings,OrderMediaUrlSigner $mediaSigner,string $timezone='Asia/Shanghai')
    {
        $this->inner=$inner;$this->db=$db;$this->settings=$settings;$this->mediaSigner=$mediaSigner;$this->timezone=$timezone!==''?$timezone:'Asia/Shanghai';
    }

    public function view(array $actor,string $publicNo,array $context=[]):array
    {
        $view=$this->inner->view($actor,$publicNo,$context);
        $orderView=is_array($view['order']??null)?$view['order']:[];
        if(empty($orderView['is_platform_order']))return $view;
        $order=$this->db->fetch('SELECT * FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1',['public_no'=>$publicNo]);
        if(!$order||(string)($order['order_source_type']??'')!=='platform')return $view;
        $state=$this->db->fetch('SELECT * FROM order_measurement_states WHERE order_id=:order_id LIMIT 1',['order_id'=>$order['id']]);
        if(!$state)return $view;

        $viewerMode=(string)($view['viewer_mode']??'');
        $private=in_array($viewerMode,['owner','admin','accepted_worker'],true);
        if(is_array($view['measurement']??null))$view['measurement']=$this->normalizePlatformMeasurement($view['measurement'],$order,$state,$viewerMode);
        elseif($private)$view['measurement']=$this->measurementArchive($actor,$order,$state);

        $isAdmin=!empty($actor['is_admin'])||!empty($actor['business_admin']);
        $pricingPending=(string)($order['workflow_profile']??'')==='measurement'&&(string)($state['result_status']??'')==='accepted'&&(string)($order['lifecycle_status']??'')==='active';
        $pricingReview=$this->pricingReviewSummary($order,$state);
        $platform=is_array($view['platform_order']??null)?$view['platform_order']:[];$platform['pricing_review']=$pricingReview;$view['platform_order']=$platform;
        $view['actions']=is_array($view['actions']??null)?$view['actions']:[];
        $view['actions']['can_review_platform_pricing']=$isAdmin&&$pricingPending&&PlatformOrderPricingReviewReadiness::isReady($this->db,(int)$order['id']);
        $view['actions']['can_confirm_platform_pricing']=false;

        if($pricingPending){
            $view['actions']['can_work']=false;
            if((string)($order['lifecycle_status']??'')==='active'&&in_array((string)($order['refund_status']??'none'),['','none','failed'],true)&&in_array((string)($order['dispute_status']??'none'),['','none','resolved'],true)){
                $view['order']=is_array($view['order']??null)?$view['order']:[];$view['order']['status_text']='等待平台核价';$view['order']['phase_text']='等待平台核价';
            }
        }

        if((string)($order['workflow_profile']??'')==='measurement')$view['actions']=$this->restorePlatformDispatchActions($view['actions'],$actor,$order,$view);
        if(is_array($view['measurement']??null)&&(!in_array((string)($order['refund_status']??'none'),['','none','failed'],true)||!in_array((string)($order['dispute_status']??'none'),['','none','resolved'],true)||in_array((string)($view['cancel_request']['status']??''),['pending','processing'],true)||in_array((string)($view['dispute']['status']??''),['open','pending','processing'],true))){foreach(['can_edit','can_submit','can_accept','can_rework'] as $action)$view['measurement']['actions'][$action]=false;}
        return $view;
    }

    private function pricingReviewSummary(array $order,array $state):array
    {
        $review=$this->db->fetch("SELECT id,payload_json,created_at FROM order_events WHERE order_id=:order_id AND event_type='platform_measurement_pricing_reviewed' ORDER BY id DESC LIMIT 1",['order_id'=>$order['id']]);
        if(!$review){$pending=(string)($order['workflow_profile']??'')==='measurement'&&(string)($state['result_status']??'')==='accepted'&&(string)($order['lifecycle_status']??'')==='active';return ['required'=>true,'status'=>$pending?'pending':'waiting_measurement','status_text'=>$pending?'等待平台核价':'等待测量成果','can_confirm'=>false,'requires_customer_confirmation'=>false];}
        $payload=json_decode((string)$review['payload_json'],true);$payload=is_array($payload)?$payload:[];
        $confirmation=$this->db->fetch("SELECT id,created_at FROM order_events WHERE order_id=:order_id AND event_type='platform_measurement_pricing_confirmed' AND id>:review_id ORDER BY id DESC LIMIT 1",['order_id'=>$order['id'],'review_id'=>$review['id']]);
        // 新流程同事务创建确认事件；历史 reviewed 无确认事件时也按“已核价”展示，并不重新开放客户确认入口。
        $old=(int)($payload['old_customer_total_cents']??0);$new=(int)($payload['new_customer_total_cents']??0);$delta=array_key_exists('customer_delta_cents',$payload)?(int)$payload['customer_delta_cents']:$new-$old;
        return ['required'=>true,'status'=>'confirmed','status_text'=>'施工价格已确认','can_confirm'=>false,'requires_customer_confirmation'=>false,'old_customer_total_cents'=>$old,'new_customer_total_cents'=>$new,'customer_delta_cents'=>$delta,'note'=>(string)($payload['note']??''),'changed_items'=>is_array($payload['changed_items']??null)?array_values($payload['changed_items']):[],'reviewed_at'=>$this->localTime((string)$review['created_at']),'confirmed_at'=>$confirmation?$this->localTime((string)$confirmation['created_at']):$this->localTime((string)$review['created_at'])];
    }

    private function normalizePlatformMeasurement(array $measurement,array $order,array $state,string $viewerMode):array
    {
        $measurement['settlement']=null;$measurement['settlement_status']='not_applicable';$measurement['hold_until']=null;$measurement['max_hold_until']=null;$measurement['hold_extension']=null;
        $actions=is_array($measurement['actions']??null)?$measurement['actions']:[];$actions['can_create_construction']=false;$actions['can_request_hold_extension']=false;$actions['can_review_hold_extension']=false;$actions['can_accept']=false;
        if($viewerMode==='owner'&&(string)($order['workflow_profile']??'')==='measurement'&&in_array((string)($state['result_status']??''),['submitted','accepted'],true)){$used=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:order_id AND event_type='measurement_rework_requested'",['order_id'=>$order['id']]);$actions['can_rework']=$used<1;}
        $measurement['actions']=$actions;$measurement['platform_stage']=true;$measurement['awaiting_pricing_review']=(string)($order['workflow_profile']??'')==='measurement'&&(string)($state['result_status']??'')==='accepted';$measurement['rework_limit']=1;return $measurement;
    }

    private function measurementArchive(array $actor,array $order,array $state):array
    {
        $spaces=[];$rows=$this->db->fetchAll("SELECT id,public_id,name,sort_order FROM order_measurement_spaces WHERE order_id=:order_id AND status='active' ORDER BY sort_order,id",['order_id'=>$order['id']]);
        foreach($rows as $space){$images=[];$imageRows=$this->db->fetchAll("SELECT m.public_id,l.purpose,l.sort_order FROM media_links l JOIN media_files m ON m.id=l.media_file_id AND m.status='active' WHERE l.entity_type='order_measurement_space' AND l.entity_id=:space_id ORDER BY l.sort_order,l.id",['space_id'=>$space['id']]);foreach($imageRows as $image)$images[]=['id'=>(string)$image['public_id'],'purpose'=>(string)$image['purpose'],'url'=>$this->mediaSigner->sign((string)$image['public_id'],$actor,(int)$order['id'],'order_measurement_space',(int)$space['id'])];$spaces[]=['id'=>(string)$space['public_id'],'name'=>(string)$space['name'],'sort_order'=>(int)$space['sort_order'],'images'=>$images];}
        $rework=(int)$this->db->fetchValue("SELECT COUNT(*) FROM order_events WHERE order_id=:order_id AND event_type='measurement_rework_requested'",['order_id'=>$order['id']]);
        return ['service_payment_status'=>'not_applicable','result_status'=>(string)$state['result_status'],'submitted_at'=>$this->localTime($state['submitted_at']??null),'auto_accept_at'=>null,'accepted_at'=>$this->localTime($state['accepted_at']??null),'settlement_status'=>'not_applicable','hold_until'=>null,'max_hold_until'=>null,'spaces'=>$spaces,'linked_construction'=>null,'hold_extension'=>null,'settlement'=>null,'platform_stage'=>true,'awaiting_pricing_review'=>false,'rework_limit'=>1,'rework_used'=>$rework>0,'actions'=>['can_edit'=>false,'can_submit'=>false,'can_accept'=>false,'can_rework'=>false,'can_create_construction'=>false,'can_request_hold_extension'=>false,'can_review_hold_extension'=>false]];
    }

    private function restorePlatformDispatchActions(array $actions,array $actor,array $order,array $view):array
    {
        $refundBlocked=!in_array((string)($order['refund_status']??'none'),['','none','failed'],true);$disputeBlocked=!in_array((string)($order['dispute_status']??'none'),['','none','resolved'],true)||(bool)$this->db->fetchValue("SELECT id FROM disputes WHERE order_id=:order_id AND status IN ('open','pending','processing') LIMIT 1",['order_id'=>$order['id']]);
        $activeAssignments=(int)$this->db->fetchValue("SELECT COUNT(*) FROM assignments WHERE order_id=:order_id AND status IN ('offered','accepted','completed')",['order_id'=>$order['id']]);
        $managedReady=(bool)$this->db->fetchValue("SELECT 1 FROM order_platform_terms t WHERE t.order_id=:id AND t.customer_flow_mode='platform_managed' AND t.settlement_status='pending' AND t.customer_contract_total_cents>0 AND t.booking_paid_cents<=t.customer_contract_total_cents AND NOT EXISTS(SELECT 1 FROM payment_orders p WHERE p.order_id=t.order_id AND p.business_type IN ('order_booking','booking_fee') AND p.status='paid') LIMIT 1",['id'=>$order['id']])&&(int)($order['booking_fee_cents']??0)===0;
        $isAdmin=!empty($actor['is_admin'])||!empty($actor['business_admin']);
        $actions['can_admin_invite_worker']=$isAdmin&&$managedReady&&!$refundBlocked&&!$disputeBlocked&&(string)$order['audit_status']==='approved'&&(string)$order['lifecycle_status']==='active'&&$activeAssignments===0;
        $workerId=(int)($actor['worker_id']??0);if($workerId<1||!empty($view['assignment'])||$refundBlocked||$disputeBlocked||!$managedReady)return $actions;
        $worker=$this->db->fetch("SELECT w.id,w.user_id,w.verification_status,w.cloud_record_mode,u.status user_status FROM worker_profiles w JOIN users u ON u.id=w.user_id WHERE w.id=:worker_id LIMIT 1",['worker_id'=>$workerId]);if(!$worker)return $actions;
        $eligibility=new WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($this->db),$this->settings);if(!$eligibility->workerCanQuote($worker,$order,true,true,false))return $actions;
        if((string)($order['dispatch_mode']??'multi_quote')==='single_grab'){$actions['can_grab']=true;$actions['can_quote']=false;}else{$actions['can_quote']=true;$actions['can_grab']=false;}$actions['quote_disabled_reason']='';return $actions;
    }

    private function localTime(?string $value):?string
    {
        if(!$value)return null;try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');}catch(Throwable $exception){return $value;}
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderDetailRepository;
use WorkerAdmin\Contracts\WorkerAgreementVersionProvider;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Domain\Order\RepairInterventionPolicy;
use WorkerAdmin\Domain\Order\NegotiationPolicy;

/** V2.5.0 订单详情统一查询入口。 */
final class OrderDetailQuery
{
    private OrderDetailRepository $repository;
    private WorkerAgreementVersionProvider $workerAgreements;

    public function __construct(OrderDetailRepository $repository, WorkerAgreementVersionProvider $workerAgreements)
    {
        $this->repository = $repository;
        $this->workerAgreements = $workerAgreements;
    }

    /**
     * @param array<string,mixed> $actor
     * @return array<string,mixed>
     */
    public function query(array $actor,string $publicNo,?float $lat=null,?float $lng=null,string $shareToken=''): array
    {
        $publicNo=trim($publicNo);
        if($publicNo===''||strlen($publicNo)>80)throw new OrderOperationException('订单编号无效','ORDER_ID_REQUIRED',422);
        if(!$this->validCoordinate($lat,$lng)){$lat=null;$lng=null;}
        $data=$this->repository->view($actor,$publicNo,[
            'lat'=>$lat,'lng'=>$lng,'share_token'=>mb_substr(trim($shareToken),0,512),
        ]);
        $data=$this->restoreOwnerActions($data);
        $data['worker_agreement_versions']=$this->workerAgreements->currentVersions();
        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function restoreOwnerActions(array $data): array
    {
        if((string)($data['viewer_mode']??'')!=='owner')return $data;

        $order=is_array($data['order']??null)?$data['order']:[];
        $actions=is_array($data['actions']??null)?$data['actions']:[];
        $selection=is_array($data['selection']??null)?$data['selection']:null;
        $cancel=is_array($data['cancel_request']??null)?$data['cancel_request']:null;
        $dispute=is_array($data['dispute']??null)?$data['dispute']:null;
        $review=is_array($data['review']??null)?$data['review']:null;
        $assignedWorker=is_array($data['assigned_worker']??null)?$data['assigned_worker']:null;
        $appointment=is_array($data['appointment']??null)?$data['appointment']:null;
        $measurementLink=is_array($data['measurement_link']??null)?$data['measurement_link']:null;
        $runEmpty=is_array($data['run_empty_claim']??null)?$data['run_empty_claim']:null;
        $quotes=is_array($data['quotes']??null)?$data['quotes']:[];
        $changes=is_array($data['changes']??null)?$data['changes']:[];
        $afterSales=is_array($data['after_sales']??null)?$data['after_sales']:[];

        $isMeasurement=(string)($order['workflow_profile']??'construction')==='measurement';
        $isSingleGrab=!$isMeasurement&&(string)($order['dispatch_mode']??'multi_quote')==='single_grab';
        $sourceType=(string)($order['order_source_type']??'consumer');
        $isMerchant=$sourceType==='merchant';
        $isPlatform=$sourceType==='platform';
        $refundInProgress=in_array((string)($order['refund_status']??'none'),['pending_review','approved','processing','refunding'],true)
            ||(string)($order['lifecycle_status']??'')==='cancel_approved_pending_refund';
        $hasOpenCancel=$cancel&&in_array((string)($cancel['status']??''),['pending','processing'],true);
        $disputeStatus=$dispute?(string)($dispute['status']??''):'';
        $isNegotiatingDispute=$disputeStatus==='negotiating';
        $hasOpenDispute=$dispute&&in_array($disputeStatus,['negotiating','open','pending','processing'],true);
        $hasSelectedQuote=false;
        foreach($quotes as $quote){if(is_array($quote)&&!empty($quote['is_selected'])){$hasSelectedQuote=true;break;}}

        $lifecycleActive=(string)($order['lifecycle_status']??'')==='active';
        $fulfillment=(string)($order['fulfillment_status']??'');
        $payment=(string)($order['payment_status']??'');
        $quoteStatus=(string)($order['quote_status']??'');
        $receiptCount=max(0,(int)($actions['collection_receipt_count']??0));
        $acceptStage=!$isMeasurement&&!$refundInProgress&&!$hasOpenDispute&&$fulfillment==='submitted'&&$lifecycleActive;

        $actions['can_select_quote']=!$isPlatform&&!$isSingleGrab&&!$refundInProgress&&!$hasSelectedQuote&&!$assignedWorker
            &&(string)($order['audit_status']??'')==='approved'
            &&in_array((string)($order['publish_status']??''),['published','hidden'],true)
            &&in_array($quoteStatus,['waiting','quoting','collecting'],true)&&$lifecycleActive;
        $actions['can_switch_worker']=!$isPlatform&&!$isMeasurement&&!$measurementLink&&!$refundInProgress
            &&$hasSelectedQuote&&(bool)$assignedWorker&&$payment==='paid'&&$lifecycleActive
            &&!in_array($fulfillment,['scheduled','in_progress','submitted','accepted'],true)
            &&!$appointment&&!$changes&&!$hasOpenDispute;
        // 管理员代填的普通草稿会以 unpaid 出现在客户“我的下单”；客户先核对协议并 finalize，随后才能创建真实微信预约费。
        $actions['can_pay_order']=!$isPlatform&&(string)($order['audit_status']??'')==='draft'
            &&in_array($payment,['unpaid','awaiting_payment'],true)&&$lifecycleActive;
        $actions['order_requires_finalize']=$actions['can_pay_order']&&$payment==='unpaid';
        // 未付款草稿可以由订单所有者主动关闭；关闭命令会再次锁单并核验真实 payment_orders，防止与在途微信支付并发穿透。
        $actions['can_close_draft']=!$isPlatform&&(string)($order['audit_status']??'')==='draft'
            &&in_array($payment,['unpaid','awaiting_payment'],true)&&$lifecycleActive
            &&!$selection&&!$hasSelectedQuote&&!$assignedWorker&&!$hasOpenCancel&&!$refundInProgress
            &&in_array($fulfillment,['','not_started'],true);
        $actions['can_pay']=$selection&&(string)($selection['status']??'')==='pending_payment';
        $actions['can_cancel']=!$hasOpenCancel&&$lifecycleActive&&$payment==='paid'
            &&!in_array($fulfillment,['submitted','accepted'],true);

        $actions['can_decide_run_empty_claim']=!$isPlatform&&$runEmpty
            &&(string)($runEmpty['status']??'')==='pending_confirmation'
            &&in_array((string)($runEmpty['collection_mode']??''),['booking_offset','online'],true);
        $actions['can_pay_run_empty_claim']=$isMerchant&&$runEmpty&&(string)($runEmpty['status']??'')==='awaiting_payment';

        $actions['can_accept_completion']=$acceptStage;
        $actions['accept_ready']=$acceptStage&&$receiptCount>0;
        $actions['accept_waiting_receipt']=$acceptStage&&$receiptCount<1;
        $actions['accept_blocked_reason']=$actions['accept_waiting_receipt']
            ?'师傅尚未上传收款凭证；凭证齐全且无争议后即可验收，达到自动验收期限时系统也会按同一条件自动处理。':'';
        $actions['can_open_dispute']=!$refundInProgress&&!$hasOpenDispute&&$fulfillment==='submitted'&&$lifecycleActive;
        $disputeEligibility=NegotiationPolicy::evaluate(
            NegotiationPolicy::disputeStatus($dispute['resolution']??null),
            $dispute['opened_at']??null,
            (string)(function_exists('app_config')?\app_config('app.timezone','Asia/Shanghai'):'Asia/Shanghai')
        );
        $actions['can_escalate_dispute']=$isNegotiatingDispute&&$lifecycleActive&&!empty($disputeEligibility['eligible']);
        $actions['can_resolve_dispute']=$isNegotiatingDispute&&$lifecycleActive;
        $actions['can_review']=$fulfillment==='accepted'&&!$review;

        if(!$isMeasurement){
            $actions['can_request_repair']=!empty($actions['can_request_repair'])
                &&(int)($order['warranty_months']??0)>0
                &&$this->futureLocalTime((string)($order['warranty_expires_at']??''));
        }

        $repairEligibility=['eligible'=>false,'reason'=>'no_active_repair','available_at'=>null,'wait_hours'=>RepairInterventionPolicy::WAIT_HOURS];
        foreach($afterSales as $afterSale){
            if(!is_array($afterSale)||(string)($afterSale['type']??'')!=='warranty_repair'
                ||!in_array((string)($afterSale['status']??''),['open','processing'],true))continue;
            $timezone=function_exists('app_config')?(string)\app_config('app.timezone','Asia/Shanghai'):'Asia/Shanghai';
            $repairEligibility=RepairInterventionPolicy::evaluate(
                (string)($afterSale['status']??''),
                isset($afterSale['resolution'])?(string)$afterSale['resolution']:null,
                isset($afterSale['opened_at'])?(string)$afterSale['opened_at']:null,
                $timezone!==''?$timezone:'Asia/Shanghai'
            );
            break;
        }
        $actions['can_request_repair_intervention']=!empty($repairEligibility['eligible']);
        $actions['repair_intervention_reason']=(string)($repairEligibility['reason']??'');
        $actions['repair_intervention_wait_hours']=(int)($repairEligibility['wait_hours']??RepairInterventionPolicy::WAIT_HOURS);
        $actions['repair_intervention_available_at']=$repairEligibility['available_at']??null;

        $data['actions']=$actions;
        return $data;
    }

    private function futureLocalTime(string $value): bool
    {
        $value=trim($value);
        if($value===''||$value==='—')return false;
        try{
            $timezone=function_exists('app_config')?(string)\app_config('app.timezone','Asia/Shanghai'):'Asia/Shanghai';
            $zone=new \DateTimeZone($timezone!==''?$timezone:'Asia/Shanghai');
            return new \DateTimeImmutable($value,$zone)>new \DateTimeImmutable('now',$zone);
        }catch(\Throwable $exception){return false;}
    }

    private function validCoordinate(?float $lat,?float $lng): bool
    {
        return $lat!==null&&$lng!==null&&is_finite($lat)&&is_finite($lng)
            &&$lat>=-90&&$lat<=90&&$lng>=-180&&$lng<=180;
    }
}

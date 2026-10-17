<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderDisputeRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class OrderDispute
{
    private OrderDisputeRepository $repository;
    public function __construct(OrderDisputeRepository $repository){$this->repository=$repository;}
    public function execute(string $route,array $actor,array $body):array
    {
        if($route==='disputes.create')return$this->start($actor,$body);
        if($route==='disputes.escalate')return$this->escalate($actor,$body);
        if($route==='disputes.resolve')return$this->resolve($actor,$body);
        if($route==='admin.dispute.resolve')return$this->resolveByAdmin($actor,$body);
        throw new OrderOperationException('争议操作不存在','ORDER_DISPUTE_ROUTE_NOT_FOUND',404);
    }
    private function start(array $actor,array $body):array
    {
        $orderNo=trim((string)($body['order_id']??''));if($orderNo==='')throw new OrderOperationException('订单编号缺失','ORDER_ID_REQUIRED',422);
        $description=mb_substr(trim((string)($body['description']??'')),0,2000);if(mb_strlen($description)<4)throw new OrderOperationException('请说明完工质量或现场异议的具体情况','DISPUTE_DESCRIPTION_REQUIRED',422);
        $raw=is_array($body['evidence_media_ids']??null)?$body['evidence_media_ids']:[];$evidence=[];foreach(array_slice($raw,0,6)as$value){$value=trim((string)$value);if($value!==''&&!in_array($value,$evidence,true))$evidence[]=$value;}if(!$evidence)throw new OrderOperationException('请至少上传一张现场或完工证据图片','DISPUTE_EVIDENCE_REQUIRED',422);
        $data=$this->repository->startNegotiation($actor,$orderNo,$description,$evidence);return['message'=>'异议已记录，自动验收已暂停。请先与师傅沟通处理；仍无法解决时再申请平台介入。','data'=>$data];
    }
    private function escalate(array $actor,array $body):array{$disputeNo=trim((string)($body['dispute_id']??''));if($disputeNo==='')throw new OrderOperationException('异议编号缺失','DISPUTE_ID_REQUIRED',422);$note=mb_substr(trim((string)($body['note']??'')),0,1000);$data=$this->repository->escalate($actor,$disputeNo,$note);return['message'=>'已申请平台介入，平台将结合双方记录和证据处理。','data'=>$data];}
    private function resolve(array $actor,array $body):array{$disputeNo=trim((string)($body['dispute_id']??''));if($disputeNo==='')throw new OrderOperationException('异议编号缺失','DISPUTE_ID_REQUIRED',422);$note=mb_substr(trim((string)($body['note']??'')),0,1000);$data=$this->repository->resolveByAgreement($actor,$disputeNo,$note);return['message'=>'已记录双方自行解决，订单恢复验收流程。','data'=>$data];}
    private function resolveByAdmin(array $actor,array $body):array
    {
        $orderNo=trim((string)($body['order_id']??''));if($orderNo===''||strlen($orderNo)>80)throw new OrderOperationException('订单编号缺失','ORDER_ID_REQUIRED',422);
        $resolution=mb_substr(trim((string)($body['resolution']??'')),0,1000);if(mb_strlen($resolution)<2)throw new OrderOperationException('请填写争议处理结果','RESOLUTION_REQUIRED',422);
        $responsibility=trim((string)($body['responsibility']??'unclear'));if(!in_array($responsibility,['user','worker','platform','shared','unclear'],true))throw new OrderOperationException('责任归属无效','INVALID_RESPONSIBILITY',422);
        $refundCents=max(0,(int)($body['refund_amount_cents']??0));$runEmptyCents=max(0,(int)($body['run_empty_fee_cents']??0));$channel=trim((string)($actor['channel']??''));
        if($channel===''||$channel==='mini_admin'){$userId=max(0,(int)($actor['user_id']??0));if($userId<1)throw new OrderOperationException('管理员身份无效','ADMIN_REQUIRED',403);if($refundCents>0||$runEmptyCents>0)throw new OrderOperationException('涉及退款或跑空费金额，请到网页后台处理','DISPUTE_ADMIN_FINANCIAL_REVIEW_REQUIRED',409);$operator=['channel'=>'mini_admin','user_id'=>$userId];}
        elseif($channel==='web_admin'){$adminId=max(0,(int)($actor['admin_user_id']??0));if($adminId<1)throw new OrderOperationException('管理员身份无效','ADMIN_REQUIRED',403);$operator=['channel'=>'web_admin','admin_user_id'=>$adminId];}
        else throw new OrderOperationException('管理员操作来源无效','ADMIN_REQUIRED',403);
        $data=$this->repository->resolveByAdmin($operator,$orderNo,$resolution,$responsibility,$refundCents,$runEmptyCents);
        return['message'=>($refundCents>0||$runEmptyCents>0)?'争议已裁定，相关退款/跑空费已按资金规则登记并通知双方':'争议已处理并通知双方','data'=>$data];
    }
}

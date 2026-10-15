<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Contracts\OrderCenterRepository;
use WorkerAdmin\Contracts\OrderMediaUrlSigner;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class OrderCenterQuery
{
    private OrderCenterRepository $repository;
    private PrivateValueCipher $cipher;
    private OrderMediaUrlSigner $mediaSigner;
    private string $timezone;

    public function __construct(OrderCenterRepository $repository, PrivateValueCipher $cipher, OrderMediaUrlSigner $mediaSigner, string $timezone = 'Asia/Shanghai')
    {
        $this->repository=$repository; $this->cipher=$cipher; $this->mediaSigner=$mediaSigner; $this->timezone=$timezone!==''?$timezone:'Asia/Shanghai';
    }

    public function query(array $actor, string $kind, string $status, int $page, string $keyword = ''): array
    {
        if (!in_array($kind, ['mine','worker'], true)) throw new OrderOperationException('订单中心类型无效','ORDER_CENTER_KIND_INVALID',422);
        if (!in_array($status, ['all','todo','supplement','quoting','progress','completed','refunding'], true)) throw new OrderOperationException('订单筛选状态无效','ORDER_CENTER_STATUS_INVALID',422);
        if ($kind === 'worker' && empty($actor['worker_id'])) return ['items'=>[],'page'=>max(1,$page),'status'=>$status,'keyword'=>'','has_more'=>false,'supplement_count'=>0,'todo_count'=>0];
        $keyword = $kind === 'mine' ? $this->normalizeKeyword($keyword) : '';
        $result = $this->repository->page($actor,$kind,$status,max(1,$page),20,$keyword);
        $items=[];
        foreach ($result['rows'] as $order) {
            $isOwner=$kind==='mine' && (int)$order['user_id']===(int)$actor['user_id'];
            $items[]=$this->card($order,$actor,$kind,$isOwner);
        }
        return ['items'=>$items,'page'=>max(1,$page),'status'=>$status,'keyword'=>$keyword,'has_more'=>(bool)$result['has_more'],'supplement_count'=>$this->repository->supplementCount($actor,$kind),'todo_count'=>$this->repository->todoCount($actor,$kind)];
    }

    private function normalizeKeyword(string $keyword): string
    {
        $value=trim((string)(preg_replace('/[\x00-\x1F\x7F]+/u',' ',$keyword)??$keyword));
        if ($value==='') return '';
        return function_exists('mb_substr')?(string)mb_substr($value,0,80,'UTF-8'):substr($value,0,80);
    }

    private function card(array $order,array $actor,string $kind,bool $isOwner): array
    {
        $workflow=(string)($order['workflow_profile']??'construction');
        $address=$isOwner?($this->cipher->decrypt((string)($order['address_ciphertext']??''),'order-address')?: (string)($order['address_summary']??'')):$this->maskAddress((string)($order['address_summary']??''));
        return [
            'id'=>(string)$order['public_no'], 'title'=>(string)($order['title']??''), 'description'=>(string)($order['description']??''),
            'workflow_profile'=>$workflow, 'business_type'=>$workflow==='measurement'?'measurement':'construction', 'order_source_type'=>(string)($order['order_source_type']??'consumer'),
            'dispatch_mode'=>(string)($order['dispatch_mode']??'multi_quote'), 'expected_fee_type'=>(string)($order['expected_fee_type']??''),
            'expected_fee_cents'=>$order['expected_fee_cents']===null?null:(int)$order['expected_fee_cents'], 'expected_fee_text'=>(string)($order['expected_fee_text']??''),
            'category_name'=>$workflow==='measurement'?'上门量尺':(string)((($order['category_names_snapshot']??'')!=='')?$order['category_names_snapshot']:($order['category_name_snapshot']??'')),
            'district'=>(string)($order['district']??''), 'address'=>$address, 'display_address'=>$isOwner?$address:trim((string)($order['district']??'').' '.$address),
            'is_owner'=>$isOwner, 'is_invited'=>!empty($order['is_invited']), 'invite_requires_quote'=>!empty($order['invite_requires_quote']), 'quantity_text'=>(string)($order['quantity_text']??''),
            'expected_visit_at'=>$this->expectedVisitLabel($order['expected_visit_at']??null)?:'接单后与业主预约', 'created_at'=>$this->localTime($order['created_at']??null),
            'published_at'=>$this->localTime($order['published_at']??null), 'updated_at'=>$this->localTime($order['updated_at']??null), 'sort_at'=>(string)($order['updated_at']??$order['created_at']??''),
            'quote_count'=>(int)($order['quote_count']??0), 'own_quote_cents'=>$order['own_quote_cents']===null?null:(int)$order['own_quote_cents'],
            'fulfillment_status'=>(string)($order['fulfillment_status']??'not_started'), 'assignment_status'=>(string)($order['assignment_status']??'unassigned'),
            'lifecycle_status'=>(string)($order['lifecycle_status']??'active'), 'audit_status'=>(string)($order['audit_status']??''), 'publish_status'=>(string)($order['publish_status']??''),
            'quote_status'=>(string)($order['quote_status']??''), 'payment_status'=>(string)($order['payment_status']??''), 'refund_status'=>(string)($order['refund_status']??'none'),
            'selected_quote_id'=>empty($order['selected_quote_id'])?null:(int)$order['selected_quote_id'], 'center_kind'=>$kind, 'detail_page'=>$kind==='mine'?'user':'worker',
            'active_repair_status'=>(string)($order['active_repair_status']??''), 'repair_attention'=>(string)($order['active_repair_status']??'')!=='',
            'platform_pricing_reviewed'=>!empty($order['platform_pricing_reviewed']), 'platform_pricing_confirmation_pending'=>!empty($order['platform_pricing_confirmation_pending']),
            'platform_pricing_confirmed'=>!empty($order['platform_pricing_confirmed']), 'service_appointment_exists'=>!empty($order['service_appointment_exists']), 'construction_appointment_todo'=>false,
            'cover_url'=>!empty($order['cover_media_id'])?$this->mediaSigner->sign((string)$order['cover_media_id'],$actor,(int)$order['id']):''
        ];
    }

    private function maskAddress(string $address): string { return $address; }
    private function localTime($value): ?string { if(!$value)return null; try{return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($this->timezone))->format('Y-m-d H:i');}catch(Throwable $e){return (string)$value;} }
    private function expectedVisitLabel($value): ?string { if(!$value)return null; return (string)$value; }
}
<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Chat;

/** PHP is the only interpreter of order/assignment states. Input is server-owned facts. */
final class OrderConversationPolicy
{
    /** A null result means no new grant. Existing grants must be closed by the bridge. */
    public function evaluate(array $facts): ?array
    {
        foreach (['order_id','customer_id','assignment_id','worker_id','worker_user_id'] as $key) {
            if (!$this->id($facts[$key] ?? null)) return null;
        }
        if ((string)($facts['current_assignment_id'] ?? '') !== (string)$facts['assignment_id']
            || (string)($facts['assignment_order_id'] ?? '') !== (string)$facts['order_id']
            || (string)($facts['assigned_worker_id'] ?? '') !== (string)$facts['worker_id']
            || (string)$facts['customer_id'] === (string)$facts['worker_user_id']) return null;
        if (($facts['is_demo'] ?? null) !== 0 || ($facts['customer_status'] ?? '') !== 'active'
            || ($facts['worker_user_status'] ?? '') !== 'active') return null;
        if (!in_array($facts['workflow_profile'] ?? '', ['construction','measurement'],true)
            || !in_array($facts['order_source_type'] ?? '', ['consumer','merchant'],true)) return null;
        if(array_key_exists('repair_episode',$facts)) {
            return is_array($facts['repair_episode'])?$this->repair($facts,$facts['repair_episode']):null;
        }
        // An offered/unaccepted assignment never creates order chat.
        if (($facts['assignment_record_status'] ?? '') !== 'accepted'
            || ($facts['assignment_status'] ?? '') !== 'accepted'
            || empty($facts['assignment_accepted_at']) || ($facts['payment_status'] ?? '') !== 'paid') return null;
        $lifecycle = (string)($facts['lifecycle_status'] ?? '');
        $open = $lifecycle === 'active';
        if (!$open && !in_array($lifecycle,['completed','cancelled','closed','refunded','cancel_approved_pending_refund'],true)) return null;
        return $this->snapshot($facts,$open,'assignment:'.$facts['assignment_id'].':service');
    }

    private function repair(array $facts,array $episode): ?array
    {
        foreach(['id','after_sale_id'] as $key) if(!$this->id($episode[$key]??null))return null;
        foreach(['order_id'=>'order_id','assignment_id'=>'assignment_id','worker_id'=>'worker_id','accepted_user_id'=>'worker_user_id',
            'repair_order_id'=>'order_id','repair_user_id'=>'customer_id'] as $key=>$fact) {
            if((string)($episode[$key]??'')!==(string)$facts[$fact])return null;
        }
        if(($facts['workflow_profile']??'')!=='construction' || ($facts['lifecycle_status']??'')!=='completed'
            || ($facts['payment_status']??'')!=='paid'
            || !in_array($facts['assignment_record_status']??'',['accepted','completed'],true)
            || !in_array($facts['assignment_status']??'',['accepted','completed'],true) || empty($facts['assignment_accepted_at'])
            || ($episode['state']??'')!=='active' || empty($episode['accepted_at']) || !empty($episode['closed_at'])
            || ($episode['repair_type']??'')!=='warranty_repair' || ($episode['repair_status']??'')!=='processing'
            || !empty($episode['repair_closed_at']))return null;
        return $this->snapshot($facts,true,'assignment:'.$facts['assignment_id'].':repair:'.$episode['id']);
    }

    private function snapshot(array $facts,bool $open,string $episode): array
    {
        $order=(string)$facts['order_id'];$assignment=(string)$facts['assignment_id'];
        return [
            'schema_version'=>1,'scope_key'=>'order:'.$order.':assignment:'.$assignment,
            'order_id'=>$order,'assignment_id'=>$assignment,'episode_key'=>$episode,
            'state'=>$open?'open':'readonly','can_invite'=>false,
            'members'=>[
                ['actor_id'=>(string)$facts['customer_id'],'kind'=>'mini','role'=>'customer','can_read'=>true,'can_send'=>$open],
                ['actor_id'=>(string)$facts['worker_user_id'],'kind'=>'mini','role'=>'worker','can_read'=>true,'can_send'=>$open],
            ],
        ];
    }

    private function id($value): bool
    {
        if (!is_string($value) && !is_int($value)) return false;
        $id=(string)$value;
        return preg_match('/^[1-9][0-9]{0,19}$/D',$id)===1
            && (strlen($id)<20 || strcmp($id,'18446744073709551615')<=0);
    }
}

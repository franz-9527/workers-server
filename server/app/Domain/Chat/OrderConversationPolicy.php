<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Chat;

/** PHP is the only interpreter of order/assignment states. Input is server-owned facts. */
final class OrderConversationPolicy
{
    /** PHP-owned platform policy; staff authority is intersected again by Go. */
    public function evaluatePlatform(array $facts): ?array
    {
        foreach (['order_id','customer_id','assignment_id','worker_id','worker_user_id'] as $key) {
            if (!$this->id($facts[$key] ?? null)) return null;
        }
        if (($facts['order_source_type'] ?? '') !== 'platform'
            || ($facts['customer_flow_mode'] ?? '') !== 'platform_managed'
            || ($facts['is_demo'] ?? null) !== 0 || ($facts['booking_fee_cents'] ?? null) !== 0
            || ($facts['payment_status'] ?? '') !== 'not_required'
            || !in_array($facts['workflow_profile'] ?? '', ['construction','measurement'], true)
            || (string)($facts['current_assignment_id'] ?? '') !== (string)$facts['assignment_id']
            || (string)($facts['assignment_order_id'] ?? '') !== (string)$facts['order_id']
            || (string)($facts['assigned_worker_id'] ?? '') !== (string)$facts['worker_id']
            || ($facts['worker_user_status'] ?? '') !== 'active'
            || empty($facts['assignment_accepted_at'])
            || (string)$facts['customer_id'] === (string)$facts['worker_user_id']
            || !$this->id($facts['staff_config_version'] ?? null)) return null;
        $lifecycle = (string)($facts['lifecycle_status'] ?? '');
        $episode = 'assignment:'.$facts['assignment_id'].':service';
        $repair = array_key_exists('repair_episode',$facts);
        if ($repair) {
            $accepted = is_array($facts['repair_episode']) ? $this->repair($facts,$facts['repair_episode'],true) : null;
            if (!$accepted) return null;
            $episode = $accepted['episode_key'];
        }
        $open = $lifecycle === 'active' || $repair;
        if (!$open && !in_array($lifecycle, ['completed','cancelled','closed','refunded','cancel_approved_pending_refund'], true)) return null;
        $statuses = $lifecycle === 'active' ? ['accepted','info_released'] : ['accepted','info_released','completed','cancelled'];
        if (!in_array($facts['assignment_status'] ?? '', $statuses, true)
            || !in_array($facts['assignment_record_status'] ?? '', $statuses, true)) return null;
        $members = [];
        if (($facts['customer_status'] ?? '') === 'active') {
            if ((string)$facts['customer_id'] === (string)$facts['worker_user_id']) return null;
            $members[] = $this->member((string)$facts['customer_id'], 'customer', $open);
        } elseif (($facts['customer_status'] ?? '') !== 'platform_unbound') return null;
        $members[] = $this->member((string)$facts['worker_user_id'], 'worker', $open);
        $staff = $facts['platform_staff'] ?? null;
        if (!is_array($staff) || count($staff) > 8) return null;
        $seen = array_fill_keys(array_column($members, 'actor_id'), true);
        foreach ($staff as $person) {
            if (!is_array($person) || !$this->id($person['actor_id'] ?? null)
                || ($person['kind'] ?? '') !== 'mini' || ($person['status'] ?? '') !== 'active'
                || ($person['is_mini_admin'] ?? null) !== true || !$this->id($person['authority_version'] ?? null)) return null;
            $id = (string)$person['actor_id'];
            if (($facts['customer_status'] ?? '') === 'platform_unbound' && $id === (string)$facts['customer_id']) return null;
            // A worker/customer who is also an administrator retains that role.
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $member = $this->member($id, 'platform_staff', $open);
            $member['authority_version'] = (string)$person['authority_version'];
            $members[] = $member;
        }
        if (count($members) > 10) return null;
        if (count($members) === 1) $members[0]['can_send'] = false;
        $snapshot = $this->snapshot($facts, $open, $episode);
        $snapshot['schema_version'] = 2;
        $snapshot['staff_config_version'] = (string)$facts['staff_config_version'];
        $snapshot['members'] = $members;
        return $snapshot;
    }

    private function member(string $id, string $role, bool $open): array
    {
        return ['actor_id'=>$id,'kind'=>'mini','role'=>$role,'can_read'=>true,'can_send'=>$open];
    }

    /** A null result means no new grant. Existing grants must be closed by the bridge. */
    public function evaluate(array $facts): ?array
    {
        if (($facts['order_source_type'] ?? '') === 'platform') return $this->evaluatePlatform($facts);
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
            || !in_array($facts['assignment_status'] ?? '', ['accepted','info_released'],true)
            || empty($facts['assignment_accepted_at']) || ($facts['payment_status'] ?? '') !== 'paid') return null;
        $lifecycle = (string)($facts['lifecycle_status'] ?? '');
        $open = $lifecycle === 'active';
        if (!$open && !in_array($lifecycle,['completed','cancelled','closed','refunded','cancel_approved_pending_refund'],true)) return null;
        return $this->snapshot($facts,$open,'assignment:'.$facts['assignment_id'].':service');
    }

    private function repair(array $facts,array $episode,bool $platform=false): ?array
    {
        foreach(['id','after_sale_id'] as $key) if(!$this->id($episode[$key]??null))return null;
        foreach(['order_id'=>'order_id','assignment_id'=>'assignment_id','worker_id'=>'worker_id','accepted_user_id'=>'worker_user_id',
            'repair_order_id'=>'order_id','repair_user_id'=>'customer_id'] as $key=>$fact) {
            if((string)($episode[$key]??'')!==(string)$facts[$fact])return null;
        }
        if(($facts['workflow_profile']??'')!=='construction' || ($facts['lifecycle_status']??'')!=='completed'
            || ($facts['payment_status']??'')!==($platform?'not_required':'paid')
            || !in_array($facts['assignment_record_status']??'',['accepted','completed'],true)
            || !in_array($facts['assignment_status']??'',['accepted','info_released','completed'],true) || empty($facts['assignment_accepted_at'])
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

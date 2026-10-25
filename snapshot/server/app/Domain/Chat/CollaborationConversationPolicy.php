<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Chat;

/**
 * S24 authority contract. Inputs are PHP-owned, current locking-read facts.
 * No HTTP input, order mutation, invitation claim or Go-table write belongs here.
 * The bridge persists schema 3 with the same order transaction and source epoch.
 */
final class CollaborationConversationPolicy
{
    public const DEFAULT_MEMBER_LIMIT = 10;
    public const MAX_MEMBER_LIMIT = 50;
    // Admission caps ordinary joins. Reserved headroom lets mandatory system
    // replacements/revocations proceed without kicking existing collaborators.
    public const MAX_SNAPSHOT_MEMBERS = 64;
    public const MAX_SNAPSHOT_BYTES = 32768;
    public const DISPLAY_ROLES = [
        'merchant', 'merchant_service', 'designer', 'owner', 'coordinator',
        'project_manager', 'team_worker', 'other',
    ];
    private const SYSTEM_ROLES = [
        'merchant_owner'=>'merchant', 'assigned_worker'=>'worker',
        'owner_mobile_match'=>'owner', 'platform_staff'=>'platform',
    ];

    /**
     * $base is a valid current result of OrderConversationPolicy::evaluate().
     * $members is a resolved, deduplicated roster from PHP authority storage.
     * membership_generation changes on grant loss/rejoin, never on display edits.
     */
    public function evaluate(array $base, string $source, array $members, int $memberLimit=self::DEFAULT_MEMBER_LIMIT, string $sourceVersion='1'): ?array
    {
        if ($source!=='merchant' || !$this->base($base) || !$this->list($members)
            || count($members)<1 || count($members)>self::MAX_SNAPSHOT_MEMBERS
            || $memberLimit<3 || $memberLimit>self::MAX_MEMBER_LIMIT) return null;
        $expected=[];foreach($base['members'] as $m)$expected[$m['role']==='customer'?'merchant_owner':'assigned_worker']=$m['actor_id'];
        $repair=strpos($base['episode_key'],':repair:');
        return $this->resolve($base['order_id'],$base['state'],'order:'.$base['order_id'].':collaboration:'.($repair===false?'service':'repair:'.substr($base['episode_key'],$repair+8)),$members,$memberLimit,$sourceVersion,$expected);
    }

    /** Called only with the current PHP-owned, resolved authoritative roster. */
    public function resolve(string $order,string $state,string $episode,array $members,int $memberLimit,string $sourceVersion,array $expected=[]): ?array
    {
        $prefix='order:'.$order.':collaboration:';
        if(!$this->id($order)||!$this->id($sourceVersion)||!in_array($state,['open','readonly'],true)
            ||($episode!==$prefix.'service'&&(strpos($episode,$prefix.'repair:')!==0||!$this->id(substr($episode,strlen($prefix)+7))))
            ||!array_is_list($members)||count($members)<1||count($members)>self::MAX_SNAPSHOT_MEMBERS||$memberLimit<3||$memberLimit>50)return null;
        $seen=[]; $roles=[]; $result=[]; $staffConfig=null;
        foreach ($members as $person) {
            if (!is_array($person) || !$this->keys($person,[
                'actor_id','kind','status','role_source','membership_generation','display_role','display_role_version',
            ], ['authority_version','staff_config_version','inviter_actor_id','invite_generation'])
                || !$this->id($person['actor_id']) || $person['kind']!=='mini' || $person['status']!=='active'
                || !$this->id($person['membership_generation']) || !$this->id($person['display_role_version'])) return null;
            $actor=$person['actor_id']; $sourceRole=$person['role_source'];
            if (isset($seen[$actor]) || !is_string($sourceRole)
                || (!array_key_exists($sourceRole,self::SYSTEM_ROLES) && $sourceRole!=='invite')) return null;
            $seen[$actor]=true;
            $display=$person['display_role'];
            if (!is_string($display) || ($sourceRole==='invite'
                ? !in_array($display,self::DISPLAY_ROLES,true)
                : $display!==self::SYSTEM_ROLES[$sourceRole])) return null;
            $member=[
                'actor_id'=>$actor,'kind'=>'mini','role_source'=>$sourceRole,
                'membership_generation'=>$person['membership_generation'],
                'display_role'=>$display,'display_role_version'=>$person['display_role_version'],
                'can_read'=>true,'can_send'=>$state==='open' && count($members)>1,
            ];
            if ($sourceRole==='invite') {
                if (!$this->id($person['inviter_actor_id']??null) || $person['inviter_actor_id']===$actor
                    || !$this->id($person['invite_generation']??null)
                    || isset($person['authority_version']) || isset($person['staff_config_version'])) return null;
                $member['inviter_actor_id']=$person['inviter_actor_id'];
                $member['invite_generation']=$person['invite_generation'];
            } elseif (array_key_exists('inviter_actor_id',$person) || array_key_exists('invite_generation',$person)) return null;
            if ($sourceRole==='platform_staff') {
                if (!$this->id($person['authority_version']??null) || !$this->id($person['staff_config_version']??null)
                    || ($staffConfig!==null && $staffConfig!==$person['staff_config_version'])) return null;
                $staffConfig=$person['staff_config_version'];
                $member['authority_version']=$person['authority_version'];
            } elseif (array_key_exists('authority_version',$person) || array_key_exists('staff_config_version',$person)) return null;
            if ($sourceRole==='merchant_owner' || $sourceRole==='assigned_worker') {
                if (isset($roles[$sourceRole])) return null;
                if (isset($expected[$sourceRole]) && $expected[$sourceRole]!==$actor) return null;
            }
            $roles[$sourceRole]=($roles[$sourceRole]??0)+1;
            $result[]=$member;
        }
        if (!isset($roles['merchant_owner']) || ($roles['owner_mobile_match']??0)>1
            || ($roles['platform_staff']??0)>8) return null;
        // Stable unsigned-decimal ordering makes identical facts hash identically.
        usort($result,static fn(array $a,array $b):int=>strlen($a['actor_id'])<=>strlen($b['actor_id']) ?: strcmp($a['actor_id'],$b['actor_id']));
        $snapshot=[
            'schema_version'=>3,'conversation_mode'=>'collaboration','source_version'=>$sourceVersion,
            'scope_key'=>'order:'.$order.':collaboration','order_id'=>$order,
            'assignment_id'=>null,'episode_key'=>$episode,'state'=>$state,
            // Invitation management is PHP-only; this is never a Go capability.
            'can_invite'=>false,'member_limit'=>$memberLimit,'members'=>$result,
        ];
        if ($staffConfig!==null) $snapshot['staff_config_version']=$staffConfig;

        return strlen(json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))<=self::MAX_SNAPSHOT_BYTES
            ? $snapshot : null;
    }

    /** Called only under the future group authority-row lock, never as a CAS substitute. */
    public function canAddInvitedMember(array $snapshot): bool
    {
        return ($snapshot['schema_version']??null)===3 && ($snapshot['conversation_mode']??'')==='collaboration'
            && ($snapshot['state']??'')==='open' && is_int($snapshot['member_limit']??null)
            && $snapshot['member_limit']>=3 && $snapshot['member_limit']<=self::MAX_MEMBER_LIMIT
            && is_array($snapshot['members']??null) && $this->list($snapshot['members'])
            && count($snapshot['members'])<$snapshot['member_limit'];
    }

    private function base(array $s): bool
    {
        // Do not accept an arbitrary client-supplied state as an order-policy result.
        if (($s['schema_version']??null)!==1 || !$this->id($s['order_id']??null)
            || !$this->id($s['assignment_id']??null)
            || ($s['scope_key']??'')!=='order:'.$s['order_id'].':assignment:'.$s['assignment_id']
            || ($s['can_invite']??null)!==false || !in_array($s['state']??'', ['open','readonly'],true)
            || !is_array($s['members']??null) || count($s['members'])!==2) return false;
        $prefix='assignment:'.$s['assignment_id'];
        $episode=$s['episode_key']??null;
        if (!is_string($episode) || ($episode!==$prefix.':service'
            && (strpos($episode,$prefix.':repair:')!==0 || !$this->id(substr($episode,strlen($prefix)+8))))) return false;
        $roles=[];$actors=[];
        foreach($s['members'] as $m) {
            if (!is_array($m) || !$this->id($m['actor_id']??null) || ($m['kind']??'')!=='mini'
                || !in_array($m['role']??'', ['customer','worker'],true) || isset($roles[$m['role']]) || isset($actors[$m['actor_id']])
                || ($m['can_read']??null)!==true || ($m['can_send']??null)!==($s['state']==='open')) return false;
            $roles[$m['role']]=true;$actors[$m['actor_id']]=true;
        }
        return true;
    }

    private function keys(array $value,array $required,array $optional=[]): bool
    {
        foreach($required as $key) if(!array_key_exists($key,$value)) return false;
        foreach($value as $key=>$item) if(!in_array($key,$required,true)&&!in_array($key,$optional,true) || $item===null) return false;
        return true;
    }
    private function list(array $value): bool { return array_is_list($value); }
    private function id($value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,19}$/D',$value)===1
            && (strlen($value)<20 || strcmp($value,'18446744073709551615')<=0);
    }
}

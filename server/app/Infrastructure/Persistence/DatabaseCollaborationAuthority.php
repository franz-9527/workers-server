<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\ChatCollaborationAuthority;
use WorkerAdmin\Domain\Chat\CollaborationConversationPolicy;
use WorkerAdmin\Domain\Identity\MobileNumber;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;

/** All calls own the order row first. PHP is the only writer of membership facts. */
final class DatabaseCollaborationAuthority implements ChatCollaborationAuthority
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function synchronize(string $order):void
    {(new DatabaseOrderConversationBridge($this->db,true))->synchronize($order);}
    public function platformStaffLocked():array
    {return (new DatabaseChatPlatformStaff($this->db))->readAuthorityLocked();}
    public function resolve(array $order,array $facts,?array $legacy,bool $apply): ?array
    {
        if(!$this->db->pdo()->inTransaction())throw new RuntimeException('群权限必须在订单事务内');
        $id=(string)$order['id'];
        $group=$this->db->fetch('SELECT CAST(source_version AS CHAR) source_version FROM chat_collaboration_groups WHERE order_id=:id FOR UPDATE',['id'=>$id]);
        if(!$group){
            if(!$legacy||$legacy['state']!=='open')return null;
            if($apply)$this->db->execute('INSERT INTO chat_collaboration_groups(order_id,source_version,created_at) VALUES(:id,1,UTC_TIMESTAMP(6))',['id'=>$id]);
            $group=['source_version'=>'1'];
        }
        $closed=$this->db->fetch('SELECT snapshot_json FROM chat_policy_guards WHERE scope_key=:scope FOR UPDATE',['scope'=>'order:'.$id.':collaboration']);
        $previous=$closed?json_decode((string)$closed['snapshot_json'],true,32,JSON_THROW_ON_ERROR):null;
        $state=$legacy['state']??'readonly';
        $episode=$legacy?('order:'.$id.':collaboration:'.(str_contains($legacy['episode_key'],':repair:')?'repair:'.explode(':repair:',$legacy['episode_key'],2)[1]:'service')):($previous['episode_key']??'order:'.$id.':collaboration:service');
        // A paid active group remains useful while an old worker is removed and
        // the next accepted assignment is pending; it never fabricates a worker.
        if(!$legacy&&$order['lifecycle_status']==='active'&&$order['payment_status']==='paid')$state='open';
        if($previous&&$previous['state']==='readonly'&&$state==='open'
            &&(!str_contains($episode,':repair:')||$episode===$previous['episode_key']))$state='readonly';
        $old=$this->db->fetchAll('SELECT * FROM chat_collaboration_members WHERE order_id=:id ORDER BY actor_id FOR UPDATE',['id'=>$id]);
        $by=[];foreach($old as $m)$by[(string)$m['actor_id']]=$m;
        $desired=[];
        $merchant=$this->db->fetch("SELECT CAST(id AS CHAR) actor_id,status FROM users WHERE id=:id LOCK IN SHARE MODE",['id'=>$order['user_id']]);
        if(!$merchant||$merchant['status']!=='active')return null;
        $this->system($desired,(string)$merchant['actor_id'],'merchant_owner','merchant','merchant:'.$merchant['actor_id']);
        $worker=(string)($facts['worker_user_id']??'');
        if($worker!==''&&($facts['worker_user_status']??'')==='active'
            &&(string)($facts['assignment_order_id']??'')===$id
            &&(string)($facts['current_assignment_id']??'')===(string)($facts['assignment_id']??'')
            &&(string)($facts['assigned_worker_id']??'')===(string)($facts['worker_id']??'')
            &&!empty($facts['assignment_accepted_at'])
            &&($legacy!==null||$state==='readonly'&&in_array($facts['assignment_record_status']??'',['accepted','completed','cancelled'],true)))
            $this->system($desired,$worker,'assigned_worker','worker','assignment:'.$facts['assignment_id']);
        // Current address snapshot is authoritative. No fallback to a stale
        // top-level number when the current address row exists but is cleared.
        $address=$this->db->fetch('SELECT contact_mobile_ciphertext FROM order_addresses WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$id]);
        $cipher=new GlobalPrivateValueCipher();
        $owner=MobileNumber::normalize($cipher->decrypt((string)($address?$address['contact_mobile_ciphertext']:($order['contact_mobile_ciphertext']??'')),'order-contact-mobile'));
        if($owner!==''){
            $hmac=$cipher->hmac($owner,'user-mobile');
            $account=$this->db->fetch("SELECT CAST(id AS CHAR) actor_id,mobile_ciphertext,mobile_hmac FROM users WHERE mobile_hmac=:hash AND status='active' LIMIT 1 LOCK IN SHARE MODE",['hash'=>$hmac]);
            if($account&&hash_equals($hmac,(string)$account['mobile_hmac'])
                &&MobileNumber::normalize($cipher->decrypt((string)$account['mobile_ciphertext'],'user-mobile'))===$owner)
                $this->system($desired,$account['actor_id'],'owner_mobile_match','owner','owner:'.$hmac);
        }
        // Preserve independent invitees. Only team helpers tied to the replaced
        // official worker assignment close automatically.
        foreach($old as $m){
            $actor=(string)$m['actor_id'];
            if((int)$m['active']!==1||$m['role_source']!=='invite'||isset($desired[$actor]))continue;
            if($m['display_role']==='team_worker'&&$m['team_assignment_id']!==null
                &&(string)$m['team_assignment_id']!==(string)($facts['assignment_id']??''))continue;
            $active=$this->db->fetchValue("SELECT id FROM users WHERE id=:id AND status='active' LOCK IN SHARE MODE",['id'=>$actor]);
            if($active)$desired[$actor]=$m;
        }
        $staffAuth=null;
        foreach($old as $m)if((int)$m['active']===1&&$m['role_source']==='platform_staff'){
            if($staffAuth===null)$staffAuth=(new DatabaseChatPlatformStaff($this->db))->readAuthorityLocked();
            foreach($staffAuth['platform_staff'] as $staff)if($staff['actor_id']===(string)$m['actor_id']&&!isset($desired[$staff['actor_id']])){
                $this->system($desired,$staff['actor_id'],'platform_staff','platform','staff:'.$staffAuth['staff_config_version'].':'.$staff['authority_version']);
                $desired[$staff['actor_id']]['authority_version']=$staff['authority_version'];
                $desired[$staff['actor_id']]['staff_config_version']=$staffAuth['staff_config_version'];
            }
        }
        foreach($desired as $actor=>&$m){
            $prior=$by[$actor]??null;
            $system=$m['role_source']!=='invite';
            $changed=!$prior||(int)$prior['active']!==1||$prior['role_source']!==$m['role_source']
                ||$system&&$prior['source_fingerprint']!==$m['source_fingerprint'];
            $m['actor_id']=(string)$actor;
            $m['membership_generation']=$prior?(string)((int)$prior['membership_generation']+($changed?1:0)):'1';
            $m['display_role_version']=$prior?(string)((int)$prior['display_role_version']+($prior['display_role']!==$m['display_role']?1:0)):'1';
            if($changed&&$apply){
                $this->db->execute("INSERT INTO chat_collaboration_members(order_id,actor_id,role_source,membership_generation,display_role,display_role_version,active,source_fingerprint,updated_at)
                 VALUES(:order,:actor,:source,:generation,:display,:display_version,1,:fingerprint,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE role_source=VALUES(role_source),membership_generation=VALUES(membership_generation),display_role=VALUES(display_role),display_role_version=VALUES(display_role_version),active=1,source_fingerprint=VALUES(source_fingerprint),inviter_actor_id=NULL,invite_generation=NULL,team_assignment_id=NULL,updated_at=VALUES(updated_at)",
                 ['order'=>$id,'actor'=>(string)$actor,'source'=>$m['role_source'],'generation'=>$m['membership_generation'],'display'=>$m['display_role'],'display_version'=>$m['display_role_version'],'fingerprint'=>$m['source_fingerprint']??'']);
                $this->event($id,'0',(string)$actor,'member.joined',$m['display_role'],$m['display_role_version']);
            }
        }unset($m);
        foreach($old as $m)if((int)$m['active']===1&&!isset($desired[(string)$m['actor_id']])&&$apply){
            $this->db->execute('UPDATE chat_collaboration_members SET active=0,updated_at=UTC_TIMESTAMP(6) WHERE order_id=:order AND actor_id=:actor',['order'=>$id,'actor'=>(string)$m['actor_id']]);
            $this->event($id,'0',(string)$m['actor_id'],'member.authority_closed',null,null);
        }
        $limit=(int)$this->db->fetchValue('SELECT member_limit FROM chat_collaboration_settings WHERE id=1 LOCK IN SHARE MODE');
        $people=[];
        // The users trigger advances only this row's account epoch. It never
        // locks groups, so order/group -> users cannot cycle with account edits.
        foreach($desired as $m){
            $accountVersion=$this->db->fetchValue("SELECT CAST(chat_collaboration_version AS CHAR) FROM users WHERE id=:id AND status='active' LOCK IN SHARE MODE",['id'=>(string)$m['actor_id']]);
            if(!$accountVersion)throw new RuntimeException('群账号权威已变化');
            $person=['actor_id'=>(string)$m['actor_id'],'kind'=>'mini','status'=>'active','role_source'=>$m['role_source'],
                'membership_generation'=>(string)$m['membership_generation'],'account_version'=>(string)$accountVersion,'display_role'=>$m['display_role'],'display_role_version'=>(string)$m['display_role_version']];
            if($m['role_source']==='invite'){$person['inviter_actor_id']=(string)$m['inviter_actor_id'];$person['invite_generation']=(string)$m['invite_generation'];}
            if($m['role_source']==='platform_staff'){$person['authority_version']=$m['authority_version'];$person['staff_config_version']=$m['staff_config_version'];}
            $people[]=$person;
        }
        $snapshot=(new CollaborationConversationPolicy())->resolve($id,$state,$episode,$people,$limit,$group['source_version']);
        if(!$snapshot)throw new RuntimeException('群权限事实无效');
        return $snapshot;
    }
    private function system(array &$desired,string $actor,string $source,string $display,string $fingerprint):void
    {if(!isset($desired[$actor]))$desired[$actor]=['actor_id'=>$actor,'role_source'=>$source,'display_role'=>$display,'source_fingerprint'=>hash('sha256',$fingerprint)];}
    public function event(string $order,string $actor,?string $target,string $type,?string $display,?string $version):void
    {$this->db->execute('INSERT INTO chat_collaboration_events(order_id,actor_id,target_actor_id,event_type,display_role,display_role_version,created_at) VALUES(:order,:actor,:target,:type,:display,:version,UTC_TIMESTAMP(6))',['order'=>$order,'actor'=>$actor,'target'=>$target,'type'=>$type,'display'=>$display,'version'=>$version]);}
}

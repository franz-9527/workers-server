<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\CollaborationConversationPolicy;
use WorkerAdmin\Domain\Identity\MobileNumber;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderConversationBridge;
use WorkerAdmin\Infrastructure\Persistence\DatabaseCollaborationAuthority;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;

/** PHP-only invitation/membership commands; all mutations serialize on orders.id. */
final class ChatCollaboration
{
    private Database $db; private string $environment; private string $key;
    public function __construct(Database $db,string $environment,string $key){$this->db=$db;$this->environment=$environment;$this->key=$key;}
    private function fail(string $code,int $status=403):void{throw new RuntimeException($code,$status);}
    private function id($v):string
    {if(!is_string($v)||preg_match('/^[1-9][0-9]{0,19}$/D',$v)!==1||strlen($v)===20&&strcmp($v,'18446744073709551615')>0)$this->fail('CHAT_INVALID_REQUEST',400);return $v;}
    private function session(string $actor,string $hash):void
    {
        $ok=$this->db->fetchValue("SELECT s.id FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
         WHERE s.user_id=:actor AND s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
         AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0 AND COALESCE(s.test_instance_id,0)=0
         AND s.test_actor_expires_at IS NULL LIMIT 1 LOCK IN SHARE MODE",['actor'=>$actor,'hash'=>$hash]);
        if(!$ok)$this->fail('CHAT_UNAUTHORIZED',401);
    }
    public function execute(string $actor,string $hash,string $action,array $input):array
    {
        if((\app_config('chat.collaboration_enabled',false)!==true || \app_config('chat.order_policy_enabled',false)!==true))$this->fail('CHAT_COLLABORATION_DISABLED',503);
        if(!in_array($this->environment,['test','development','production'],true)||strlen($this->key)<32)$this->fail('CHAT_DEPENDENCY_UNAVAILABLE',503);
        $fields=['info'=>['conversation_id'],'invite'=>['conversation_id','expected_generation'],'revoke'=>['conversation_id','expected_generation'],
          'poster'=>['conversation_id','expected_generation'],'preview'=>['token'],'join'=>['token','display_role'],'leave'=>['conversation_id','expected_member_version'],
          'remove'=>['conversation_id','target_actor_id','expected_member_version'],'role'=>['conversation_id','display_role','expected_display_version'],
          'discover'=>['after']];
        if(!isset($fields[$action]))$this->fail('CHAT_INVALID_REQUEST',400);
        $actual=array_keys($input);$expected=$fields[$action];sort($actual);sort($expected);
        if($actual!==$expected)$this->fail('CHAT_INVALID_REQUEST',400);
        if($action==='discover')return $this->discover($actor,$hash,$input);
        $tokenAction=in_array($action,['preview','join'],true);
        $token=$tokenAction?($input['token']??null):'';
        if($tokenAction&&(!is_string($token)||preg_match('/^[a-f0-9]{32}$/D',$token)!==1))$this->fail('CHAT_INVITE_INVALID',400);
        $lookup=$tokenAction?$this->db->fetch('SELECT CAST(order_id AS CHAR) order_id FROM chat_collaboration_invites WHERE token_hash=:hash AND environment=:env',['hash'=>hash('sha256',$token),'env'=>$this->environment]):
          $this->db->fetch("SELECT CAST(order_id AS CHAR) order_id FROM chat_conversations WHERE id=:id AND assignment_id IS NULL AND conversation_type='order'",['id'=>$this->id($input['conversation_id']??null)]);
        if(!$lookup)$this->fail('CHAT_INVITE_INVALID',404);
        return $this->db->transaction(function()use($actor,$hash,$action,$input,$lookup,$tokenAction,$token):array{
            $order=$this->db->fetch("SELECT * FROM orders WHERE id=:id AND order_source_type='merchant' AND is_demo=0 FOR UPDATE",['id'=>$lookup['order_id']]);
            if(!$order)$this->fail('CHAT_FORBIDDEN');
            $this->session($actor,$hash);
            $id=(string)$order['id'];$this->refresh($id);
            $snapshot=$this->snapshot($id);$members=$this->members($id);
            $me=$members[$actor]??null;$canInvite=$this->inviter($me,$snapshot);
            $invite=null;
            if($tokenAction){
                $invite=$this->db->fetch("SELECT *,CAST(generation AS CHAR) generation,expires_at>UTC_TIMESTAMP(6) live FROM chat_collaboration_invites WHERE token_hash=:hash AND environment=:env FOR UPDATE",['hash'=>hash('sha256',$token),'env'=>$this->environment]);
                $who=$invite?($members[(string)$invite['inviter_actor_id']]??null):null;
                if(!$invite||!(int)$invite['live']||$invite['revoked_at']!==null||!$this->inviter($who,$snapshot)
                    ||(string)$who['membership_generation']!==(string)$invite['authority_generation'])$this->fail('CHAT_INVITE_INVALID',409);
                if($action==='preview')return ['title'=>'订单协作群','valid'=>true,'expires_at'=>$invite['expires_at'],'roles'=>CollaborationConversationPolicy::DISPLAY_ROLES,'already_member'=>$me!==null];
            }elseif(!$me)$this->fail('CHAT_FORBIDDEN');
            if(in_array($action,['invite','revoke','poster'],true)){
                if(!$canInvite)$this->fail('CHAT_FORBIDDEN');
                $old=$this->db->fetch('SELECT *,CAST(generation AS CHAR) generation,expires_at>UTC_TIMESTAMP(6) live FROM chat_collaboration_invites WHERE order_id=:order AND inviter_actor_id=:actor FOR UPDATE',['order'=>$id,'actor'=>$actor]);
                $generation=$old?(string)$old['generation']:'0';
                if(!is_string($input['expected_generation'])||$input['expected_generation']!==$generation)$this->fail('CHAT_VERSION_CONFLICT',409);
                if($action==='poster'){
                    if(!$old||!$old['live']||$old['revoked_at']!==null||(string)$old['authority_generation']!==(string)$me['membership_generation'])$this->fail('CHAT_INVITE_INVALID',409);
                    $value=substr(hash_hmac('sha256',$this->environment."\n".$id."\n".$actor."\n".$generation."\n".$old['seed'],$this->key),0,32);
                    if(!hash_equals((string)$old['token_hash'],hash('sha256',$value)))$this->fail('CHAT_INVITE_INVALID',409);
                    return ['poster_order_id'=>$id,'poster_type'=>'chat_'.substr(hash('sha256',$actor),0,32),'poster_title'=>'订单协作群 · '.$order['public_no'],'poster_path'=>'subpackages/chat/group/index?invite='.$value,'expires_at'=>$old['expires_at']];
                }
                if($action==='revoke'){
                    $this->db->execute('UPDATE chat_collaboration_invites SET revoked_at=UTC_TIMESTAMP(6) WHERE order_id=:order AND inviter_actor_id=:actor',['order'=>$id,'actor'=>$actor]);
                    $this->event($id,$actor,null,'invite.revoked');return ['revoked'=>true];
                }
                $generation=(string)((int)$generation+1);$seed=bin2hex(random_bytes(16));
                $token=substr(hash_hmac('sha256',$this->environment."\n".$id."\n".$actor."\n".$generation."\n".$seed,$this->key),0,32);
                $this->db->execute("INSERT INTO chat_collaboration_invites(order_id,inviter_actor_id,environment,generation,token_hash,seed,authority_generation,expires_at,created_at)
                 VALUES(:order,:actor,:env,:generation,:hash,:seed,:authority,UTC_TIMESTAMP(6)+INTERVAL 7 DAY,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE environment=VALUES(environment),generation=VALUES(generation),token_hash=VALUES(token_hash),seed=VALUES(seed),authority_generation=VALUES(authority_generation),expires_at=VALUES(expires_at),revoked_at=NULL,created_at=VALUES(created_at)",
                 ['order'=>$id,'actor'=>$actor,'env'=>$this->environment,'generation'=>$generation,'hash'=>hash('sha256',$token),'seed'=>$seed,'authority'=>(string)$me['membership_generation']]);
                $this->event($id,$actor,null,'invite.generated');
                return ['token'=>$token,'generation'=>$generation,'expires_at'=>gmdate('c',time()+7*86400),'path'=>'/subpackages/chat/group/index?invite='.$token,'title'=>'壁选师傅 · 订单协作群 · '.$order['public_no']];
            }
            if($action==='join'){
                if($me)return $this->info($id,$actor,$members,$snapshot);
                $role=$this->role($input['display_role']);$old=$this->db->fetch('SELECT * FROM chat_collaboration_members WHERE order_id=:order AND actor_id=:actor FOR UPDATE',['order'=>$id,'actor'=>$actor]);
                if($old&&(string)$old['inviter_actor_id']===(string)$invite['inviter_actor_id']&&(int)$old['invite_generation']>=(int)$invite['generation'])$this->fail('CHAT_INVITE_REGENERATE_REQUIRED',409);
                $limit=$this->limit();
                if(count($members)>=$limit)$this->fail('CHAT_GROUP_FULL',409);
                $generation=$old?(string)((int)$old['membership_generation']+1):'1';
                $displayVersion=$old?(string)((int)$old['display_role_version']+1):'1';
                $inviter=$members[(string)$invite['inviter_actor_id']];
                $assignment=$role==='team_worker'&&$inviter['role_source']==='assigned_worker'?$order['current_assignment_id']:null;
                $this->db->execute("INSERT INTO chat_collaboration_members(order_id,actor_id,role_source,membership_generation,display_role,display_role_version,active,inviter_actor_id,invite_generation,team_assignment_id,updated_at)
                 VALUES(:order,:actor,'invite',:generation,:role,:version,1,:inviter,:invite_generation,:assignment,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE role_source='invite',membership_generation=VALUES(membership_generation),display_role=VALUES(display_role),display_role_version=VALUES(display_role_version),active=1,inviter_actor_id=VALUES(inviter_actor_id),invite_generation=VALUES(invite_generation),team_assignment_id=VALUES(team_assignment_id),updated_at=VALUES(updated_at)",
                 ['order'=>$id,'actor'=>$actor,'generation'=>$generation,'role'=>$role,'version'=>$displayVersion,'inviter'=>(string)$invite['inviter_actor_id'],'invite_generation'=>(string)$invite['generation'],'assignment'=>$assignment]);
                $this->event($id,$actor,$actor,'member.joined',$role,$displayVersion);
            }elseif($action==='leave'||$action==='remove'){
                $target=$action==='leave'?$actor:$this->id($input['target_actor_id']);$person=$members[$target]??null;
                if(!$person||$person['role_source']!=='invite'||($target!==$actor&&!in_array($me['role_source'],['merchant_owner','platform_staff'],true)&&(!in_array($me['role_source'],['assigned_worker','owner_mobile_match'],true)||(string)$person['inviter_actor_id']!==$actor)))$this->fail('CHAT_FORBIDDEN');
                if(!is_string($input['expected_member_version'])||$input['expected_member_version']!==(string)$person['membership_generation'])$this->fail('CHAT_VERSION_CONFLICT',409);
                $this->db->execute('UPDATE chat_collaboration_members SET active=0,updated_at=UTC_TIMESTAMP(6) WHERE order_id=:order AND actor_id=:actor',['order'=>$id,'actor'=>$target]);
                $this->event($id,$actor,$target,$action==='leave'?'member.left':'member.removed');
                $this->touch($id);$this->refresh($id);return ['removed'=>true];
            }elseif($action==='role'){
                if($me['role_source']!=='invite')$this->fail('CHAT_FORBIDDEN');
                $role=$this->role($input['display_role']);
                if(!is_string($input['expected_display_version'])||$input['expected_display_version']!==(string)$me['display_role_version'])$this->fail('CHAT_VERSION_CONFLICT',409);
                if($role!==$me['display_role']){
                    $version=(string)((int)$me['display_role_version']+1);
                    $origin=$members[(string)($me['inviter_actor_id']??'')]??null;$team=$role==='team_worker'&&$origin&&$origin['role_source']==='assigned_worker'?$order['current_assignment_id']:null;
                    $this->db->execute('UPDATE chat_collaboration_members SET display_role=:role,display_role_version=:version,team_assignment_id=:team,updated_at=UTC_TIMESTAMP(6) WHERE order_id=:order AND actor_id=:actor',['role'=>$role,'version'=>$version,'team'=>$team,'order'=>$id,'actor'=>$actor]);
                    $this->event($id,$actor,$actor,'member.role_changed',$role,$version);
                }
            }elseif($action==='info')return $this->info($id,$actor,$members,$snapshot);
            $this->touch($id);$this->refresh($id);
            return $this->info($id,$actor,$this->members($id),$this->snapshot($id));
        });
    }
    private function refresh(string $id):void{(new DatabaseOrderConversationBridge($this->db,true))->synchronize($id);}
    private function touch(string $id):void{$this->db->execute('UPDATE chat_collaboration_groups SET source_version=source_version+1 WHERE order_id=:id',['id'=>$id]);}
    private function snapshot(string $id):array {
        $row=$this->db->fetch('SELECT snapshot_json,CAST(policy_version AS CHAR) policy_version FROM chat_policy_guards WHERE scope_key=:scope LOCK IN SHARE MODE',['scope'=>'order:'.$id.':collaboration']);
        if(!$row)$this->fail('CHAT_FORBIDDEN');$s=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        $source=$this->db->fetchValue('SELECT CAST(source_version AS CHAR) FROM chat_collaboration_groups WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$id]);
        if(($s['schema_version']??null)!==3||(string)$source!==(string)($s['source_version']??''))$this->fail('CHAT_FORBIDDEN');
        $s['policy_version']=$row['policy_version'];return $s;
    }
    private function members(string $id):array {
        $result=[];foreach($this->db->fetchAll('SELECT * FROM chat_collaboration_members WHERE order_id=:id AND active=1 ORDER BY actor_id LOCK IN SHARE MODE',['id'=>$id]) as $m)$result[(string)$m['actor_id']]=$m;return $result;
    }
    private function inviter(?array $m,array $s):bool{return $m!==null&&$s['state']==='open'&&in_array($m['role_source'],['merchant_owner','assigned_worker','owner_mobile_match','platform_staff'],true);}
    private function role($value):string {if(!is_string($value)||!in_array($value,CollaborationConversationPolicy::DISPLAY_ROLES,true))$this->fail('CHAT_INVALID_ROLE',400);return $value;}
    private function limit():int{return (int)$this->db->fetchValue('SELECT member_limit FROM chat_collaboration_settings WHERE id=1 LOCK IN SHARE MODE');}
    private function event(string $order,string $actor,?string $target,string $type,?string $display=null,?string $version=null):void {(new DatabaseCollaborationAuthority($this->db))->event($order,$actor,$target,$type,$display,$version);}
    private function info(string $id,string $actor,array $members,array $s):array {
        $result=[];$me=$members[$actor]??null;if(!$me)$this->fail('CHAT_FORBIDDEN');
        $params=[];$slots=[];foreach(array_keys($members) as $i=>$uid){$key='u'.$i;$params[$key]=(string)$uid;$slots[]=':'.$key;}
        $people=[];foreach($this->db->fetchAll("SELECT CAST(u.id AS CHAR) actor_id,u.public_id,u.mobile_ciphertext,u.mobile_hmac,rv.status real_name_status,rv.real_name_ciphertext
            FROM users u LEFT JOIN worker_profiles w ON w.user_id=u.id LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id
            WHERE u.id IN (".implode(',',$slots).")",$params) as $person)$people[$person['actor_id']]=$person;
        $formatter=new ChatParticipantProfiles($this->db,new GlobalPrivateValueCipher());
        foreach($members as $m){
            $user=$people[(string)$m['actor_id']]??['actor_id'=>(string)$m['actor_id']];
            $suffix=substr(preg_replace('/[^A-Za-z0-9]/','',(string)($user['public_id']??''))?:((string)$m['actor_id']),-6);
            $kind=['merchant_owner'=>'merchant','assigned_worker'=>'worker','platform_staff'=>'platform'][$m['role_source']]??'user';
            $name=$m['role_source']==='invite'||$m['role_source']==='owner_mobile_match'?'用户'.$suffix:$formatter->display($user,$kind)['display_name'];
            $result[]=['actor_id'=>(string)$m['actor_id'],'name'=>$name,'role_source'=>$m['role_source'],'display_role'=>$m['display_role'],
             'member_version'=>(string)$m['membership_generation'],'display_version'=>(string)$m['display_role_version'],
             'can_remove'=>$m['role_source']==='invite'&&((string)$m['actor_id']===$actor||in_array($me['role_source'],['merchant_owner','platform_staff'],true)||in_array($me['role_source'],['assigned_worker','owner_mobile_match'],true)&&(string)$m['inviter_actor_id']===$actor)];
        }
        $invite=$this->db->fetch('SELECT CAST(generation AS CHAR) generation FROM chat_collaboration_invites WHERE order_id=:order AND inviter_actor_id=:actor',['order'=>$id,'actor'=>$actor]);
        $projection=$this->db->fetch("SELECT CAST(c.id AS CHAR) id,(c.policy_version=g.policy_version AND c.snapshot_hash=g.snapshot_hash) ready FROM chat_conversations c JOIN chat_policy_guards g ON g.scope_key=c.scope_key WHERE c.scope_key=:scope AND c.conversation_type='order'",['scope'=>'order:'.$id.':collaboration']);
        $cid=$projection['id']??'';
        $events=$this->db->fetchAll('SELECT CAST(id AS CHAR) id,event_type,CAST(actor_id AS CHAR) actor_id,CAST(target_actor_id AS CHAR) target_actor_id,display_role,CAST(display_role_version AS CHAR) display_role_version,created_at FROM chat_collaboration_events WHERE order_id=:id ORDER BY id DESC LIMIT 30',['id'=>$id]);
        return ['conversation_id'=>(string)($cid??''),'projection_ready'=>$projection!==null&&(int)$projection['ready']===1,'policy_version'=>$s['policy_version'],'viewer_actor_id'=>$actor,'title'=>'订单协作群 · '.(string)($s['order_context']['public_no']??''),'member_limit'=>$this->limit(),'members'=>$result,
         'state'=>$s['state'],'can_invite'=>$this->inviter($me,$s),'can_leave'=>$me['role_source']==='invite','can_edit_role'=>$me['role_source']==='invite',
         'invite_generation'=>$invite['generation']??'0','events'=>$events];
    }
    private function discover(string $actor,string $hash,array $input):array {
        $after=$input['after'];if(!is_string($after)||preg_match('/^(0|[1-9][0-9]{0,18})$/D',$after)!==1)$this->fail('CHAT_INVALID_REQUEST',400);
        $user=$this->db->fetch('SELECT mobile_ciphertext,mobile_hmac FROM users WHERE id=:id',['id'=>$actor]);$cipher=new GlobalPrivateValueCipher();
        $mobile=MobileNumber::normalize($cipher->decrypt((string)($user['mobile_ciphertext']??''),'user-mobile'));
        $hmac=$mobile!==''&&hash_equals((string)$user['mobile_hmac'],$cipher->hmac($mobile,'user-mobile'))?$cipher->hmac($mobile,'order-contact-mobile'):'';
        $rows=$this->db->fetchAll("SELECT CAST(g.order_id AS CHAR) id FROM chat_collaboration_groups g JOIN orders o ON o.id=g.order_id
         LEFT JOIN order_addresses a ON a.order_id=o.id WHERE g.order_id>:after AND o.is_demo=0 AND o.order_source_type='merchant'
         AND (o.user_id=:actor OR (a.contact_mobile_hmac=:mobile AND :mobile<>'')
          OR EXISTS(SELECT 1 FROM chat_collaboration_members m WHERE m.order_id=o.id AND m.actor_id=:actor AND m.active=1))
         ORDER BY g.order_id LIMIT 21",['after'=>$after,'actor'=>$actor,'mobile'=>$hmac]);
        $hasMore=count($rows)>20;$rows=array_slice($rows,0,20);$next=$after;
        foreach($rows as $row){$this->db->transaction(function()use($row,$actor,$hash):void{
            $this->db->fetch('SELECT id FROM orders WHERE id=:id FOR UPDATE',['id'=>$row['id']]);$this->session($actor,$hash);$this->refresh($row['id']);
        });$next=$row['id'];}
        return ['next_after'=>$next,'has_more'=>$hasMore];
    }
}

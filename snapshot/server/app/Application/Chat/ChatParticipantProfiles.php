<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Identity\MobileNumber;

/** Read-only, bounded display projection. Never changes membership or user profiles. */
final class ChatParticipantProfiles
{
    private Database $db;
    private PrivateValueCipher $cipher;
    public function __construct(Database $db, PrivateValueCipher $cipher) { $this->db=$db; $this->cipher=$cipher; }
    private function id($value): string
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,19}$/D',$value)!==1
            || strlen($value)===20 && strcmp($value,'18446744073709551615')>0) throw new RuntimeException('展示范围无效',400);
        return $value;
    }
    public function read(string $actor,string $tokenHash,array $requests): array
    {
        $this->id($actor);
        if (!$requests || count($requests)>20 || array_keys($requests)!==range(0,count($requests)-1)) throw new RuntimeException('展示范围无效',400);
        $total=0;$seen=[];
        foreach($requests as &$request){
            if(!is_array($request)||array_diff(array_keys($request),['conversation_id','policy_version','sender_ids']))throw new RuntimeException('展示范围无效',400);
            $cid=$this->id($request['conversation_id']??null);$this->id($request['policy_version']??null);
            $ids=$request['sender_ids']??[];if(!is_array($ids)||count($ids)>50||$ids&&array_keys($ids)!==range(0,count($ids)-1))throw new RuntimeException('展示范围无效',400);
            foreach($ids as $id)$this->id($id);
            $total+=count($ids);if($total>100||isset($seen[$cid]))throw new RuntimeException('展示范围无效',400);
            $seen[$cid]=true;$request['sender_ids']=array_values(array_unique($ids));
        }unset($request);
        if(!$this->db->pdo()->inTransaction())$this->db->pdo()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $this->db->transaction(function()use($actor,$tokenHash,$requests):array{
            $login=$this->db->fetch("SELECT UNIX_TIMESTAMP(s.expires_at) expires_unix FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
                WHERE s.user_id=:actor AND s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
                AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0 AND COALESCE(s.test_instance_id,0)=0
                AND s.test_actor_expires_at IS NULL LIMIT 1 LOCK IN SHARE MODE",['actor'=>$actor,'hash'=>$tokenHash]);
            if(!$login)throw new RuntimeException('登录状态已失效',401);
            $items=[];
            foreach($requests as $request){
                $cid=$request['conversation_id'];$version=$request['policy_version'];
                // Lock order -> group -> guard, matching the PHP business
                // writer and Go reader. The first binding read grants nothing.
                $binding=$this->db->fetch('SELECT CAST(order_id AS CHAR) order_id,assignment_id FROM chat_conversations WHERE id=:id',['id'=>$cid]);
                if(!$binding)continue;
                $this->db->fetch('SELECT id FROM orders WHERE id=:id LOCK IN SHARE MODE',['id'=>$binding['order_id']]);
                $lockedUsers=null;
                if($binding['assignment_id']===null){
                    $hint=$this->db->fetchValue('SELECT snapshot_json FROM chat_policy_guards WHERE scope_key=:scope',['scope'=>'order:'.$binding['order_id'].':collaboration']);
                    $hint=is_string($hint)&&strlen($hint)<=32768?json_decode($hint,true,32):null;
                    if(!is_array($hint)||!is_array($hint['members']??null)||count($hint['members'])>64)continue;
                    $lockedUsers=[];$args=[];$slots=[];
                    foreach($hint['members'] as $i=>$person){$uid=$this->id($person['actor_id']??null);$lockedUsers[$uid]=true;$args['lock'.$i]=$uid;$slots[]=':lock'.$i;}
                    if(!$slots)continue;
                    $this->db->fetchAll('SELECT id FROM users WHERE id IN ('.implode(',',$slots).') ORDER BY id LOCK IN SHARE MODE',$args);
                }
                if($binding['assignment_id']===null)$this->db->fetch('SELECT source_version FROM chat_collaboration_groups WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$binding['order_id']]);

                $row=$this->db->fetch("SELECT c.scope_key,c.snapshot_hash applied_hash,CAST(c.policy_version AS CHAR) applied_version,
                    g.snapshot_json,g.snapshot_hash,CAST(g.policy_version AS CHAR) policy_version,o.order_source_type,CAST(o.current_assignment_id AS CHAR) current_assignment_id
                    FROM chat_conversations c JOIN chat_policy_guards g ON g.scope_key=c.scope_key
                    JOIN orders o ON o.id=g.order_id AND o.is_demo=0
                    JOIN chat_members cm ON cm.conversation_id=c.id AND cm.actor_id=:actor AND cm.actor_kind='mini' AND cm.can_read=1
                    WHERE c.id=:id AND c.conversation_type='order' LIMIT 1 LOCK IN SHARE MODE",['actor'=>$actor,'id'=>$cid]);
                if(!$row||$row['policy_version']!==$version||$row['applied_version']!==$version
                    || !hash_equals((string)$row['snapshot_hash'],hash('sha256',(string)$row['snapshot_json']))
                    || !hash_equals((string)$row['snapshot_hash'],(string)$row['applied_hash']))continue;
                $snapshot=json_decode((string)$row['snapshot_json'],true,32);
                if(!is_array($snapshot)||!in_array($snapshot['schema_version']??null,[1,2,3],true)||($snapshot['can_invite']??null)!==false||!in_array($snapshot['state']??'', ['open','readonly'],true)
                    ||($snapshot['scope_key']??'')!==$row['scope_key']||!is_array($snapshot['members']??null)||count($snapshot['members'])>(($snapshot['schema_version']??0)===3?64:10))continue;
                $group=($snapshot['schema_version']??0)===3;
                if($group){
                    $epoch=$this->db->fetchValue('SELECT CAST(source_version AS CHAR) FROM chat_collaboration_groups WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$snapshot['order_id']]);
                    if((string)$epoch!==(string)($snapshot['source_version']??''))continue;
                }
                if($group){
                    $actual=[];foreach($snapshot['members'] as $person)$actual[(string)($person['actor_id']??'')]=true;
                    if(array_diff_key($actual,$lockedUsers??[])||array_diff_key($lockedUsers??[],$actual))continue;
                }
                $display=[];$roles=[];$allowed=false;
                foreach($snapshot['members'] as $member){
                    if($group){
                        $display[(string)($member['actor_id']??'')]=$member;
                        $member['role']=['merchant_owner'=>'customer','assigned_worker'=>'worker','owner_mobile_match'=>'owner','invite'=>'collaborator','platform_staff'=>'platform_staff'][$member['role_source']??'']??'';
                    }
                    if(!is_array($member)||($member['kind']??'')!=='mini'||($member['can_read']??null)!==true
                        ||!in_array($member['role']??'', ['customer','worker','platform_staff','owner','collaborator'],true))continue;
                    $id=$this->id($member['actor_id']??null);
                    if($member['role']==='platform_staff'&&!$this->staffCurrent($snapshot,$member))continue;
                    $roles[$id]=$member['role'];if($id===$actor)$allowed=true;
                }
                if(!$allowed)continue;
                // Former authors are returned only if the viewer can see an actual
                // message from them in the Go-owned historical membership window.
                if($request['sender_ids']){
                    $params=['cid'=>$cid,'viewer'=>$actor,'version'=>$version];$slots=[];
                    foreach($request['sender_ids'] as $i=>$id){$key='sender'.$i;$slots[]=':'.$key;$params[$key]=$id;}
                    $authors=$this->db->fetchAll("SELECT DISTINCT CAST(cm.actor_id AS CHAR) actor_id,cm.role FROM chat_members cm
                        JOIN chat_messages m ON m.conversation_id=cm.conversation_id AND m.sender_id=cm.actor_id
                        JOIN chat_conversations history ON history.id=m.conversation_id
                        WHERE cm.conversation_id=:cid AND cm.actor_kind='mini' AND cm.actor_id IN (".implode(',',$slots).") AND m.policy_version<=:version AND m.seq>history.sync_floor_seq AND m.seq<=history.last_seq
                        AND EXISTS(SELECT 1 FROM chat_member_intervals v WHERE v.conversation_id=m.conversation_id AND v.actor_id=:viewer
                        AND v.start_version<=m.policy_version AND (v.end_version IS NULL OR v.end_version>m.policy_version)) LIMIT 50",$params);
                    foreach($authors as $author)if(in_array($author['role'],['customer','worker','platform_staff'],true))$roles[(string)$author['actor_id']]=$author['role'];
                }
                $params=['assignment'=>$snapshot['assignment_id']??$row['current_assignment_id']];$slots=[];
                foreach(array_keys($roles) as $i=>$id){$key='actor'.$i;$slots[]=':'.$key;$params[$key]=$id;}
                $people=$this->db->fetchAll("SELECT CAST(u.id AS CHAR) actor_id,u.public_id,u.mobile_ciphertext,u.mobile_hmac,
                    rv.status real_name_status,rv.real_name_ciphertext
                    FROM users u LEFT JOIN assignments a ON a.id=:assignment
                    LEFT JOIN worker_profiles w ON w.id=a.worker_id AND w.user_id=u.id
                    LEFT JOIN worker_real_name_verifications rv ON rv.worker_id=w.id
                    WHERE u.status='active' AND u.id IN (".implode(',',$slots).") ORDER BY u.id",$params);
                $profiles=[];foreach($people as $person){
                    $id=(string)$person['actor_id'];$role=$roles[$id];
                    $kind=$role==='worker'?'worker':($role==='platform_staff'?'platform':($row['order_source_type']==='merchant'?'merchant':'user'));
                    $profile=$this->display($person,$kind);
                    $suffix=substr(preg_replace('/[^A-Za-z0-9]/','',(string)($person['public_id']??''))?:$id,-6);
                    if($group){
                        $profile['user_suffix']=$suffix;
                        $fact=$display[$id]??null;
                        if($fact&&in_array($fact['role_source'],['invite','owner_mobile_match'],true)){
                            $labels=['merchant'=>'商家','merchant_service'=>'商家客服','designer'=>'设计师','owner'=>'业主','coordinator'=>'对接人','project_manager'=>'项目负责人','team_worker'=>'团队师傅','other'=>'其他'];
                            $profile['role']='user';$profile['avatar']='user';$profile['display_role']=$fact['display_role'];$profile['role_source']=$fact['role_source'];
                            $profile['display_name']=($labels[$fact['display_role']]??'成员').' · 用户'.$suffix;
                        }elseif(!$fact){$profile['role']='user';$profile['avatar']='user';$profile['display_name']='用户'.$suffix;}
                    }
                    $profiles[]=$profile;
                }
                $items[]=['conversation_id'=>$cid,'policy_version'=>$version,'viewer_actor_id'=>$actor,'members'=>$profiles];
            }
            if((int)$login['expires_unix']<=time())throw new RuntimeException('登录状态已失效',401);
            return ['items'=>$items];
        });
    }
    private function staffCurrent(array $snapshot,array $member): bool
    {
        foreach(['0'=>(string)($snapshot['staff_config_version']??''),(string)$member['actor_id']=>(string)($member['authority_version']??'')] as $id=>$version){
            $current=$this->db->fetchValue('SELECT CAST(authority_version AS CHAR) FROM chat_staff_authority_versions WHERE user_id=:id LOCK IN SHARE MODE',['id'=>(string)$id]);
            if($version===''||(string)$current!==$version)return false;
        }
        return true;
    }
    /** Public for independent display-rule tests; inputs are always server rows. */
    public function display(array $row,string $kind): array
    {
        if(!in_array($kind,['user','merchant','worker','platform'],true))throw new RuntimeException('展示身份无效',400);
        $suffix=substr(preg_replace('/[^A-Za-z0-9]/','',(string)($row['public_id']??''))?:$this->id((string)$row['actor_id']),-6);
        $label=['user'=>'用户','merchant'=>'商家','worker'=>'师傅','platform'=>'平台工作人员'][$kind];
        $name=$label.$suffix;
        if($kind==='platform')$name=$label;
        elseif($kind==='worker'){
            if(($row['real_name_status']??'')==='approved'){
                $value=trim($this->cipher->decrypt((string)($row['real_name_ciphertext']??''),'worker-real-name'));
                if(preg_match('/^[\p{L}\p{M}· .-]{1,40}$/u',$value)===1)$name=$value;
            }
        }elseif(!empty($row['mobile_ciphertext'])&&!empty($row['mobile_hmac'])){
            $mobile=MobileNumber::normalize($this->cipher->decrypt((string)$row['mobile_ciphertext'],'user-mobile'));
            if($mobile!==''&&hash_equals((string)$row['mobile_hmac'],$this->cipher->hmac($mobile,'user-mobile')))$name=$label.substr($mobile,0,3).'****'.substr($mobile,-4);
        }
        return ['actor_id'=>$this->id((string)$row['actor_id']),'role'=>$kind,'display_name'=>$name,'avatar'=>$kind];
    }
}

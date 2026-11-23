<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use Closure;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\ConversationScope;

final class ChatDirect
{
    private const DEFAULT_DAILY_NEW_CONTACT_LIMIT = 5;
    private const MAX_DAILY_NEW_CONTACT_LIMIT = 20;

    public function __construct(private Database $db,private SettingsProvider $settings,private ChatIdentityDisplay $identityDisplay,
        private Closure $reserveDirect) {}

    public function enabled(bool $lock = false): bool
    {
        return (bool)$this->db->fetchValue('SELECT enabled FROM chat_direct_release WHERE id=1'.($lock ? ' LOCK IN SHARE MODE' : ''));
    }

    public function adminSettings(): ?array
    {
        if (!$this->db->tableExists('chat_direct_release')) return null;
        $row=$this->db->fetch("SELECT enabled,CAST(report_reviewer_admin_id AS CHAR) reviewer,
            COALESCE((SELECT setting_value FROM system_settings WHERE setting_key='chat.direct.daily_new_contact_limit'),'5') daily_new_contact_limit
            FROM chat_direct_release WHERE id=1");
        if(!$row)return null;
        $limit=(int)$row['daily_new_contact_limit'];
        $row['daily_new_contact_limit']=$limit>=1&&$limit<=self::MAX_DAILY_NEW_CONTACT_LIMIT?$limit:self::DEFAULT_DAILY_NEW_CONTACT_LIMIT;
        return $row;
    }

    public function configure(bool $enabled,string $reviewer,int $dailyNewContactLimit,int $admin): void
    {
        if($admin<1)throw new RuntimeException('管理员身份无效');
        if($dailyNewContactLimit<1||$dailyNewContactLimit>self::MAX_DAILY_NEW_CONTACT_LIMIT){
            throw new RuntimeException('每日主动联系新联系人数量需为1至20人');
        }
        $this->db->transaction(function()use($enabled,$reviewer,$dailyNewContactLimit,$admin){
            if ($reviewer!=='' && (!preg_match('/^[1-9][0-9]{0,19}$/D',$reviewer) || !$this->db->fetchValue(
                "SELECT id FROM admin_users WHERE id=:id AND status='active' AND role='super_admin' LOCK IN SHARE MODE",['id'=>$reviewer]
            ))) throw new RuntimeException('请选择有效的超级管理员作为举报审核人');
            $this->db->execute('UPDATE chat_direct_release SET enabled=:enabled,report_reviewer_admin_id=:reviewer WHERE id=1',
                ['enabled'=>(int)$enabled,'reviewer'=>$reviewer!==''?$reviewer:null]);
            $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at)
                VALUES('chat.direct.daily_new_contact_limit',:value,'int','chat',0,'普通私聊每日主动联系新陌生人数上限',:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type='int',group_name='chat',is_public=0,
                description=VALUES(description),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",
                ['value'=>(string)$dailyNewContactLimit,'admin'=>$admin]);
        });
    }

    public function execute(string $actor, string $token, string $action, array $body): array
    {
        if ($action === 'availability' && $body === []) return ['enabled'=>$this->enabled()];
        if (!$this->enabled()) throw new RuntimeException('CHAT_DIRECT_DISABLED', 403);
        if ($action==='card' && count($body)===2 && $this->id($body['relationship']??null) &&
            is_string($body['order']??null) && preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$body['order'])) {
            return $this->db->transaction(function () use ($actor,$token,$body) {
                $this->checkLogin($actor,$token);
                $peer=$this->peerFor($actor,$body['relationship']);
                $card=$this->db->fetch("SELECT o.public_no,IF(o.user_id=:actor,'customer','worker') role FROM orders o
                    JOIN assignments a ON a.id=o.current_assignment_id JOIN worker_profiles w ON w.id=a.worker_id
                    WHERE o.public_no=:number AND o.is_demo=0 AND ((o.user_id=:actor AND w.user_id=:peer)
                        OR (o.user_id=:peer AND w.user_id=:actor))",['number'=>$body['order'],'actor'=>$actor,'peer'=>$peer]);
                if(!$card)throw new RuntimeException('CHAT_FORBIDDEN',403);
                return $card;
            });
        }
        if ($action === 'inbox' && is_string($body['after']??null) &&
            preg_match('/^(0|[1-9][0-9]{0,19})$/D', $body['after']) && count($body)<=2 &&
            in_array($body['filter']??'active',['active','hidden','archived'],true)) {
            return $this->inbox($actor, $token, $body['after'], $body['filter']??'active');
        }

        $ref = $body['target'] ?? null;
        $source = $body['source'] ?? null;
        $id = $body['relationship'] ?? null;
        $version = $body['expected_version'] ?? null;
        $cmd = $body['transition'] ?? null;
        if ($action === 'entry' && (count($body) === 1 && $this->id($id) ||
            count($body) === 2 && $this->validReference($ref, $source))) {
            return $this->db->transaction(function () use ($actor, $token, $ref, $source, $id) {
                $this->checkLogin($actor, $token);
                $peer = $id !== null ? $this->peerFor($actor, $id) : $this->resolve($ref, $source);
                if ($peer===$actor) throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                $this->ensureOpen($actor,$peer);
                $this->identityDisplay->effective($actor,true);
                return $this->loadPair($actor,$peer,true);
            });
        }

        // Compatibility for clients released before 2.8.1. The server no longer
        // has an application state machine: request/accept are idempotent open,
        // while an explicit legacy reject is preserved as the user's block intent.
        if ($action === 'transition' && is_string($cmd) && in_array($cmd,['request','accept','reject'],true)
            && is_string($version) && preg_match('/^(0|[1-9][0-9]{0,19})$/D',$version)
            && (count($body) === 3 && $this->id($id) || count($body) === 4 && $this->validReference($ref,$source))) {
            return $this->legacyTransition($actor,$token,$id,$ref,$source,$cmd);
        }

        if ($action !== 'transition' || !is_string($cmd) || !in_array($cmd,['block','unblock'],true)
            || !is_string($version) || !preg_match('/^(0|[1-9][0-9]{0,19})$/D',$version)
            || !(count($body) === 3 && $this->id($id) || count($body) === 4 && $this->validReference($ref,$source))) {
            throw new RuntimeException('CHAT_INVALID_REQUEST',400);
        }

        return $this->db->transaction(function () use ($actor,$token,$id,$ref,$source,$version,$cmd) {
            if (!$this->enabled(true)) throw new RuntimeException('CHAT_DIRECT_DISABLED',403);
            $this->checkLogin($actor,$token);
            $peer = $id !== null ? $this->peerFor($actor,$id) : $this->resolve($ref,$source);
            if ($peer===$actor) throw new RuntimeException('CHAT_INVALID_REQUEST',400);
            $scope=$this->ensureOpen($actor,$peer);
            $row=$this->db->fetch('SELECT CAST(policy_version AS CHAR) version,CAST(requester_id AS CHAR) requester
                FROM chat_direct_guards WHERE scope_key=:scope FOR UPDATE',['scope'=>$scope]);
            if(!$row)throw new RuntimeException('CHAT_FORBIDDEN',403);
            if($row['version']!==$version)throw new RuntimeException('CHAT_VERSION_CONFLICT',409);

            $blocks=$this->blocks($actor,$peer,true);
            $ownBlock=in_array($actor,$blocks,true);
            if($ownBlock===($cmd==='block'))return $this->loadPair($actor,$peer);
            if($cmd==='block'){
                $this->db->execute('INSERT INTO chat_direct_blocks(blocker_id,blocked_id,created_at) VALUES(:me,:peer,UTC_TIMESTAMP(6))',
                    ['me'=>$actor,'peer'=>$peer]);
                $blocks[]=$actor;
            }else{
                $this->db->execute('DELETE FROM chat_direct_blocks WHERE blocker_id=:me AND blocked_id=:peer',['me'=>$actor,'peer'=>$peer]);
                $blocks=array_values(array_diff($blocks,[$actor]));
            }
            $this->publish($scope,$actor,$peer,$row['requester'],'accepted',$blocks!==[],$cmd);
            return $this->loadPair($actor,$peer);
        });
    }

    private function legacyTransition(string $actor,string $token,mixed $id,mixed $ref,mixed $source,string $cmd): array
    {
        return $this->db->transaction(function () use ($actor,$token,$id,$ref,$source,$cmd) {
            if (!$this->enabled(true)) throw new RuntimeException('CHAT_DIRECT_DISABLED',403);
            $this->checkLogin($actor,$token);
            $peer = $id !== null ? $this->peerFor($actor,(string)$id) : $this->resolve((string)$ref,(string)$source);
            if ($peer===$actor) throw new RuntimeException('CHAT_INVALID_REQUEST',400);
            $scope=$this->ensureOpen($actor,$peer);
            if ($cmd!=='reject') return $this->loadPair($actor,$peer,true);

            $row=$this->db->fetch('SELECT CAST(requester_id AS CHAR) requester FROM chat_direct_guards WHERE scope_key=:scope FOR UPDATE',['scope'=>$scope]);
            if(!$row)throw new RuntimeException('CHAT_FORBIDDEN',403);
            $blocks=$this->blocks($actor,$peer,true);
            if(in_array($actor,$blocks,true))return $this->loadPair($actor,$peer);
            $this->db->execute('INSERT INTO chat_direct_blocks(blocker_id,blocked_id,created_at) VALUES(:me,:peer,UTC_TIMESTAMP(6))',
                ['me'=>$actor,'peer'=>$peer]);
            $blocks[]=$actor;
            $this->publish($scope,$actor,$peer,$row['requester'],'accepted',true,'block');
            return $this->loadPair($actor,$peer);
        });
    }

    private function ensureOpen(string $actor,string $peer): string
    {
        $scope=($this->reserveDirect)($actor,$peer);
        $row=$this->db->fetch('SELECT CAST(requester_id AS CHAR) requester,application_state
            FROM chat_direct_guards WHERE scope_key=:scope FOR UPDATE',['scope'=>$scope]);
        if(!$row||$row['application_state']!=='accepted'){
            $blocks=$this->blocks($actor,$peer,true);
            $this->publish($scope,$actor,$peer,$row['requester']??$actor,'accepted',$blocks!==[],'open');
        }
        return $scope;
    }

    private function publish(string $scope,string $actor,string $peer,string $requester,string $application,bool $blocked,string $action): void
    {
        [$first,$second] = ConversationScope::pair($actor,$peer);
        $body = json_encode(['schema_version'=>5,'scope_key'=>$scope,'first'=>$first,'second'=>$second,
            'requester'=>$requester,'application'=>$application,'blocked'=>$blocked],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $this->db->execute(
            'INSERT INTO chat_direct_guards(scope_key,policy_version,requester_id,application_state,snapshot_json,snapshot_hash,changed_at)
             VALUES(:scope,1,:requester,:state,:body,:hash,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE policy_version=policy_version+1,
              requester_id=VALUES(requester_id),application_state=VALUES(application_state),snapshot_json=VALUES(snapshot_json),
              snapshot_hash=VALUES(snapshot_hash),changed_at=IF(application_state=VALUES(application_state) AND :action IN (\'block\',\'unblock\'),changed_at,VALUES(changed_at))',
            ['scope'=>$scope,'requester'=>$requester,'state'=>$application,'body'=>$body,'hash'=>hash('sha256',$body),'action'=>$action]
        );
        $params = ['scope'=>$scope,'actor'=>$actor,'action'=>$action];
        $this->db->execute('INSERT INTO chat_direct_actions(scope_key,actor_id,policy_version,action,created_at)
            SELECT scope_key,:actor,policy_version,:action,UTC_TIMESTAMP(6) FROM chat_direct_guards WHERE scope_key=:scope',$params);
        $this->db->execute("INSERT INTO chat_business_events(event_id,aggregate_key,aggregate_version,schema_version,event_type,payload_json,created_at)
            SELECT :event,scope_key,policy_version,5,'chat.direct.changed',snapshot_json,UTC_TIMESTAMP() FROM chat_direct_guards WHERE scope_key=:scope",
            ['event'=>bin2hex(random_bytes(16)),'scope'=>$scope]);
    }

    private function inbox(string $actor,string $token,string $after,string $filter): array
    {
        return $this->db->transaction(function () use ($actor,$token,$after,$filter) {
            $this->checkLogin($actor,$token);
            $from=" FROM chat_direct_actions a JOIN chat_direct_guards g ON g.scope_key=a.scope_key AND g.policy_version=a.policy_version
                JOIN chat_direct_relationships r ON g.scope_key=CONCAT('direct:',r.actor_a,':',r.actor_b)
                LEFT JOIN chat_conversations c ON c.conversation_type='direct' AND c.scope_key=g.scope_key
                LEFT JOIN chat_scene_preferences opt ON opt.conversation_id=c.id AND opt.actor_id=:viewer AND opt.member_key_hash=SHA2(CONCAT(g.scope_key,':',:viewer),256)
                LEFT JOIN chat_user_preferences prefs ON prefs.conversation_id=c.id AND prefs.actor_id=:viewer AND prefs.member_key_hash=SHA2(CONCAT(g.scope_key,':',:viewer),256)
                WHERE (r.actor_a=:viewer OR r.actor_b=:viewer)";
            $position='COALESCE(c.last_message_at,a.created_at)';
            $pin='COALESCE(prefs.pinned,0)';
            $params=['viewer'=>$actor];
            $from.=match($filter) {
                'hidden'=>' AND opt.hidden=1',
                'archived'=>' AND opt.archived=1',
                default=>' AND COALESCE(opt.hidden,0)=0 AND COALESCE(opt.archived,0)=0',
            };
            if($after!=='0'){
                $cursor=$this->db->fetch("SELECT {$position} activity_key,{$pin} pinned".$from.' AND a.id=:after',$params+['after'=>$after]);
                if(!$cursor)throw new RuntimeException('CHAT_RESYNC_REQUIRED',409);
                $from.=" AND ({$pin}<:pinned OR ({$pin}=:pinned AND ({$position}<:at OR ({$position}=:at AND a.id<:after))))";
                $params+=['pinned'=>$cursor['pinned'],'at'=>$cursor['activity_key'],'after'=>$after];
            }
            $rows = $this->db->fetchAll(
                "SELECT CAST(a.id AS CHAR) action_id,CAST(IF(r.actor_a=:viewer,r.actor_b,r.actor_a) AS CHAR) peer,
                    COALESCE(opt.muted,0) muted {$from} ORDER BY {$pin} DESC,{$position} DESC,a.id DESC LIMIT 21",
                $params
            );
            $page = array_slice($rows,0,20);
            return ['items'=>array_map(fn($row)=>$this->loadPair($actor,$row['peer'])+['muted'=>(bool)$row['muted']],$page),
                'next'=>count($rows)>20?$page[19]['action_id']:''];
        });
    }

    private function peerFor(string $actor,string $id): string
    {
        $peer = $this->db->fetchValue("SELECT CAST(IF(r.actor_a=:me,r.actor_b,r.actor_a) AS CHAR)
            FROM chat_conversations c JOIN chat_direct_relationships r ON c.scope_key=CONCAT('direct:',r.actor_a,':',r.actor_b)
            WHERE c.id=:id AND c.conversation_type='direct' AND (r.actor_a=:left_id OR r.actor_b=:right_id)",
            ['me'=>$actor,'id'=>$id,'left_id'=>$actor,'right_id'=>$actor]);
        if (!$peer) throw new RuntimeException('CHAT_FORBIDDEN',403);
        return (string)$peer;
    }

    private function loadPair(string $actor,string $peer,bool $prime=false): array
    {
        $scope = ConversationScope::direct($actor,$peer);
        $guard = $this->db->fetch(
            "SELECT CAST(g.policy_version AS CHAR) version,g.application_state,CAST(g.requester_id AS CHAR) requester,
                    CAST(c.id AS CHAR) relationship,c.snapshot_hash=g.snapshot_hash AND c.policy_version=g.policy_version projected,
                    c.last_message_id IS NOT NULL started
             FROM chat_direct_guards g LEFT JOIN chat_conversations c ON c.conversation_type='direct' AND c.scope_key=g.scope_key
             WHERE g.scope_key=:scope",['scope'=>$scope]
        );
        $profile = $this->identityDisplay->effective($peer,$prime);
        $blocks = $guard ? $this->blocks($actor,$peer,false) : [];
        return ['relationship'=>$guard['relationship']??null,'ready'=>!empty($guard['projected']),
            'started'=>!empty($guard['started']),'version'=>$guard['version']??'0',
            'application'=>$guard['application_state']??'accepted','requested_by_me'=>($guard['requester']??'')===$actor,
            'blocked'=>$blocks!==[],'blocked_by_me'=>in_array($actor,$blocks,true),'peer_id'=>$peer,'peer'=>$profile];
    }

    private function blocks(string $actor,string $peer,bool $lock): array
    {
        return array_column($this->db->fetchAll(
            'SELECT CAST(blocker_id AS CHAR) blocker FROM chat_direct_blocks WHERE (blocker_id=:a AND blocked_id=:b)
             OR (blocker_id=:c AND blocked_id=:d) ORDER BY blocker_id'.($lock?' FOR UPDATE':''),
            ['a'=>$actor,'b'=>$peer,'c'=>$peer,'d'=>$actor]
        ),'blocker');
    }

    private function checkLogin(string $actor,string $token): void
    {
        if (!$this->db->fetchValue("SELECT s.id FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
            WHERE s.user_id=:actor AND s.token_hash=:token AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
            AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0 AND COALESCE(s.test_instance_id,0)=0
            AND s.test_actor_expires_at IS NULL LOCK IN SHARE MODE",['actor'=>$actor,'token'=>hash('sha256',$token)])) {
            throw new RuntimeException('CHAT_UNAUTHORIZED',401);
        }
    }

    private function resolve(string $reference,string $source): string
    {
        $app=trim((string)$this->settings->get('wechat.miniapp_appid',''));
        if($app==='')throw new RuntimeException('CHAT_DEPENDENCY_UNAVAILABLE',503);
        $identity=" AND (SELECT COUNT(*) FROM wechat_identities wi
            WHERE wi.user_id=u.id AND BINARY wi.app_id=BINARY :app AND wi.status='active')=1";
        $sql = $source === 'worker'
            ? "SELECT DISTINCT CAST(u.id AS CHAR) id FROM worker_profiles w JOIN users u ON u.id=w.user_id AND u.status='active'
               WHERE (w.public_id=:a OR BINARY w.cloud_worker_id=BINARY :b) AND w.verification_status='approved' AND w.invited_only=0
               AND COALESCE(w.cloud_record_mode,'')<>'demo_local'"
            : "SELECT CAST(u.id AS CHAR) id FROM users u WHERE u.public_id=:a AND u.status='active'";
        $params=$source==='worker'?['a'=>$reference,'b'=>$reference,'app'=>$app]:['a'=>$reference,'app'=>$app];
        $rows = $this->db->fetchAll($sql.$identity.' LIMIT 2',$params);
        if (count($rows)!==1) throw new RuntimeException('CHAT_FORBIDDEN',403);
        return $rows[0]['id'];
    }

    private function validReference(mixed $value,mixed $source): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$value)
            && in_array($source,['worker','public_user'],true) && ($source==='worker'||strlen($value)===26);
    }

    private function id(mixed $id): bool
    {
        return is_string($id) && (bool)preg_match('/^[1-9][0-9]{0,19}$/D',$id);
    }
}

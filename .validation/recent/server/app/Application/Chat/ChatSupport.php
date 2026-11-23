<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use Closure;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\ConversationScope;

final class ChatSupport
{
    public function __construct(private Database $db,private Closure $readStaffAuthority,private Closure $reserveFeedback) {}

    public function settings(bool $lock = false): array
    {
        $row = $this->db->fetch(
            "SELECT s.display_name, s.enabled, CAST(s.revision AS CHAR) revision,
                    s.images_enabled, CAST(s.image_generation AS CHAR) image_generation,
                    COALESCE((SELECT setting_value FROM system_settings WHERE setting_key='chat.support.auto_reply_enabled'),'0') auto_reply_enabled,
                    COALESCE((SELECT setting_value FROM system_settings WHERE setting_key='chat.support.auto_reply_text'),'') auto_reply_text
             FROM chat_support_service s WHERE s.service_key='platform_feedback'".($lock ? ' FOR UPDATE' : '')
        );
        if (!$row) throw new RuntimeException('CHAT_SUPPORT_UNAVAILABLE', 503);
        $row['auto_reply_enabled']=in_array(strtolower((string)$row['auto_reply_enabled']),['1','true'],true);
        $row['auto_reply_text']=(string)$row['auto_reply_text'];
        return $row;
    }

    public function configurationOptions(): array
    {
        return $this->db->transaction(function (): array {
            $facts = ($this->readStaffAuthority)();
            $options = [];
            foreach ($facts['platform_staff'] as $member) {
                $actor = $member['actor_id'];
                $options[] = $this->db->fetch('SELECT CAST(id AS CHAR) actor_id,nickname,public_id FROM users WHERE id=:id', ['id'=>$actor]);
            }
            $current = $this->db->fetchAll('SELECT CAST(actor_id AS CHAR) actor_id FROM chat_support_roster WHERE enabled=1 ORDER BY actor_id');
            return ['options'=>$options, 'selected'=>count($current)===1?$current[0]['actor_id']:''];
        });
    }

    public function configure(string $revision, string $name, bool $enabled, int $admin, bool $images, string $handler, bool $autoReply=false, string $autoReplyText=''): void
    {
        $name = trim($name);
        $autoReplyText = trim($autoReplyText);
        if ($admin < 1 || !mb_check_encoding($name, 'UTF-8') || $name === '' || mb_strlen($name, 'UTF-8') > 40 || preg_match('/[\p{C}<>]/u', $name)) {
            throw new RuntimeException('请输入不超过40字的服务名称');
        }
        if (!mb_check_encoding($autoReplyText,'UTF-8') || mb_strlen($autoReplyText,'UTF-8')>500 || strlen($autoReplyText)>2000 || preg_match('/[\x00<>]/u',$autoReplyText)) {
            throw new RuntimeException('自动回复最多500字，且不能包含无效控制字符');
        }
        if ($autoReply && $autoReplyText==='') throw new RuntimeException('启用自动回复后请填写回复内容');
        if (($enabled || $handler!=='') && !preg_match('/^[1-9][0-9]{0,19}$/D', $handler)) {
            throw new RuntimeException('请选择一个反馈处理管理员');
        }
        $this->db->transaction(function () use ($revision, $name, $enabled, $admin, $images, $handler, $autoReply, $autoReplyText) {
            $before = $this->settings(true);
            if ($revision !== $before['revision']) throw new RuntimeException('配置已变化，请刷新后重试');

            $facts = ($this->readStaffAuthority)();
            $authority = array_column($facts['platform_staff'], 'authority_version', 'actor_id');
            if ($handler!=='' && !isset($authority[$handler])) throw new RuntimeException('请选择当前有效的小程序管理员');

            $roster = $this->db->fetchAll('SELECT CAST(actor_id AS CHAR) actor_id,CAST(generation AS CHAR) generation,
                CAST(user_version AS CHAR) user_version,CAST(config_version AS CHAR) config_version
                FROM chat_support_roster WHERE enabled=1 ORDER BY actor_id FOR UPDATE');
            $before['handlers'] = array_column($roster, 'actor_id');
            $selected = null;
            $handlerChanged = false;
            foreach ($roster as $current) {
                $actor = $current['actor_id'];
                if ($actor === $handler && $current['user_version'] === $authority[$handler]
                    && $current['config_version'] === $facts['staff_config_version']) {
                    $selected = ['actor_id'=>$actor,'generation'=>$current['generation']];
                } elseif ($actor !== $handler) {
                    $this->db->execute('UPDATE chat_support_roster SET enabled=0,generation=generation+1 WHERE actor_id=:actor', ['actor'=>$actor]);
                    $this->recordRoster($actor,$admin);
                    $handlerChanged = true;
                }
            }
            if ($handler!=='' && $selected===null) {
                $this->db->execute('INSERT INTO chat_support_roster(actor_id,enabled,generation,user_version,config_version)
                    VALUES(:actor,1,1,:version,:config) ON DUPLICATE KEY UPDATE enabled=1,generation=generation+1,
                    user_version=VALUES(user_version),config_version=VALUES(config_version)',
                    ['actor'=>$handler,'version'=>$authority[$handler],'config'=>$facts['staff_config_version']]);
                $this->recordRoster($handler,$admin);
                $selected = ['actor_id'=>$handler, 'generation'=>(string)$this->db->fetchValue(
                    'SELECT CAST(generation AS CHAR) FROM chat_support_roster WHERE actor_id=:actor', ['actor'=>$handler])];
                $handlerChanged = true;
            }
            $assigned = $this->assignFeedback($selected,$admin);
            $imageChanged = $enabled !== (bool)$before['enabled'] || $images !== (bool)$before['images_enabled'];
            $autoChanged = $autoReply !== (bool)$before['auto_reply_enabled'] || $autoReplyText !== (string)$before['auto_reply_text'];
            if ($name === $before['display_name'] && !$imageChanged && !$autoChanged && !$handlerChanged && !$assigned) return;
            $this->putSupportSetting('chat.support.auto_reply_enabled',$autoReply?'1':'0','bool','平台反馈首次消息自动回复开关',$admin);
            $this->putSupportSetting('chat.support.auto_reply_text',$autoReplyText,'string','平台反馈首次消息自动回复内容',$admin);
            $this->db->execute(
                "UPDATE chat_support_service SET display_name=:name,enabled=:enabled,images_enabled=:images,
                    revision=revision+1,image_generation=image_generation+:image_changed WHERE service_key='platform_feedback'",
                ['name'=>$name,'enabled'=>(int)$enabled,'images'=>(int)$images,'image_changed'=>(int)$imageChanged]
            );
            $after = $this->settings(true);
            $after['handlers'] = $selected===null?[]:[$selected['actor_id']];
            $this->db->execute(
                'INSERT INTO chat_support_service_audit(revision,operated_by,old_json,new_json,created_at) VALUES(:rev,:admin,:old,:new,UTC_TIMESTAMP(6))',
                ['rev'=>$after['revision'],'admin'=>$admin,'old'=>json_encode($before,JSON_THROW_ON_ERROR),'new'=>json_encode($after,JSON_THROW_ON_ERROR)]
            );
        });
    }

    private function putSupportSetting(string $key,string $value,string $type,string $description,?int $admin):void
    {
        $this->db->execute("INSERT INTO system_settings(setting_key,setting_value,value_type,group_name,is_public,description,updated_by,created_at,updated_at)
            VALUES(:key,:value,:type,'chat',0,:description,:admin,UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),value_type=VALUES(value_type),group_name='chat',is_public=0,
            description=VALUES(description),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()",
            ['key'=>$key,'value'=>$value,'type'=>$type,'description'=>$description,'admin'=>$admin]);
    }

    private function recordRoster(string $actor,int $admin): void
    {
        $this->db->execute('INSERT INTO chat_support_roster_audit(actor_id,admin_id,generation,enabled,created_at)
            SELECT actor_id,:admin,generation,enabled,UTC_TIMESTAMP(6) FROM chat_support_roster WHERE actor_id=:actor',
            ['actor'=>$actor,'admin'=>$admin]);
    }

    public function frontendSettings(string $actor):array
    {
        if(!$this->isStaff($actor))throw new RuntimeException('CHAT_FORBIDDEN',403);
        $cfg=$this->settings();
        return ['revision'=>(string)$cfg['revision'],'name'=>(string)$cfg['display_name'],
            'auto_reply_enabled'=>(bool)$cfg['auto_reply_enabled'],'auto_reply_text'=>(string)$cfg['auto_reply_text']];
    }

    public function configureFrontend(string $actor,string $revision,string $name,bool $autoReply,string $autoReplyText):array
    {
        if(!$this->isStaff($actor))throw new RuntimeException('CHAT_FORBIDDEN',403);
        $name=trim($name);$autoReplyText=trim($autoReplyText);
        if(!mb_check_encoding($name,'UTF-8')||$name===''||mb_strlen($name,'UTF-8')>40||preg_match('/[\p{C}<>]/u',$name))
            throw new RuntimeException('CHAT_INVALID_REQUEST',400);
        if(!mb_check_encoding($autoReplyText,'UTF-8')||mb_strlen($autoReplyText,'UTF-8')>500||strlen($autoReplyText)>2000||preg_match('/[\x00<>]/u',$autoReplyText))
            throw new RuntimeException('CHAT_INVALID_REQUEST',400);
        if($autoReply&&$autoReplyText==='')throw new RuntimeException('CHAT_INVALID_REQUEST',400);
        return $this->db->transaction(function()use($actor,$revision,$name,$autoReply,$autoReplyText):array{
            $before=$this->settings(true);
            if(!$this->isStaff($actor))throw new RuntimeException('CHAT_FORBIDDEN',403);
            if($revision!==(string)$before['revision'])throw new RuntimeException('CHAT_VERSION_CONFLICT',409);
            if($name===(string)$before['display_name']&&$autoReply===(bool)$before['auto_reply_enabled']&&$autoReplyText===(string)$before['auto_reply_text'])
                return ['revision'=>(string)$before['revision'],'name'=>(string)$before['display_name'],
                    'auto_reply_enabled'=>(bool)$before['auto_reply_enabled'],'auto_reply_text'=>(string)$before['auto_reply_text']];
            $this->putSupportSetting('chat.support.auto_reply_enabled',$autoReply?'1':'0','bool','平台反馈首次消息自动回复开关',null);
            $this->putSupportSetting('chat.support.auto_reply_text',$autoReplyText,'string','平台反馈首次消息自动回复内容',null);
            $this->db->execute("UPDATE chat_support_service SET display_name=:name,revision=revision+1 WHERE service_key='platform_feedback'",['name'=>$name]);
            $after=$this->settings(true);
            $this->db->execute('INSERT INTO chat_support_service_audit(revision,operated_by,old_json,new_json,created_at) VALUES(:rev,:actor,:old,:new,UTC_TIMESTAMP(6))',
                ['rev'=>$after['revision'],'actor'=>(int)$actor,'old'=>json_encode($before,JSON_THROW_ON_ERROR),'new'=>json_encode($after,JSON_THROW_ON_ERROR)]);
            return ['revision'=>(string)$after['revision'],'name'=>(string)$after['display_name'],
                'auto_reply_enabled'=>(bool)$after['auto_reply_enabled'],'auto_reply_text'=>(string)$after['auto_reply_text']];
        });
    }

    public function entry(string $actor,bool $sandbox=false): array
    {
        if($sandbox&&!\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active())throw new RuntimeException('CHAT_FORBIDDEN',403);
        $cfg = $this->settings();
        $scope = ConversationScope::support($actor,$sandbox);
        $cid = $cfg['enabled'] ? $this->db->fetchValue(
            "SELECT CAST(id AS CHAR) FROM chat_conversations WHERE conversation_type='support' AND scope_key=:scope",
            ['scope'=>$scope]
        ) : null;
        $staff = $cfg['enabled'] && $this->isStaff($actor);
        return ['enabled'=>(bool)$cfg['enabled'], 'staff'=>$staff, 'can_manage'=>$staff, 'name'=>$cfg['display_name'], 'version'=>$cfg['revision'],
                'images_enabled'=>(bool)$cfg['enabled'], 'conversation_id'=>$cid ?: null];
    }

    public function isStaff(string $actor): bool
    {
        return (bool)$this->db->fetchValue("SELECT 1 FROM chat_support_roster r
            JOIN chat_support_service s ON s.service_key='platform_feedback' AND s.enabled=1
            JOIN chat_staff_authority_versions u ON u.user_id=r.actor_id AND u.authority_version=r.user_version
            JOIN chat_staff_authority_versions c ON c.user_id=0 AND c.authority_version=r.config_version
            WHERE r.actor_id=:actor AND r.enabled=1", ['actor'=>$actor]);
    }

    private function assignedHandler(): ?array
    {
        $rows=$this->db->fetchAll('SELECT CAST(r.actor_id AS CHAR) actor_id,CAST(r.generation AS CHAR) generation
            FROM chat_support_roster r
            JOIN chat_staff_authority_versions u ON u.user_id=r.actor_id AND u.authority_version=r.user_version
            JOIN chat_staff_authority_versions c ON c.user_id=0 AND c.authority_version=r.config_version
            WHERE r.enabled=1 LIMIT 2 LOCK IN SHARE MODE');
        return count($rows)===1?$rows[0]:null;
    }

    private function writeStaffSnapshot(string $scope,string $owner,array $staff): void
    {
        $testPrefix='support:'.ConversationScope::FEEDBACK_TEST_SERVICE.':';
        $service=str_starts_with($scope,$testPrefix)?ConversationScope::FEEDBACK_TEST_SERVICE:ConversationScope::FEEDBACK_SERVICE;
        $body=json_encode(['schema_version'=>6,'scope_key'=>$scope,'owner_id'=>$owner,
            'service_key'=>$service,'staff'=>array_values($staff)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $this->db->execute('INSERT INTO chat_support_guards(scope_key,owner_id,policy_version,snapshot_json,snapshot_hash)
            VALUES(:scope,:owner,1,:body,:hash) ON DUPLICATE KEY UPDATE policy_version=policy_version+1,
            snapshot_json=VALUES(snapshot_json),snapshot_hash=VALUES(snapshot_hash)',
            ['scope'=>$scope,'owner'=>$owner,'body'=>$body,'hash'=>hash('sha256',$body)]);
        $this->db->execute("INSERT INTO chat_business_events(event_id,aggregate_key,aggregate_version,schema_version,event_type,payload_json,created_at)
            SELECT :event,scope_key,policy_version,6,'chat.support.assigned',snapshot_json,UTC_TIMESTAMP() FROM chat_support_guards WHERE scope_key=:scope",
            ['event'=>bin2hex(random_bytes(16)),'scope'=>$scope]);
    }

    private function assignFeedback(?array $handler,int $admin): bool
    {
        $changed=false;
        $guards=$this->db->fetchAll('SELECT scope_key,CAST(owner_id AS CHAR) owner_id,
            CAST(policy_version+1 AS CHAR) next_version,snapshot_json FROM chat_support_guards ORDER BY scope_key FOR UPDATE');
        foreach ($guards as $guard) {
            $scope=$guard['scope_key'];
            $old=json_decode($guard['snapshot_json'],true,32,JSON_THROW_ON_ERROR)['staff'];
            $people=array_column($old,null,'actor_id');
            $selected=$handler!==null && $handler['actor_id']!==$guard['owner_id']?$handler:null;
            $continue=$selected!==null && ($people[$selected['actor_id']]['generation']??null)===$selected['generation'];
            if (count($old)===($selected===null?0:1) && ($selected===null || $continue)) continue;

            if ($selected!==null) {
                $selected['assignment_version']=$continue?$people[$selected['actor_id']]['assignment_version']:$guard['next_version'];
            }
            $this->writeStaffSnapshot($scope,$guard['owner_id'],$selected===null?[]:[$selected]);
            if ($selected!==null && !$continue) {
                $through=(string)($this->db->fetchValue("SELECT CAST(last_seq AS CHAR) FROM chat_conversations
                    WHERE conversation_type='support' AND scope_key=:scope FOR UPDATE",['scope'=>$scope])?:'0');
                $this->db->execute('INSERT INTO chat_support_history_access(scope_key,actor_id,assignment_version,through_seq)
                    VALUES(:scope,:actor,:assignment,:through)',
                    ['scope'=>$scope,'actor'=>$selected['actor_id'],'assignment'=>$selected['assignment_version'],'through'=>$through]);
                $this->recordHandover($scope,$guard['next_version'],$selected,$admin,true,$through);
            }
            foreach ($old as $removed) {
                if ($selected===null || $removed['actor_id']!==$selected['actor_id']) {
                    $this->recordHandover($scope,$guard['next_version'],$removed,$admin,false,'0');
                }
            }
            $changed=true;
        }
        return $changed;
    }

    private function recordHandover(string $scope,string $version,array $member,int $admin,bool $assigned,string $through):void
    {
        $this->db->execute('INSERT INTO chat_support_handover_audit(scope_key,policy_version,actor_id,admin_id,generation,assigned,through_seq,created_at)
            VALUES(:scope,:version,:actor,:admin,:generation,:assigned,:through,UTC_TIMESTAMP(6))',
            ['scope'=>$scope,'version'=>$version,'actor'=>$member['actor_id'],'admin'=>$admin,'generation'=>$member['generation'],
             'assigned'=>(int)$assigned,'through'=>$through]);
    }

    public function prepare(string $actor,string $token,bool $sandbox=false):array
    {
        return $this->db->transaction(function()use($actor,$token,$sandbox){
            $cfg=$this->settings(true);
            if(!$cfg['enabled'])throw new RuntimeException('CHAT_SUPPORT_DISABLED',403);
            if($sandbox){
                if(!\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active())throw new RuntimeException('CHAT_FORBIDDEN',403);
                $test=(new ChatSimulation($this->db))->identity($token);
                if(!is_array($test)||empty($test['sandbox'])||!empty($test['mini_admin'])
                    ||!in_array((string)($test['role']??''),['customer','worker'],true)
                    ||(string)($test['actor_id']??'')!==$actor)throw new RuntimeException('CHAT_UNAUTHORIZED',401);
            }else{
                $login=$this->db->fetchValue("SELECT id FROM login_sessions WHERE user_id=:actor AND token_hash=:hash AND status='active' AND expires_at>UTC_TIMESTAMP() AND COALESCE(demo_actor_user_id,0)=0 AND COALESCE(test_actor_user_id,0)=0 AND COALESCE(test_instance_id,0)=0 AND test_actor_expires_at IS NULL LOCK IN SHARE MODE",['actor'=>$actor,'hash'=>hash('sha256',$token)]);
                if(!$login)throw new RuntimeException('CHAT_UNAUTHORIZED',401);
            }
            $scope=($this->reserveFeedback)($actor,$sandbox);
            if(!$this->db->fetchValue('SELECT scope_key FROM chat_support_guards WHERE scope_key=:scope FOR UPDATE',['scope'=>$scope])){
                $handler=$this->assignedHandler();
                $selected=[];
                if ($handler!==null && $handler['actor_id']!==$actor) {
                    $handler['assignment_version']='1';
                    $selected[]=$handler;
                }
                $this->writeStaffSnapshot($scope,$actor,$selected);
            }
            $result=$this->entry($actor,$sandbox);
            return $result+['state'=>$result['conversation_id']?'ready':'preparing'];
        });
    }
}

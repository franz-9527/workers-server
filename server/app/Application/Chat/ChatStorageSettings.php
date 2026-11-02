<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;

/** PHP owns configuration only. Existing-message changes are applied by Go. */
final class ChatStorageSettings
{
    private Database $db;
    private string $key;
    public function __construct(Database $db, string $key) { $this->db=$db; $this->key=$key; }
    public function current(int $page=1): array
    {
        $page=max(1,min(10000,$page));$offset=($page-1)*20;
        $row=$this->db->fetch('SELECT revision,policy_json,CAST(apply_through_id AS CHAR) apply_through_id,updated_at FROM chat_storage_policy WHERE id=1');
        if(!$row)throw new RuntimeException('请先完成聊天数据库升级');
        $policy=json_decode((string)$row['policy_json'],true,32,JSON_THROW_ON_ERROR);
        self::validate($policy);
        $application=$this->db->fetch('SELECT state,last_message_id,diagnostic FROM chat_storage_applications WHERE revision=:revision',['revision'=>$row['revision']]);
        $applications=$this->db->fetchAll('SELECT revision,state,last_message_id,errors,diagnostic FROM chat_storage_applications ORDER BY revision DESC LIMIT 21 OFFSET '.$offset);
        return ['pagination'=>['page'=>$page,'has_more'=>count($applications)>20],'collaboration'=>$this->db->fetch('SELECT member_limit,CAST(revision AS CHAR) revision FROM chat_collaboration_settings WHERE id=1'),'applications'=>array_slice($applications,0,20),'revision'=>(string)$row['revision'],'policy'=>$policy,'apply_through_id'=>(string)$row['apply_through_id'],'application'=>$application,'updated_at'=>$row['updated_at']];
    }
    public static function validate(array $p): void
    {
        self::shape($p,['schema','retention','tiering','quota']);
        if($p['schema']!==1)throw new RuntimeException('保存策略版本不支持');
        if(!is_array($p['retention'])||!is_array($p['tiering'])||!is_array($p['quota']))throw new RuntimeException('保存策略格式不正确');
        self::shape($p['retention'],['text','image','audio','video']);
        foreach($p['retention'] as $rule){
            if(!is_array($rule))throw new RuntimeException('保存期限不正确');
            self::shape($rule,['mode','days']);
            if(!is_int($rule['days'])||!($rule['mode']==='permanent'&&$rule['days']===0||$rule['mode']==='days'&&$rule['days']>=1&&$rule['days']<=3650))throw new RuntimeException('请选择永久保存或1至3650天');
        }
        $t=$p['tiering'];self::shape($t,['enabled','images','audio','videos','age_days','idle_days','min_bytes','remaining_days','batch']);
        foreach(['enabled','images','audio','videos'] as $k)if(!is_bool($t[$k]))throw new RuntimeException('低频开关格式不正确');
        if($t['enabled']&&!$t['images']&&!$t['audio']&&!$t['videos'])throw new RuntimeException('请选择低频适用类型');
        foreach(['age_days'=>[30,3650],'idle_days'=>[1,3650],'min_bytes'=>[65536,1073741824],'remaining_days'=>[32,3650],'batch'=>[1,50]] as $k=>$range)self::bounded($t[$k],$range[0],$range[1]);
        self::shape($p['quota'],['video_count','video_bytes']);
        self::bounded($p['quota']['video_count'],1,200);self::bounded($p['quota']['video_bytes'],20971520,2147483648);
    }
    private static function shape(array $v,array $keys): void
    {
        $actual=array_keys($v);sort($actual);sort($keys);
        if($actual!==$keys)throw new RuntimeException('配置字段缺失或包含未知字段');
    }
    private static function bounded($v,int $min,int $max): void
    {
        if(!is_int($v)||$v<$min||$v>$max)throw new RuntimeException('配置数字超出允许范围');
    }
    private static function number(array $input,string $key,int $min,int $max): int
    {
        $value=$input[$key]??null;
        if(!is_string($value)||preg_match('/^(0|[1-9][0-9]{0,10})$/D',$value)!==1)throw new RuntimeException('请填写完整的整数配置');
        $n=(int)$value;self::bounded($n,$min,$max);return $n;
    }
    public static function fromForm(array $input): array
    {
        $p=['schema'=>1,'retention'=>[],'tiering'=>[],'quota'=>[]];
        foreach(['text','image','audio','video'] as $kind){
            $mode=$input[$kind.'_mode']??null;
            if(!is_string($mode)||!in_array($mode,['permanent','days'],true))throw new RuntimeException('请选择各类型的保存方式');
            $p['retention'][$kind]=['mode'=>$mode,'days'=>$mode==='permanent'?0:self::number($input,$kind.'_days',1,3650)];
        }
        foreach(['enabled','images','audio','videos'] as $key){
            $v=$input['tier_'.$key]??'0';
            if(!is_string($v)||!in_array($v,['0','1'],true))throw new RuntimeException('低频开关格式不正确');
            $p['tiering'][$key]=$v==='1';
        }
        foreach(['age_days'=>[30,3650],'idle_days'=>[1,3650],'remaining_days'=>[32,3650],'batch'=>[1,50]] as $key=>$range)$p['tiering'][$key]=self::number($input,'tier_'.$key,$range[0],$range[1]);
        $p['tiering']['min_bytes']=self::number($input,'tier_min_kb',64,1048576)*1024;
        $p['quota']=['video_count'=>self::number($input,'video_count',1,200),'video_bytes'=>self::number($input,'video_mb',20,2048)*1048576];
        self::validate($p);return $p;
    }
    public function preview(array $input,int $admin): array
    {
        if(strlen($this->key)<32)throw new RuntimeException('应用签名配置不可用');
        $current=$this->current();$policy=self::fromForm($input);
        if(!isset($input['revision'])||!is_string($input['revision'])||$input['revision']!==$current['revision'])throw new RuntimeException('设置已变化，请刷新后重新预览');
        $apply=$input['apply_existing']??'0';if(!is_string($apply)||!in_array($apply,['0','1'],true))throw new RuntimeException('应用范围不正确');
        $through=$apply==='1'?(string)($this->db->fetchValue('SELECT COALESCE(MAX(id),0) FROM chat_messages')??'0'):'0';
        $json=json_encode($policy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $payload=['admin'=>$admin,'revision'=>$current['revision'],'hash'=>hash('sha256',$json),'through'=>$through,'apply'=>$apply,'expires'=>time()+600];
        $encoded=rtrim(strtr(base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $token=$encoded.'.'.hash_hmac('sha256',$encoded,$this->key);
        return ['policy'=>$policy,'revision'=>$current['revision'],'through'=>$through,'apply'=>$apply,'preview_token'=>$token];
    }
    public function save(array $input,int $admin,string $role): void
    {
        if(strlen($this->key)<32)throw new RuntimeException('应用签名配置不可用');
        $token=$input['preview_token']??null;
        if(!is_string($token)||strlen($token)>2048||substr_count($token,'.')!==1)throw new RuntimeException('请先预览本次设置');
        [$body,$signature]=explode('.',$token);
        if(!hash_equals(hash_hmac('sha256',$body,$this->key),$signature))throw new RuntimeException('设置预览已失效');
        $raw=base64_decode(strtr($body,'-_','+/'),true);
        $proof=is_string($raw)?json_decode($raw,true,16,JSON_THROW_ON_ERROR):null;
        $policy=self::fromForm($input);$json=json_encode($policy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $reason=$input['reason']??'';$revision=$input['revision']??null;$apply=$input['apply_existing']??'0';
        if(is_string($reason)&&trim($reason)===''&&$apply==='0')$reason='更新新消息的保存与上传设置';
        if(!is_array($proof)||($proof['admin']??0)!==$admin||!is_int($proof['expires']??null)||$proof['expires']<=time()||$proof['expires']>time()+600||($proof['revision']??null)!==$revision||($proof['hash']??null)!==hash('sha256',$json)||($proof['apply']??null)!==$apply||!is_string($proof['through']??null)||preg_match('/^(0|[1-9][0-9]{0,19})$/D',$proof['through'])!==1)throw new RuntimeException('设置预览已失效，请重新预览');
        if(!is_string($reason)||mb_strlen(trim($reason))<2||mb_strlen($reason)>200||str_contains($reason,chr(0)))throw new RuntimeException('应用历史内容需填写2至200字说明，记录清理依据');
        $this->db->transaction(function()use($admin,$role,$proof,$revision,$json,$reason):void{
            $currentAdmin=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if(!$currentAdmin||(string)$currentAdmin['role']!==$role)throw new RuntimeException('后台权限已变化');
            $row=$this->db->fetch('SELECT revision,apply_through_id FROM chat_storage_policy WHERE id=1 FOR UPDATE');
            if(!$row||(string)$row['revision']!==$revision||(int)$revision>=PHP_INT_MAX-1)throw new RuntimeException('设置已变化，请刷新后重新预览');
            $next=(int)$revision+1;
            $this->db->execute('UPDATE chat_storage_policy SET revision=:next,policy_json=:policy,apply_through_id=:through,updated_by=:admin,updated_at=UTC_TIMESTAMP(6) WHERE id=1',['next'=>$next,'policy'=>$json,'through'=>$proof['through'],'admin'=>$admin]);
            $this->db->execute('INSERT INTO chat_storage_policy_audit(revision,admin_id,policy_json,apply_through_id,reason,created_at) VALUES(:revision,:admin,:policy,:through,:reason,UTC_TIMESTAMP(6))',['revision'=>$next,'admin'=>$admin,'policy'=>$json,'through'=>$proof['through'],'reason'=>trim($reason)]);
        });
    }
    public function saveGroupLimit(array $input,int $admin,string $role):void
    {
        $limit=self::number($input,'member_limit',3,50);
        $revision=$input['group_revision']??null;$reason=$input['group_reason']??'';
        if(is_string($reason)&&trim($reason)==='')$reason='调整协作群人数上限';
        if(!is_string($revision)||preg_match('/^[1-9][0-9]{0,18}$/D',$revision)!==1||!is_string($reason)||mb_strlen(trim($reason))<2||mb_strlen($reason)>200)throw new RuntimeException('版本或备注不正确，请刷新后重试');
        $this->db->transaction(function()use($limit,$revision,$reason,$admin,$role):void{
            $current=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if(!$current||$current['role']!==$role)throw new RuntimeException('后台权限已变化');
            $row=$this->db->fetch('SELECT CAST(revision AS CHAR) revision FROM chat_collaboration_settings WHERE id=1 FOR UPDATE');
            if(!$row||$row['revision']!==$revision)throw new RuntimeException('群人数设置已变化，请刷新');
            $this->db->execute('UPDATE chat_collaboration_settings SET member_limit=:limit,revision=revision+1,updated_by=:admin,updated_at=UTC_TIMESTAMP(6) WHERE id=1',['limit'=>$limit,'admin'=>$admin]);
            $this->db->execute("INSERT INTO chat_collaboration_events(order_id,actor_id,event_type,display_role_version,reason,created_at) VALUES(NULL,:admin,'admin.limit_changed',:limit,:reason,UTC_TIMESTAMP(6))",['admin'=>$admin,'limit'=>$limit,'reason'=>trim($reason)]);
        });
    }

}

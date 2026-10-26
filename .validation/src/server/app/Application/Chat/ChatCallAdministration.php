<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\CallResourcePolicy;

/** Audited administrator operations; no purchased entitlement or user wallet. */
final class ChatCallAdministration
{
    public function __construct(private Database $db) {}
    public function current():array
    {
        $resources=new ChatCallResources($this->db);$availability=$resources->availability();
        $pools=$this->db->fetchAll('SELECT * FROM chat_call_resource_pools ORDER BY id DESC LIMIT 100');$decisions=[];foreach($pools as $row)$decisions[(string)$row['id']]=$resources->decision($row,$availability['settings']['config']);
        return ['settings'=>$availability['settings'],'decisions'=>$decisions,
            'alerts'=>$this->db->fetchAll('SELECT a.*,p.provider,p.cycle_key FROM chat_call_alerts a JOIN chat_call_resource_pools p ON p.id=a.pool_id ORDER BY a.id DESC LIMIT 100'),
            'trend'=>$this->db->fetchAll('SELECT p.provider,DATE(l.created_at) day,CAST(SUM(l.consumed_units) AS CHAR) estimated_units FROM chat_call_resource_ledger l JOIN chat_call_resource_pools p ON p.id=l.pool_id WHERE l.created_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY GROUP BY p.provider,DATE(l.created_at) ORDER BY day DESC,p.provider'),
            'recent'=>$this->db->fetchAll('SELECT CAST(id AS CHAR) id,CAST(conversation_id AS CHAR) conversation_id,state,model_json,created_at FROM chat_call_sessions ORDER BY id DESC LIMIT 50'),
            'pools'=>$pools,
            'audit'=>$this->db->fetchAll('SELECT admin_id,action,reason,created_at FROM chat_call_admin_audit ORDER BY id DESC LIMIT 20'),
            'active'=>$this->db->fetchAll("SELECT CAST(id AS CHAR) id,CAST(conversation_id AS CHAR) conversation_id,state,attempt_epoch,created_at FROM chat_call_sessions WHERE state IN ('ringing','preparing','connecting','active','stopping') ORDER BY id LIMIT 100")];
    }
    public function change(array $input,int $admin,string $role):void
    {
        $reason=$input['reason']??null;$action=$input['_action']??'';
        if(!is_string($reason)||mb_strlen(trim($reason))<2||mb_strlen($reason)>200)throw new RuntimeException('请填写2至200字操作理由');
        $this->db->transaction(function()use($input,$admin,$role,$reason,$action):void{
            $actor=$this->db->fetch("SELECT role FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$admin]);
            if(!$actor||$actor['role']!==$role||$role!=='super_admin')throw new RuntimeException('当前账号无权修改通话资源');
            $settings=(new ChatCallResources($this->db))->settings(true);
            if(!is_string($input['revision']??null)||$settings['revision']!==$input['revision'])throw new RuntimeException('设置已变化，请刷新重试');
            if($action==='ack_alert'){
                ChatCallResources::id($input['alert_id']??null);
                $this->db->execute('UPDATE chat_call_alerts SET acknowledged_at=UTC_TIMESTAMP(6),admin_id=:admin WHERE id=:id AND acknowledged_at IS NULL',['admin'=>$admin,'id'=>$input['alert_id']]);$details=['alert_id'=>$input['alert_id']];
            }elseif($action==='settings'){
                $config=$settings['config'];
                foreach(['enabled','audio_enabled','video_enabled','allow_fallback'] as $k)$config[$k]=self::flag($input,$k);
                foreach(['audio','video'] as $media){
                    $first=$input[$media.'_first']??'';
                    if(!in_array($first,['wechat','shengwang'],true))throw new RuntimeException('请选择通道优先级');
                    $config[$media.'_priority']=[$first,$first==='wechat'?'shengwang':'wechat'];
                }
                foreach(['window_seconds'=>[30,300],'stop_margin_seconds'=>[15,120],'heartbeat_seconds'=>[5,30],'max_active_calls'=>[1,1000]] as $k=>$range)$config[$k]=self::number($input,$k,...$range);
                if($config['heartbeat_seconds']*2>=$config['window_seconds'])throw new RuntimeException('续约窗口须大于两次心跳间隔');
                $this->db->execute('UPDATE chat_call_settings SET revision=revision+1,config_json=:json,updated_at=UTC_TIMESTAMP(6) WHERE id=1',['json'=>json_encode($config,JSON_THROW_ON_ERROR)]);
                $details=$config;
             }elseif($action==='confirmed_stop'){
                ChatCallResources::id($input['call_id']??null);ChatCallResources::id($input['attempt_epoch']??null);
                $proof=$input['evidence_hash']??'';if(!is_string($proof)||preg_match('/^[a-f0-9]{64}$/D',$proof)!==1)throw new RuntimeException('请先取得真实供应商退房证明并填写摘要');
                $details=['call_id'=>$input['call_id'],'attempt_epoch'=>$input['attempt_epoch'],'evidence_hash'=>$proof];
            }elseif($action==='pool'){$details=$this->createPool($input);}
            elseif($action==='budget'){
                ChatCallResources::id($input['pool_id']??null);
                $pool=$this->db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id FOR UPDATE',['id'=>$input['pool_id']]);
                if(!$pool||(string)$pool['revision']!==($input['pool_revision']??''))throw new RuntimeException('资源池已变化，请刷新');
                $facts=json_decode($pool['facts_json'],true,32,JSON_THROW_ON_ERROR);
                foreach(['budget_limit','safety_margin','external_uncertainty'] as $key)$facts[$key]=self::minutes($input,$key);
                (new CallResourcePolicy())->availability($facts,time());
                $this->db->execute('UPDATE chat_call_resource_pools SET facts_json=:facts,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE id=:id',['facts'=>json_encode($facts,JSON_THROW_ON_ERROR),'id'=>$pool['id']]);
                $details=['pool_id'=>(string)$pool['id'],'budget_limit'=>$facts['budget_limit'],'safety_margin'=>$facts['safety_margin'],'external_uncertainty'=>$facts['external_uncertainty']];
            }
            elseif($action==='import'){$details=$this->import($input,$admin);}
            elseif(in_array($action,['disable_pool','reverify'],true)){
                ChatCallResources::id($input['pool_id']??null);
                $pool=$this->db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id FOR UPDATE',['id'=>$input['pool_id']]);
                if(!$pool)throw new RuntimeException('资源池不存在');
                $facts=json_decode($pool['facts_json'],true,32,JSON_THROW_ON_ERROR);$facts['enabled']=$action==='reverify'?self::flag($input,'pool_enabled'):false;
                if($action==='reverify'){
                    if((string)$pool['revision']!==($input['pool_revision']??''))throw new RuntimeException('资源池已变化，请刷新');
                    $proof=$input['evidence_hash']??'';if(!is_string($proof)||preg_match('/^[a-f0-9]{64}$/D',$proof)!==1)throw new RuntimeException('请填写当前权益与免费边界证明摘要');
                    $facts['verified']=self::flag($input,'verified');$facts['hard_stop_verified']=self::flag($input,'hard_stop_verified');$facts['verified_at']=time();
                    $profile=json_decode($pool['meter_json'],true,32,JSON_THROW_ON_ERROR);$profile['evidence_hash']=$proof;
                    $this->db->execute('UPDATE chat_call_resource_pools SET meter_json=:profile,revision=revision+1 WHERE id=:id',['profile'=>json_encode($profile,JSON_THROW_ON_ERROR),'id'=>$pool['id']]);
                }
                $this->db->execute('UPDATE chat_call_resource_pools SET facts_json=:json,updated_at=UTC_TIMESTAMP(6) WHERE id=:id',['json'=>json_encode($facts,JSON_THROW_ON_ERROR),'id'=>$pool['id']]);
                $details=['pool_id'=>(string)$pool['id']];
            }else throw new RuntimeException('操作不存在');
            $this->db->execute('INSERT INTO chat_call_admin_audit(admin_id,action,reason,details_json,created_at) VALUES(:admin,:action,:reason,:details,UTC_TIMESTAMP(6))',
                ['admin'=>$admin,'action'=>$action,'reason'=>trim($reason),'details'=>json_encode($details,JSON_THROW_ON_ERROR)]);
        });
        if($action==='confirmed_stop')\WorkerAdmin\Infrastructure\Messaging\ChatInternalAuth::configured($this->db)->confirmCallStopped($input['call_id'],$input['attempt_epoch'],$input['evidence_hash']);
    }
    private function createPool(array $input):array
    {
        $provider=$input['provider']??'';if(!in_array($provider,['wechat','shengwang'],true))throw new RuntimeException('通道不正确');
        foreach(['scope_key','cycle_key','app_id'] as $key)if(!is_string($input[$key]??null)||preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,127}$/D',$input[$key])!==1)throw new RuntimeException('请填写有效的供应商资源范围与周期标识');
        $proof=$input['evidence_hash']??'';if(!is_string($proof)||preg_match('/^[a-f0-9]{64}$/D',$proof)!==1)throw new RuntimeException('请填写官方权益及免费边界证明文件的SHA-256摘要');
        $now=time();$from=self::timestamp($input,'valid_from');$until=self::timestamp($input,'expires_at');if($until<=$from)throw new RuntimeException('到期时间必须晚于生效时间');
        $facts=['schema_version'=>1,'provider'=>$provider,'quota_scope_id'=>$input['scope_key'],'cycle_id'=>$input['cycle_key'],'config_revision'=>'1',
            'unit'=>$provider==='wechat'?'wechat_equivalent':'shengwang_standard','enabled'=>self::flag($input,'pool_enabled'),
            'verified'=>self::flag($input,'verified'),'hard_stop_verified'=>self::flag($input,'hard_stop_verified'),'healthy'=>true,
            'valid_from'=>$from,'expires_at'=>$until,'verified_at'=>$now,'usage_observed_at'=>$now,'max_age_seconds'=>self::number($input,'max_age_seconds',30,86400),
            'free_total'=>self::minutes($input,'free_total'),'budget_limit'=>self::minutes($input,'budget_limit'),'official_used'=>self::minutes($input,'official_used'),
            'local_uncovered'=>'0','reserved'=>'0','safety_margin'=>self::minutes($input,'safety_margin'),'external_uncertainty'=>self::minutes($input,'external_uncertainty')];
        (new CallResourcePolicy())->availability($facts,$now);
        $profile=['app_id'=>$input['app_id'],'evidence_hash'=>$proof,'audio'=>[],'video'=>[]];
        foreach(['audio','video'] as $media){
            $profile[$media]=['rate_numerator'=>self::number($input,$media.'_numerator',1,1000000),
                'rate_denominator'=>self::number($input,$media.'_denominator',1,1000000),'quantum_seconds'=>self::number($input,$media.'_quantum',1,3600)];
        }
        // Physical scope + cycle cannot be cloned under an alias AppID in this authority store.
        $bound=$this->db->fetch("SELECT scope_key,meter_json FROM chat_call_resource_pools WHERE provider=:provider AND cycle_key=:cycle AND JSON_UNQUOTE(JSON_EXTRACT(meter_json,'$.app_id'))=:app FOR UPDATE",['provider'=>$provider,'cycle'=>$input['cycle_key'],'app'=>$input['app_id']]);
        $overlap=$this->db->fetch("SELECT id FROM chat_call_resource_pools WHERE provider=:provider AND (scope_key=:scope OR JSON_UNQUOTE(JSON_EXTRACT(meter_json,'$.app_id'))=:app) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(facts_json,'$.valid_from')) AS UNSIGNED)<:until AND CAST(JSON_UNQUOTE(JSON_EXTRACT(facts_json,'$.expires_at')) AS UNSIGNED)>:from_time LIMIT 1 FOR UPDATE",['provider'=>$provider,'scope'=>$input['scope_key'],'app'=>$input['app_id'],'until'=>$until,'from_time'=>$from]);
        if($overlap)throw new RuntimeException('同一实际资源范围的周期不允许重叠，不得复制免费权益');
        if($bound)throw new RuntimeException('该应用本周期已有资源池，不得重复赠送或改名复制额度');
        $id=$this->db->insert('INSERT INTO chat_call_resource_pools(provider,scope_key,cycle_key,revision,facts_json,meter_json,official_used,observation_at,updated_at) VALUES(:provider,:scope,:cycle,1,:facts,:meter,:used,:observed,UTC_TIMESTAMP(6))',
            ['provider'=>$provider,'scope'=>$input['scope_key'],'cycle'=>$input['cycle_key'],'facts'=>json_encode($facts,JSON_THROW_ON_ERROR),'meter'=>json_encode($profile,JSON_THROW_ON_ERROR),'used'=>$facts['official_used'],'observed'=>$now]);
        return ['pool_id'=>(string)$id,'evidence_hash'=>$proof,'facts'=>$facts,'profile'=>$profile];
    }
    private function import(array $input,int $admin):array
    {
        ChatCallResources::id($input['pool_id']??null);
        $row=$this->db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id FOR UPDATE',['id'=>$input['pool_id']]);
        if(!$row)throw new RuntimeException('资源池不存在');
        if((string)$row['revision']!==($input['pool_revision']??null))throw new RuntimeException('资源池已变化，请刷新');
        $used=self::minutes($input,'official_used');$observed=self::timestamp($input,'observed_at');$through=self::timestamp($input,'covered_through');
        if($observed<(int)$row['observation_at']||$observed>time()||$through<(int)$row['covered_through']||$through>$observed)throw new RuntimeException('官方统计水位不正确或已经过时');
        $proof=$input['evidence_hash']??'';if(!is_string($proof)||preg_match('/^[a-f0-9]{64}$/D',$proof)!==1)throw new RuntimeException('请填写官方完整范围报表证明摘要');
        if((int)$used<(int)$row['official_used'])throw new RuntimeException('官方累计用量不可回退；需另行核对并追加审计纠正');
        // Only a confirmed, complete supplier watermark may retire covered L.
        $covered=(int)$this->db->fetchValue('SELECT COALESCE(SUM(consumed_units),0) FROM chat_call_resource_ledger WHERE pool_id=:pool AND covered=0 AND through_at<=:through',['pool'=>$row['id'],'through'=>$through]);
        if($covered>(int)$row['local_uncovered'])throw new RuntimeException('本地对账不一致，资源池保持关闭');
        $this->db->execute('UPDATE chat_call_resource_ledger SET covered=1 WHERE pool_id=:pool AND covered=0 AND through_at<=:through',['pool'=>$row['id'],'through'=>$through]);
        $this->db->execute('UPDATE chat_call_resource_pools SET official_used=:used,observation_at=:observed,covered_through=:through,local_uncovered=local_uncovered-:covered,revision=revision+1,updated_at=UTC_TIMESTAMP(6) WHERE id=:id',
            ['used'=>$used,'observed'=>$observed,'through'=>$through,'covered'=>$covered,'id'=>$row['id']]);
        $this->db->execute('INSERT INTO chat_call_usage_snapshots(pool_id,official_used,observed_at,covered_through,evidence_hash,admin_id,created_at) VALUES(:pool,:used,:observed,:through,:proof,:admin,UTC_TIMESTAMP(6))',
            ['pool'=>$row['id'],'used'=>$used,'observed'=>$observed,'through'=>$through,'proof'=>$proof,'admin'=>$admin]);
        return ['pool_id'=>(string)$row['id'],'official_used'=>$used,'observed_at'=>$observed,'covered_through'=>$through,'evidence_hash'=>$proof];
    }
    private static function flag(array $v,string $key):bool
    {
        $value=$v[$key]??'0';if(!in_array($value,['0','1'],true))throw new RuntimeException('开关格式不正确');return $value==='1';
    }
    private static function number(array $v,string $key,int $min,int $max):int
    {
        $s=$v[$key]??null;if(!is_string($s)||preg_match('/^(0|[1-9][0-9]{0,18})$/D',$s)!==1||strlen($s)===19&&strcmp($s,(string)PHP_INT_MAX)>0)throw new RuntimeException('请填写有效整数：'.$key);
        $n=(int)$s;if($n<$min||$n>$max)throw new RuntimeException('数值超出范围：'.$key);return $n;
    }
    private static function timestamp(array $v,string $key):int
    {
        $s=$v[$key]??null;if(!is_string($s))throw new RuntimeException('请选择日期时间');
        if(strlen($s)===16)$s.=':00';
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s',$s,new \DateTimeZone(date_default_timezone_get()));
        if(!$dt||$dt->format('Y-m-d\\TH:i:s')!==$s)throw new RuntimeException('日期时间不正确');
        return $dt->getTimestamp();
    }
    private static function minutes(array $v,string $key):string
    {
        $s=$v[$key]??null;if(!is_string($s)||preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D',$s,$m)!==1)throw new RuntimeException('分钟数最多两位小数：'.$key);
        return (string)((int)$m[1]*6000+(int)str_pad($m[2]??'',2,'0')*60);
    }
}

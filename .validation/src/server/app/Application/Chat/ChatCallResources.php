<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\CallResourcePolicy;

/** PHP is the only writer of the shared resource pools, calls and ledger. */
final class ChatCallResources
{
    public function __construct(private Database $db) {}
    public function settings(bool $lock=false): array
    {
        $row=$this->db->fetch('SELECT CAST(revision AS CHAR) revision,config_json FROM chat_call_settings WHERE id=1'.($lock?' FOR UPDATE':''));
        if(!$row)throw new RuntimeException('CALL_SCHEMA_REQUIRED',503);
        return ['revision'=>$row['revision'],'config'=>json_decode($row['config_json'],true,32,JSON_THROW_ON_ERROR)];
    }
    public function availability(): array
    {
        $settings=$this->settings();$pools=[];
        $selected=[];
        foreach($this->db->fetchAll('SELECT * FROM chat_call_resource_pools ORDER BY provider,id DESC LIMIT 100') as $row){
            $facts=$this->facts($row);$profile=json_decode($row['meter_json'],true,32,JSON_THROW_ON_ERROR);
            $decision=(new CallResourcePolicy())->availability($facts,time());
            $stale=(int)$this->db->fetchValue("SELECT COUNT(*) FROM chat_call_resource_calls WHERE pool_id=:pool AND state='active' AND authorized_until<:deadline",['pool'=>$row['id'],'deadline'=>time()-$settings['config']['stop_margin_seconds']]);
            if($stale>0){$decision['status']='unavailable';$decision['available_units']='0';}
            $this->alerts($row,$facts,$decision);
            $candidate=['pool_id'=>(string)$row['id'],'decision'=>$decision,'audio'=>$profile['audio'],'video'=>$profile['video']];
            $provider=$row['provider'];
            if(!isset($selected[$provider])||($selected[$provider]['decision']['status']!=='available'&&$decision['status']==='available'))$selected[$provider]=$candidate;
        }
        $pools=array_values($selected);
        return ['settings'=>$settings,'pools'=>$pools];
    }
    private function alerts(array $row,array $facts,array $decision):void
    {
        if(!$facts['enabled']||!$facts['verified']||!$facts['hard_stop_verified']||time()<$facts['valid_from']||time()>=$facts['expires_at'])return;
        $limit=(int)$facts['budget_limit'];$available=(int)$decision['available_units'];
        if(!in_array($decision['status'],['available','busy','exhausted','safety_insufficient'],true))return;
        foreach([20,10,0] as $threshold){
            // Compare integers without multiplying a potentially large budget.
            $boundary=intdiv($limit,100)*$threshold+intdiv(($limit%100)*$threshold,100);
            if($available>$boundary)continue;
            $this->db->execute('INSERT IGNORE INTO chat_call_alerts(pool_id,threshold_percent,available_units,created_at) VALUES(:pool,:threshold,:available,UTC_TIMESTAMP(6))',['pool'=>$row['id'],'threshold'=>$threshold,'available'=>$available]);
        }
    }
    private function facts(array $row): array
    {
        $f=json_decode($row['facts_json'],true,32,JSON_THROW_ON_ERROR);
        $f['config_revision']=(string)$row['revision'];$f['official_used']=(string)$row['official_used'];$f['local_uncovered']=(string)$row['local_uncovered'];
        $f['reserved']=(string)$row['reserved_units'];$f['usage_observed_at']=(int)$row['observation_at'];
        return $f;
    }
    public function receive(array $q): array
    {
        $action=$q['action']??'';
        if($action==='availability'&&count($q)===1)return $this->availability();
        $required=['action','operation_id','call_id','attempt_epoch','pool_id','media','caller_id','callee_id'];
        if(count($q)!==count($required))throw new RuntimeException('CALL_REQUEST_INVALID',400);
        foreach($required as $k)if(!is_string($q[$k]??null))throw new RuntimeException('CALL_REQUEST_INVALID',400);
        foreach(['call_id','attempt_epoch','pool_id','caller_id','callee_id'] as $k)self::id($q[$k]);
        if(preg_match('/^[a-f0-9]{64}$/D',$q['operation_id'])!==1||!in_array($q['media'],['audio','video'],true)
            ||!in_array($action,['reserve','renew','release','release_unsubmitted','status','identities'],true)||$q['caller_id']===$q['callee_id'])throw new RuntimeException('CALL_REQUEST_INVALID',400);
        if($action==='identities')return $this->identities($q);
        if($action==='status'){
            $row=$this->db->fetch('SELECT * FROM chat_call_resource_calls WHERE call_id=:call AND attempt_epoch=:epoch',['call'=>$q['call_id'],'epoch'=>$q['attempt_epoch']]);
            if(!$row)throw new RuntimeException('CALL_RESOURCE_UNKNOWN',409);
            $this->bound($row,$q);return $this->permit($row);
        }
        ksort($q);$fingerprint=hash('sha256',json_encode($q,JSON_THROW_ON_ERROR));
        return $this->db->transaction(function()use($q,$action,$fingerprint):array{
            $settings=$this->settings(true)['config'];
            $pool=$this->db->fetch('SELECT * FROM chat_call_resource_pools WHERE id=:id FOR UPDATE',['id'=>$q['pool_id']]);
            if(!$pool)throw new RuntimeException('CALL_RESOURCE_UNAVAILABLE',503);
            $old=$this->db->fetch('SELECT fingerprint,response_json FROM chat_call_resource_operations WHERE operation_id=:operation',['operation'=>$q['operation_id']]);
            if($old){
                if(!hash_equals($old['fingerprint'],$fingerprint))throw new RuntimeException('CALL_OPERATION_CONFLICT',409);
                return json_decode($old['response_json'],true,32,JSON_THROW_ON_ERROR);
            }
            $row=$this->db->fetch('SELECT * FROM chat_call_resource_calls WHERE call_id=:call AND attempt_epoch=:epoch FOR UPDATE',['call'=>$q['call_id'],'epoch'=>$q['attempt_epoch']]);
            if($row)$this->bound($row,$q);
            $now=time();$releasing=in_array($action,['release','release_unsubmitted'],true);
            if(!$releasing){
                if(!$settings['enabled']||!$settings[$q['media'].'_enabled'])throw new RuntimeException('CALL_DISABLED',409);
                if($row&&$row['state']!=='active')throw new RuntimeException('CALL_RESOURCE_CLOSED',409);
                if($action==='renew'&&!$row)throw new RuntimeException('CALL_RESOURCE_UNKNOWN',409);
                $stale=(int)$this->db->fetchValue("SELECT COUNT(*) FROM chat_call_resource_calls WHERE pool_id=:pool AND state='active' AND authorized_until<:deadline",['pool'=>$pool['id'],'deadline'=>$now-$settings['stop_margin_seconds']]);
                if($stale)throw new RuntimeException('CALL_RESOURCE_UNAVAILABLE',503);
            }elseif(!$row&&$action==='release')throw new RuntimeException('CALL_RESOURCE_UNKNOWN',409);
            if($row&&$row['state']==='closed'){
                $result=$this->permit($row);
                $this->db->execute('INSERT INTO chat_call_resource_operations(operation_id,fingerprint,response_json,created_at) VALUES(:operation,:fingerprint,:response,UTC_TIMESTAMP(6))',['operation'=>$q['operation_id'],'fingerprint'=>$fingerprint,'response'=>json_encode($result,JSON_THROW_ON_ERROR)]);
                return $result;
            }
            $profile=$row?json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR)['profile']:json_decode($pool['meter_json'],true,32,JSON_THROW_ON_ERROR);
            $facts=$this->facts($pool);$meter=$profile[$q['media']];
            $start=$row?(int)$row['started_at']:$now;
            $oldReserved=$row?(int)$row['reserved_units']:0;$oldConsumed=$row?(int)$row['consumed_units']:0;
            $elapsed=$action==='release_unsubmitted'?0:max(0,$now-$start);
            $policy=new CallResourcePolicy();
            $cost=static fn(int $seconds):int=>$policy->incrementalCost($meter,array_fill(0,2,['previous_seconds'=>0,'current_seconds'=>$seconds]));
            $consumed=$row?$cost($elapsed):0;
            if($consumed<$oldConsumed)$consumed=$oldConsumed;
            $delta=$consumed-$oldConsumed;
            $until=$releasing?$now:min($facts['expires_at'],$now+$settings['window_seconds']);
            if(!$releasing&&$until<$now+5)throw new RuntimeException('CALL_RESOURCE_CYCLE_END',409);
            $upper=$releasing?$elapsed:max($elapsed,$until-$start+$settings['stop_margin_seconds']);
            $reserved=$releasing?0:max(0,$cost($upper)-$consumed);
            // Temporarily return this call's old R to available capacity. O/L
            // remain conservative and cannot be reduced by retry/client timers.
            $facts['reserved']=(string)max(0,(int)$pool['reserved_units']-$oldReserved);
            $admission=$policy->availability($facts,$now);
            if(!$releasing&&($admission['status']!=='available'||$reserved>max(0,(int)$admission['available_units']-$delta)))throw new RuntimeException('CALL_RESOURCE_'.$admission['status'],409);
            $covered=$now<=(int)$pool['covered_through'];$localDelta=$covered?0:$delta;
            if($localDelta>PHP_INT_MAX-(int)$pool['local_uncovered'])throw new RuntimeException('CALL_RESOURCE_OVERFLOW',503);
            $newReserved=max(0,(int)$pool['reserved_units']-$oldReserved);
            if($reserved>PHP_INT_MAX-$newReserved)throw new RuntimeException('CALL_RESOURCE_OVERFLOW',503);
            $this->db->execute('UPDATE chat_call_resource_pools SET local_uncovered=:local,reserved_units=:reserved,updated_at=UTC_TIMESTAMP(6) WHERE id=:id',
                ['local'=>(int)$pool['local_uncovered']+$localDelta,'reserved'=>$newReserved+$reserved,'id'=>$pool['id']]);
            if($delta>0)$this->db->execute('INSERT INTO chat_call_resource_ledger(pool_id,call_id,attempt_epoch,operation_id,consumed_units,through_at,covered,created_at) VALUES(:pool,:call,:epoch,:operation,:units,:through,:covered,UTC_TIMESTAMP(6))',
                ['pool'=>$pool['id'],'call'=>$q['call_id'],'epoch'=>$q['attempt_epoch'],'operation'=>$q['operation_id'],'units'=>$delta,'through'=>$now,'covered'=>$covered?1:0]);
            $snapshot=$row?$row['snapshot_json']:json_encode(['provider'=>$pool['provider'],'scope_key'=>$pool['scope_key'],'cycle_key'=>$pool['cycle_key'],'profile'=>$profile,
                'media'=>$q['media'],'caller_id'=>$q['caller_id'],'callee_id'=>$q['callee_id'],'config_revision'=>(string)$pool['revision']],JSON_THROW_ON_ERROR);
            $this->db->execute("INSERT INTO chat_call_resource_calls(call_id,attempt_epoch,pool_id,state,snapshot_json,started_at,authorized_until,upper_seconds,consumed_units,reserved_units,updated_at)
                VALUES(:call,:epoch,:pool,:state,:snapshot,:start,:until,:upper,:consumed,:reserved,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE state=VALUES(state),authorized_until=VALUES(authorized_until),upper_seconds=VALUES(upper_seconds),consumed_units=VALUES(consumed_units),reserved_units=VALUES(reserved_units),updated_at=VALUES(updated_at)",
                ['call'=>$q['call_id'],'epoch'=>$q['attempt_epoch'],'pool'=>$pool['id'],'state'=>$releasing?'closed':'active','snapshot'=>$snapshot,'start'=>$start,'until'=>$until,'upper'=>$upper,'consumed'=>$consumed,'reserved'=>$reserved]);
            $result=$this->permit(['call_id'=>$q['call_id'],'attempt_epoch'=>$q['attempt_epoch'],'pool_id'=>(string)$pool['id'],'state'=>$releasing?'closed':'active','snapshot_json'=>$snapshot,'authorized_until'=>$until]);
            $this->db->execute('INSERT INTO chat_call_resource_operations(operation_id,fingerprint,response_json,created_at) VALUES(:operation,:fingerprint,:response,UTC_TIMESTAMP(6))',['operation'=>$q['operation_id'],'fingerprint'=>$fingerprint,'response'=>json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
    private function bound(array $row,array $q):void
    {
        $s=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        foreach(['media','caller_id','callee_id'] as $key)if($s[$key]!==$q[$key])throw new RuntimeException('CALL_OPERATION_CONFLICT',409);
        if((string)$row['pool_id']!==$q['pool_id'])throw new RuntimeException('CALL_OPERATION_CONFLICT',409);
    }
    private function permit(array $row):array
    {
        $s=json_decode($row['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
        return ['call_id'=>(string)$row['call_id'],'attempt_epoch'=>(string)$row['attempt_epoch'],'pool_id'=>(string)$row['pool_id'],'state'=>$row['state'],
            'provider'=>$s['provider'],'app_id'=>$s['profile']['app_id'],'authorized_until'=>(int)$row['authorized_until'],'config_revision'=>$s['config_revision']];
    }
    private function identities(array $q):array
    {
        $row=$this->db->fetch('SELECT * FROM chat_call_resource_calls WHERE call_id=:call AND attempt_epoch=:epoch',['call'=>$q['call_id'],'epoch'=>$q['attempt_epoch']]);
        if(!$row||$row['state']!=='active'||(int)$row['authorized_until']<=time())throw new RuntimeException('CALL_RESOURCE_CLOSED',409);
        $this->bound($row,$q);$permit=$this->permit($row);$people=[];
        foreach(['caller_id','callee_id'] as $key){
            $identity=$this->db->fetch("SELECT w.openid,u.public_id FROM wechat_identities w JOIN users u ON u.id=w.user_id AND u.status='active' WHERE w.user_id=:actor AND w.app_id=:app AND w.status='active' LIMIT 1",['actor'=>$q[$key],'app'=>$permit['app_id']]);
            if(!$identity)throw new RuntimeException('CALL_IDENTITY_UNAVAILABLE',503);
            $people[$key]=['openid'=>$identity['openid'],'nickname'=>'用户'.substr((string)$identity['public_id'],-6)];
        }
        return $people;
    }
    public static function id($v):void
    {
        if(!is_string($v)||preg_match('/^[1-9][0-9]{0,18}$/D',$v)!==1||strlen($v)===19&&strcmp($v,(string)PHP_INT_MAX)>0)throw new RuntimeException('CALL_REQUEST_INVALID',400);
    }
}

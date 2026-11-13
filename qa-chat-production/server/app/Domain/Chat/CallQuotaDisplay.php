<?php
declare(strict_types=1);
namespace WorkerAdmin\Domain\Chat;

final class CallQuotaDisplay
{
    public static function summary(array $row):array
    {
        $facts=json_decode((string)$row['facts_json'],true,32,JSON_THROW_ON_ERROR);
        $profile=json_decode((string)$row['meter_json'],true,32,JSON_THROW_ON_ERROR);
        $known=($profile['usage_known']??true)===true;
        $total=max(0,(int)$facts['free_total']);$budget=max(0,(int)$facts['budget_limit']);
        $remaining=min($total,$budget);
        foreach([(int)$row['official_used'],(int)$row['local_uncovered'],(int)$row['reserved_units'],(int)$facts['safety_margin'],(int)$facts['external_uncertainty']] as $amount)
            $remaining=$amount>=$remaining?0:$remaining-max(0,$amount);
        return ['total'=>$total,'budget'=>$budget,'official_used'=>$known?(int)$row['official_used']:null,
            'usage_known'=>$known,'platform_used'=>array_key_exists('platform_used',$row)?(int)$row['platform_used']:null,
            'local_uncovered'=>(int)$row['local_uncovered'],'reserved'=>(int)$row['reserved_units'],
            'remaining'=>$known?$remaining:null,'verified'=>($facts['verified']??false)===true];
    }
    public static function presets(array $rows,string $appId,string $wechatAppId=''):array
    {
        $base=['app_id'=>'','scope_type'=>'','scope_account'=>'','free_total'=>'','budget_limit'=>'','official_used'=>'',
            'safety_margin'=>'0','external_uncertainty'=>'0','audio_numerator'=>'','audio_denominator'=>'',
            'video_numerator'=>'','video_denominator'=>'','audio_quantum'=>'','video_quantum'=>'',
            'pool_enabled'=>'0','verified'=>'0','hard_stop_verified'=>'0','max_age_seconds'=>'86400'];
        $result=['shengwang'=>array_replace($base,['app_id'=>$appId,'free_total'=>'10000','budget_limit'=>'10000',
            'audio_numerator'=>'143','audio_denominator'=>'100','video_numerator'=>'429','video_denominator'=>'100','audio_quantum'=>'1','video_quantum'=>'1']),
            'wechat'=>array_replace($base,['app_id'=>$wechatAppId])];
        $seen=[];
        foreach($rows as $row){
            $provider=(string)$row['provider'];if(!isset($result[$provider])||isset($seen[$provider]))continue;
            $facts=json_decode((string)$row['facts_json'],true,32,JSON_THROW_ON_ERROR);
            $profile=json_decode((string)$row['meter_json'],true,32,JSON_THROW_ON_ERROR);
            if((int)$facts['valid_from']>time()||(int)$facts['expires_at']<=time())continue;
            if($result[$provider]['app_id']!==''&&$result[$provider]['app_id']!==($profile['app_id']??''))continue;
            $seen[$provider]=true;$scope=CallQuotaScope::describe($provider,(string)$row['scope_key']);$summary=self::summary($row);
            $next=['app_id'=>(string)$profile['app_id'],'scope_type'=>$scope['type'],'scope_account'=>$scope['account'],
                'cycle_key'=>(string)$row['cycle_key'],'valid_from'=>date('Y-m-d\TH:i:s',(int)$facts['valid_from']),
                'expires_at'=>date('Y-m-d\TH:i:s',(int)$facts['expires_at']),'pool_id'=>(string)$row['id'],
                'pool_enabled'=>!empty($facts['enabled'])?'1':'0','verified'=>($profile['entitlement_confirmed']??$facts['verified']??false)?'1':'0',
                'hard_stop_verified'=>!empty($facts['hard_stop_verified'])?'1':'0','max_age_seconds'=>(string)$facts['max_age_seconds'],
                'local_estimate_minutes'=>self::input((int)$row['local_uncovered']),'reserved_minutes'=>self::input((int)$row['reserved_units'])];
            foreach(['free_total','budget_limit','safety_margin','external_uncertainty'] as $key)$next[$key]=self::input((int)$facts[$key]);
            $next['official_used']=$summary['usage_known']?self::input((int)$row['official_used']):'';
            foreach(['audio','video'] as $media)foreach(['numerator'=>'rate_numerator','denominator'=>'rate_denominator','quantum'=>'quantum_seconds'] as $input=>$key)
                $next[$media.'_'.$input]=(string)$profile[$media][$key];
            $result[$provider]=array_replace($result[$provider],$next);
        }
        return $result;
    }

    /** Explain actual admission blockers without modifying resource state. */
    public static function blockers(array $row,array $decision):array
    {
        $facts=json_decode((string)$row['facts_json'],true,32,JSON_THROW_ON_ERROR);
        $profile=json_decode((string)$row['meter_json'],true,32,JSON_THROW_ON_ERROR);
        $out=[];$known=($profile['usage_known']??true)===true;$now=time();$admitted=($decision['status']??'')==='available';
        if(empty($facts['enabled']))$out[]='本资源池尚未允许使用';
        if(!$known)$out[]='官方已用量尚未录入；权益确认已保留，核对后请填写实际用量（确认为零时填0）';
        elseif(empty($facts['verified']))$out[]='实际权益或完整共享范围尚未复核，请保存复核';
        if(empty($facts['hard_stop_verified'])&&!$admitted)$out[]='实际通话停止能力尚未确认；空频道接口自检不能替代真机通话验证';
        if($now<(int)$facts['valid_from'])$out[]='权益周期尚未生效';
        if($now>=(int)$facts['expires_at'])$out[]='权益周期已经到期';
        if($known&&!$admitted&&$now-(int)$row['observation_at']>=(int)$facts['max_age_seconds'])
            $out[]='官方用量数据距上次核对已超过'.(int)$facts['max_age_seconds'].'秒，请重新核对并更新官方用量';
        if(empty($facts['healthy']))$out[]='供应商资源健康状态待确认';
        if(!$out&&($decision['status']??'')!=='available'){
            $out[]=['busy'=>'额度已被活动或待确认通话预占','exhausted'=>'本周期可用额度已耗尽',
                'safety_insufficient'=>'扣除安全余量后额度不足'][$decision['status']??'']??'存在待确认通话或资源校验未通过';
        }
        return $out;
    }

    private static function input(int $units):string
    {return number_format($units/6000,2,'.','');}
}

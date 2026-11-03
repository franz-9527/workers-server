<?php
declare(strict_types=1);
namespace WorkerAdmin\Domain\Chat;

use RuntimeException;

/** Administrator quota identity; does not grant or verify provider entitlements. */
final class CallQuotaScope
{
    public static function fromInput(string $provider,array $input):string
    {
        if(!in_array($provider,['wechat','shengwang'],true))throw new RuntimeException('请选择通话供应商。');
        // Preserve existing clients/records; new forms always submit an explicit scope type.
        if(!array_key_exists('scope_type',$input)){
            $legacy=$input['scope_key']??null;
            if(!is_string($legacy)||preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,127}$/D',$legacy)!==1)
                throw new RuntimeException('请选择按账号共享或按应用独立计算额度。');
            return $legacy;
        }
        $type=$input['scope_type'];
        if(!is_string($type)||!in_array($type,['account','app'],true))
            throw new RuntimeException('请选择按账号共享或按应用独立计算额度。');
        $id=$type==='app'?($input['app_id']??null):($input['scope_account']??null);
        if(!is_string($id)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,95}$/D',$id)!==1)
            throw new RuntimeException($type==='app'?'请填写有效的供应商应用 AppID。':'请填写供应商账号 ID，仅用字母、数字、点、短横线或下划线。');
        return $provider.':'.$type.':'.$id;
    }

    /** Unknown historical identifiers are shown as-is and are never silently reclassified. */
    public static function describe(string $provider,string $key):array
    {
        $prefix=$provider.':';
        foreach(['account','app'] as $type){
            $head=$prefix.$type.':';
            if(str_starts_with($key,$head)){
                $id=substr($key,strlen($head));
                if(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,95}$/D',$id)===1)
                    return ['type'=>$type,'account'=>$type==='account'?$id:'',
                        'label'=>($type==='account'?'按账号共享 · ':'按应用独立 · ').$id];
            }
        }
        return ['type'=>'','account'=>'','label'=>'历史计费范围 · '.$key];
    }
}

<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

/** Shared by managed Go admission and the paid-SMS quote; no provider request or secret output. */
final class ChatSmsProviderReadiness
{
    public static function inspect(callable $get,bool $testing):array
    {
        $missing=[];
        if(!filter_var($get('sms.enabled',false),FILTER_VALIDATE_BOOLEAN))$missing[]='后台短信总开关未开启';
        if(strtolower((string)$get('sms.provider','aliyun'))!=='aliyun')$missing[]='短信服务商需选择阿里云';
        foreach(['sms.access_key_id'=>'阿里云 AccessKey ID','sms.access_key_secret'=>'阿里云 AccessKey Secret','sms.sign_name'=>'阿里云短信签名'] as $key=>$label){
            if(trim((string)$get($key,''))==='')$missing[]=$label.'未配置';
        }
        $codes=(array)$get('sms.template_codes',[]);$v=$codes['chat_unread_nudge']??'';
        $code=trim(is_array($v)?(string)(($v['template_code']??'')?:($v['template_id']??'')?:($v['code']??'')):(string)$v);
        if(preg_match('/^SMS_[A-Za-z0-9]+$/D',$code)!==1)$missing[]='聊天未读提醒阿里云短信模板编码未配置或格式不正确';
        if(!function_exists('curl_init'))$missing[]='PHP cURL 扩展未安装';
        if($testing){
            if(!filter_var($get('testing.sms_enabled',false),FILTER_VALIDATE_BOOLEAN))$missing[]='测试环境真实短信开关未开启';
            $allowed=$get('testing.allowed_sms_mobiles',[]);
            if(is_string($allowed))$allowed=preg_split('/[\\s,;]+/',$allowed)?:[];
            if(!is_array($allowed)||!array_filter($allowed,static fn($v):bool=>is_string($v)&&preg_match('/^1[3-9][0-9]{9}$/D',trim($v))===1))$missing[]='测试短信接收白名单尚无有效手机号';
        }
        return ['ready'=>!$missing,'missing'=>$missing];
    }
}

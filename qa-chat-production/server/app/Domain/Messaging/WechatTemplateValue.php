<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;



final class WechatTemplateValue
{

    public static function format(string $wechatKey,string $value):?string
    {
        $value=trim((string)(preg_replace('/[\x00-\x1F\x7F]+/u',' ',$value)??$value));
        if($value==='')return null;
        $prefix=strtolower((string)(preg_replace('/\d+$/','',$wechatKey)??''));
        if($prefix==='thing')return mb_substr($value,0,20);
        if($prefix==='name')return mb_substr($value,0,preg_match('/\p{Han}/u',$value)===1?10:20);
        if($prefix==='phrase')return preg_match('/^\p{Han}{1,5}$/u',$value)===1?$value:null;
        if($prefix==='character_string'){
            $ascii=(string)(preg_replace('/[^A-Za-z0-9_+\-\.\/]/','',$value)??'');
            return $ascii===''?null:substr($ascii,0,32);
        }
        if($prefix==='number'){
            $number=(string)(preg_replace('/[^0-9.\-]/','',$value)??'');
            return preg_match('/^\d+(?:\.\d+)?$/D',$number)===1?substr($number,0,32):null;
        }
        if($prefix==='letter'){
            $letters=(string)(preg_replace('/[^A-Za-z]/','',$value)??'');
            return $letters===''?null:substr($letters,0,32);
        }
        if($prefix==='symbol'){
            $symbols=(string)(preg_replace('/[A-Za-z0-9\x{4e00}-\x{9fff}\s]/u','',$value)??'');
            return $symbols===''?null:mb_substr($symbols,0,5);
        }
        if($prefix==='phone_number'){
            $phone=(string)(preg_replace('/[^0-9+\-]/','',$value)??'');
            return $phone===''?null:substr($phone,0,17);
        }
        if($prefix==='car_number')return mb_substr($value,0,8);
        if($prefix==='amount'){
            $number=(string)(preg_replace('/[^0-9.]/','',$value)??'');
            if($number===''||!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$number))return null;
            return '¥'.$number;
        }
        if($prefix==='time'){
            if(preg_match('/(?:^|\s)((?:[01]\d|2[0-3]):[0-5]\d)(?::[0-5]\d)?$/',$value,$match)===1)return (string)$match[1];
            return preg_match('/^((?:[01]\d|2[0-3]):[0-5]\d)(?::[0-5]\d)?$/',$value,$match)===1?(string)$match[1]:null;
        }
        if($prefix==='date'){
            if(preg_match('/(\d{4})-([01]\d)-([0-3]\d)/',$value,$match)===1&&checkdate((int)$match[2],(int)$match[3],(int)$match[1]))return $match[1].'-'.$match[2].'-'.$match[3];
            return null;
        }
        return mb_substr($value,0,20);
    }
    public static function formatBusiness(string $semantic,string $wechatKey,string $value): ?string
    {
        $value=trim($value);
        if($value==='')return null;
        $prefix=strtolower((string)(preg_replace('/\d+$/','',$wechatKey)??''));
        // phrase 不能静默截断。超过 5 个汉字或带数字/标点时，用业务语义短词承载摘要，
        // 完整事项应由同一模板的“温馨提示”字段承载。
        if($prefix==='phrase'){
            if(preg_match('/^\p{Han}{1,5}$/u',$value)===1)return $value;
            $phrases=[
                'status'=>'进度更新',
                'fee_note'=>'费用变更',
                'change_reason'=>'资金变动',
                'notice_text'=>'温馨提示',
                'order_type'=>'施工订单',
                'sender_name'=>'系统消息',
                'message_type'=>'新消息',
                'chat_info'=>'消息提醒',
                'service_category'=>'施工服务',
                'audit_note'=>'审核结果',
                'requirement_note'=>'订单需求',
                'appointment_note'=>'预约提醒',
                'worker_name'=>'服务师傅',
                'merchant_name'=>'服务商家',
                'platform_name'=>'壁选平台',
                'order_title'=>'订单提醒',
            ];
            return $phrases[$semantic]??null;
        }
        return self::format($wechatKey,$value);
    }

    public static function ruleHint(string $wechatKey): string
    {
        $prefix=strtolower((string)preg_replace('/\\d+$/','',$wechatKey));
        return [
            'thing'=>'文本最多20字','phrase'=>'仅汉字，最多5字','name'=>'含汉字最多10字，英文最多20字',
            'character_string'=>'字母、数字或符号，最多32位','number'=>'数字，可带小数，最多32位',
            'letter'=>'仅字母，最多32位','symbol'=>'仅符号，最多5位','phone_number'=>'电话最多17位',
            'car_number'=>'车牌最多8位','amount'=>'币种符号及金额','time'=>'24小时制时间','date'=>'有效年月日日期',
            'enum'=>'须匹配微信模板枚举值',
        ][$prefix]??'请核对微信模板字段类型';
    }

    /** 对直接测试和既有排队消息使用同一格式，失败时只输出字段名与规则，不输出收件人或字段值。 */
    public static function prepareData(array $data): array
    {
        $result=[];
        foreach($data as $key=>$item){
            $key=(string)$key;
            if(preg_match('/^[a-z_]+\\d+$/D',$key)!==1||!is_array($item)
                ||!isset($item['value'])||!is_scalar($item['value'])||is_bool($item['value'])){
                throw new \RuntimeException('微信订阅消息发送失败（47003）：模板字段结构不正确，请同步模板并检查字段映射。',47003);
            }
            $value=self::format($key,(string)$item['value']);
            if($value===null||$value==='')throw new \RuntimeException('微信订阅消息发送失败（47003）：字段 '.$key.' 格式不正确（'.self::ruleHint($key).'）。',47003);
            $result[$key]=['value'=>$value];
        }
        return $result;
    }

}

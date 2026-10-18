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
        if($prefix==='name')return mb_substr($value,0,32);
        if($prefix==='phrase')return mb_substr($value,0,16);
        if($prefix==='character_string'){
            $ascii=(string)(preg_replace('/[^A-Za-z0-9_+\-\.\/]/','',$value)??'');
            return $ascii===''?null:substr($ascii,0,64);
        }
        if($prefix==='number'){
            $number=(string)(preg_replace('/[^0-9.\-]/','',$value)??'');
            return $number===''?null:substr($number,0,32);
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
        if($prefix==='car_number')return mb_substr($value,0,12);
        if($prefix==='amount'){
            $number=(string)(preg_replace('/[^0-9.]/','',$value)??'');
            if($number===''||!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$number))return null;
            return '¥'.$number;
        }
        if($prefix==='time'){
            if(preg_match('/(?:^|\s)([0-2]\d:[0-5]\d)(?::[0-5]\d)?$/',$value,$match)===1)return (string)$match[1];
            return preg_match('/^([0-2]\d:[0-5]\d)(?::[0-5]\d)?$/',$value,$match)===1?(string)$match[1]:null;
        }
        if($prefix==='date'){
            if(preg_match('/(\d{4}-[01]\d-[0-3]\d)/',$value,$match)===1)return (string)$match[1];
            return null;
        }
        return mb_substr($value,0,20);
    }
}

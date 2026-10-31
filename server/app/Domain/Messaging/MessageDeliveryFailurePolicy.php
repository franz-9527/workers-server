<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Messaging;

/**
 * 外部消息失败后的重试/终止/降级纯策略。
 *
 * 不访问数据库、不发送消息，生产 dispatcher 与测试中心共同复用，
 * 避免测试规则与真实发送规则漂移。
 */
final class MessageDeliveryFailurePolicy
{
    /** @return array<string,mixed> */
    public static function decide(string $topic,int $attempt,string $error): array
    {
        $attempt=max(1,$attempt);
        $terminal=$attempt>=5;
        $permanentCode=0;

        if($topic==='wechat_subscribe'
            && preg_match('/微信订阅消息发送失败（(\d+)）/u',$error,$match)===1){
            $candidate=(int)($match[1]??0);
            if(in_array($candidate,[40003,40037,41030,43101,43107,45168,47003],true)){
                $permanentCode=$candidate;
                $terminal=true;
            }
        }

        $retryDelay=$terminal?null:min(60,(int)pow(2,max(0,$attempt-1)));
        $fallbackReason=null;
        if($terminal&&$topic==='wechat_subscribe'){
            $fallbackReason=$permanentCode>0?'wechat_permanent_'.$permanentCode:'wechat_retry_exhausted';
        }

        return[
            'attempt'=>$attempt,
            'terminal'=>$terminal,
            'retry_delay_minutes'=>$retryDelay,
            'permanent_code'=>$permanentCode,
            'invalidate_subscription'=>$topic==='wechat_subscribe'&&$permanentCode===43101,
            'fallback_reason'=>$fallbackReason,
        ];
    }
}

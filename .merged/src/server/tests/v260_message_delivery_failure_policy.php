<?php

declare(strict_types=1);

use WorkerAdmin\Domain\Messaging\MessageDeliveryFailurePolicy;

$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($path))require_once $path;
});

function policyAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$permanent=MessageDeliveryFailurePolicy::decide('wechat_subscribe',1,'微信订阅消息发送失败（43101）：permission unavailable');
policyAssert($permanent['terminal']===true,'43101 is terminal on first attempt');
policyAssert($permanent['permanent_code']===43101,'43101 permanent code retained');
policyAssert($permanent['invalidate_subscription']===true,'43101 invalidates subscription quota');
policyAssert($permanent['fallback_reason']==='wechat_permanent_43101','43101 immediately selects SMS fallback reason');
policyAssert($permanent['retry_delay_minutes']===null,'43101 has no retry delay');

$template=MessageDeliveryFailurePolicy::decide('wechat_subscribe',1,'微信订阅消息发送失败（40037）：template invalid');
policyAssert($template['terminal']===true&&$template['permanent_code']===40037,'template error is permanent');
policyAssert($template['invalidate_subscription']===false,'non-43101 permanent error does not invalidate quota');

$delays=[];
for($attempt=1;$attempt<=5;$attempt++){
    $decision=MessageDeliveryFailurePolicy::decide('wechat_subscribe',$attempt,'network timeout');
    $delays[]=$decision['retry_delay_minutes'];
    policyAssert($decision['terminal']===($attempt===5),'wechat transient terminal boundary attempt '.$attempt);
}
policyAssert($delays===[1,2,4,8,null],'wechat transient retries use 1/2/4/8 then terminal');
$wechatTerminal=MessageDeliveryFailurePolicy::decide('wechat_subscribe',5,'network timeout');
policyAssert($wechatTerminal['fallback_reason']==='wechat_retry_exhausted','wechat fifth transient failure triggers fallback');

$smsDelays=[];
for($attempt=1;$attempt<=5;$attempt++){
    $decision=MessageDeliveryFailurePolicy::decide('aliyun_sms',$attempt,'network timeout');
    $smsDelays[]=$decision['retry_delay_minutes'];
    policyAssert($decision['fallback_reason']===null,'SMS failure never recursively falls back attempt '.$attempt);
}
policyAssert($smsDelays===[1,2,4,8,null],'SMS transient retries use same bounded backoff');
policyAssert(MessageDeliveryFailurePolicy::decide('aliyun_sms',5,'network timeout')['terminal']===true,'SMS fifth failure is terminal');

fwrite(STDOUT,"Message delivery failure policy passed.\n");

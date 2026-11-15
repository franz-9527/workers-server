<?php

declare(strict_types=1);

use WorkerAdmin\Domain\Messaging\PaidSmsPolicy;
use WorkerAdmin\Domain\Messaging\PaidSmsReceiptPolicy;

$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($path))require_once $path;
});

function paidReceiptAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

$mobile='TEST_MOBILE';
$invite=['public_no'=>'PAID-PROBE','aliyun_biz_id'=>''];
$expected='nearby-invite:PAID-PROBE';

$matched=PaidSmsPolicy::receipt($invite,$mobile,[[
    'out_id'=>$expected,'phone_num'=>$mobile,'send_status'=>3,'err_code'=>'DELIVERED',
]]);
paidReceiptAssert(is_array($matched),'exact OutId receipt matches');
$delivered=PaidSmsReceiptPolicy::classify($matched);
paidReceiptAssert($delivered['state']==='delivered'&&$delivered['billing_status']==='charged','delivered receipt classifies as charged');
paidReceiptAssert($delivered['should_charge']===true&&$delivered['should_release']===false,'delivered receipt charges only');

$wrongOut=PaidSmsPolicy::receipt($invite,$mobile,[[
    'out_id'=>'nearby-invite:OTHER','phone_num'=>$mobile,'send_status'=>3,
]]);
paidReceiptAssert($wrongOut===null,'without BizId wrong OutId never matches');

$bizInvite=['public_no'=>'PAID-PROBE','aliyun_biz_id'=>'BIZ-PRESENT'];
$bizMatched=PaidSmsPolicy::receipt($bizInvite,$mobile,[[
    'out_id'=>'','phone_num'=>$mobile,'send_status'=>3,'err_code'=>'DELIVERED',
]]);
paidReceiptAssert(is_array($bizMatched),'BizId query accepts missing OutId');
$bizWrongOut=PaidSmsPolicy::receipt($bizInvite,$mobile,[[
    'out_id'=>'nearby-invite:OTHER','phone_num'=>$mobile,'send_status'=>3,
]]);
paidReceiptAssert($bizWrongOut===null,'BizId query still rejects conflicting non-empty OutId');
$wrongPhone=PaidSmsPolicy::receipt($bizInvite,$mobile,[[
    'out_id'=>'','phone_num'=>'OTHER_MOBILE','send_status'=>3,
]]);
paidReceiptAssert($wrongPhone===null,'receipt for another phone never matches');

$failed=PaidSmsReceiptPolicy::classify([
    'out_id'=>$expected,'phone_num'=>$mobile,'send_status'=>2,'err_code'=>'OPERATOR_REJECTED',
]);
paidReceiptAssert($failed['state']==='delivery_failed'&&$failed['billing_status']==='released','failed receipt classifies as released');
paidReceiptAssert($failed['should_charge']===false&&$failed['should_release']===true,'failed receipt releases only');

$pending=PaidSmsReceiptPolicy::classify(['send_status'=>1]);
paidReceiptAssert($pending['state']==='pending'&&$pending['billing_status']==='held','non-terminal receipt remains held');
paidReceiptAssert(PaidSmsReceiptPolicy::classify(null)['state']==='pending','missing receipt remains pending');

$delays=[];
for($attempt=1;$attempt<=6;$attempt++)$delays[]=PaidSmsReceiptPolicy::retryDelaySeconds($attempt);
paidReceiptAssert($delays===[30,120,600,1800,3600,3600],'receipt retry backoff stays bounded');

$timeout=PaidSmsReceiptPolicy::timeout();
paidReceiptAssert($timeout['delivery_status']==='unknown'&&$timeout['billing_status']==='released','receipt timeout becomes unknown and released');
paidReceiptAssert($timeout['retain_active_dedupe_key']===true,'receipt timeout retains anti-resend dedupe key');

fwrite(STDOUT,"Paid SMS receipt policy passed.\n");

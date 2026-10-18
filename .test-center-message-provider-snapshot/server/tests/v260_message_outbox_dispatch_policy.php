<?php

declare(strict_types=1);

use WorkerAdmin\Application\Messaging\MessageOutboxDispatch;
use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Contracts\MessageDeliveryQueue;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SubscribeMessageGateway;

$root=dirname(__DIR__,2);
spl_autoload_register(static function(string $class)use($root):void{
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($path))require_once $path;
});

function dispatchPolicyAssert(bool $condition,string $label): void
{
    if(!$condition){
        fwrite(STDERR,'[FAIL] '.$label."\n");
        exit(1);
    }
    fwrite(STDOUT,'[PASS] '.$label."\n");
}

final class PolicyQueue implements MessageDeliveryQueue
{
    public array $rows=[];
    public array $failed=[];
    public array $fallbacks=[];
    public array $invalidated=[];
    public array $completed=[];
    public function assertSendBoundary():void{}
    public function recoverExpiredClaims():void{}
    public function pending(int $limit):array{return array_slice($this->rows,0,$limit);}
    public function claim(int $id):bool{return true;}
    public function complete(int $id):void{$this->completed[]=$id;}
    public function fail(int $id,string $error,?int $delayMinutes):void{$this->failed[]=[$id,$error,$delayMinutes];}
    public function consumeSubscription(int $userId,string $scene):void{}
    public function invalidateSubscription(int $userId,string $scene):void{$this->invalidated[]=[$userId,$scene];}
    public function enqueueFallback(array $payload):bool{$this->fallbacks[]=$payload;return true;}
}

final class PolicySms implements SmsGateway
{
    public string $failure='';
    public function enabled():bool{return true;}
    public function send(string $mobile,string $templateCode,array $parameters,string $outId='',bool $test=false):array
    {
        if($this->failure!=='')throw new RuntimeException($this->failure);
        return[];
    }
    public function sendWithOutcome(string $mobile,string $templateCode,array $parameters,string $outId='',bool $test=false):array{return[];}
    public function querySendDetails(string $mobile,string $sendDate,?string $bizId=null):array{return[];}
}

final class PolicySubscribe implements SubscribeMessageGateway
{
    public string $failure='';
    public function categoryList():array{return[];}
    public function publicTemplateTitles(array $categoryIds,int $start=0,int $limit=30):array{return[];}
    public function publicTemplateKeywords(string $templateTitleId):array{return[];}
    public function addTemplate(string $templateTitleId,array $keywordIds,string $sceneDesc=''):string{return'';}
    public function templateList():array{return[];}
    public function send(array $payload):array
    {
        if($this->failure!=='')throw new RuntimeException($this->failure);
        return[];
    }
}

final class PolicyAdminAlert implements AdminAlertGateway
{
    public function send(string $text,?string $path=null):array{return[];}
}

$queue=new PolicyQueue();
$sms=new PolicySms();
$subscribe=new PolicySubscribe();
$dispatch=new MessageOutboxDispatch($queue,$sms,$subscribe,new PolicyAdminAlert());

$basePayload='{"user_id":42,"scene_key":"reviewResult","sms_fallback":{"business_key":"fallback-key","event_type":"worker_assigned"}}';

$queue->rows=[['id'=>1,'topic'=>'wechat_subscribe','business_key'=>'wx-43101','payload_json'=>$basePayload,'attempt_count'=>0]];
$subscribe->failure='微信订阅消息发送失败（43101）：permission unavailable';
$result=$dispatch->dispatch();
dispatchPolicyAssert($result['failure']===1,'43101 remains a failed provider attempt');
dispatchPolicyAssert(end($queue->failed)[2]===null,'43101 remains terminal without retry delay');
dispatchPolicyAssert($queue->invalidated===[[42,'reviewResult']],'43101 still invalidates subscription quota');
dispatchPolicyAssert(count($queue->fallbacks)===1&&$queue->fallbacks[0]['fallback_reason']==='wechat_permanent_43101','43101 still immediately falls back once');

$queue->rows=[['id'=>2,'topic'=>'wechat_subscribe','business_key'=>'wx-net-1','payload_json'=>$basePayload,'attempt_count'=>0]];
$subscribe->failure='network timeout';
$fallbacks=count($queue->fallbacks);
$dispatch->dispatch();
dispatchPolicyAssert(end($queue->failed)[2]===1,'first transient WeChat failure retries after one minute');
dispatchPolicyAssert(count($queue->fallbacks)===$fallbacks,'first transient WeChat failure does not fall back');

$queue->rows=[['id'=>3,'topic'=>'wechat_subscribe','business_key'=>'wx-net-4','payload_json'=>$basePayload,'attempt_count'=>3]];
$dispatch->dispatch();
dispatchPolicyAssert(end($queue->failed)[2]===8,'fourth transient WeChat failure retries after eight minutes');

$queue->rows=[['id'=>4,'topic'=>'wechat_subscribe','business_key'=>'wx-net-5','payload_json'=>$basePayload,'attempt_count'=>4]];
$fallbacks=count($queue->fallbacks);
$dispatch->dispatch();
dispatchPolicyAssert(end($queue->failed)[2]===null,'fifth transient WeChat failure is terminal');
dispatchPolicyAssert(count($queue->fallbacks)===$fallbacks+1&&end($queue->fallbacks)['fallback_reason']==='wechat_retry_exhausted','fifth transient WeChat failure falls back exactly once');

$queue->rows=[['id'=>5,'topic'=>'aliyun_sms','business_key'=>'sms-net-5','payload_json'=>'{"mobile":"13800131234","template_code":"SMS_TEST","parameters":{}}','attempt_count'=>4]];
$sms->failure='network timeout';
$fallbacks=count($queue->fallbacks);
$dispatch->dispatch();
dispatchPolicyAssert(end($queue->failed)[2]===null,'fifth SMS transient failure is terminal');
dispatchPolicyAssert(count($queue->fallbacks)===$fallbacks,'terminal SMS failure never recursively degrades');

fwrite(STDOUT,"Message outbox shared failure-policy regression passed.\n");

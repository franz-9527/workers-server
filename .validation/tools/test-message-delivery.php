<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void{if(strpos($class,'WorkerAdmin\\')!==0)return;$f=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';if(is_file($f))require_once $f;});
$checks=0;
function deliveryAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);++$GLOBALS['checks'];}
function deliveryReject(callable $call,string $message):void{try{$call();throw new LogicException('expected rejection');}catch(RuntimeException $e){deliveryAssert(strpos($e->getMessage(),$message)!==false,$message);}}
function setting($key,$default=null){return $default;}
function app_config($key,$default=null){return $default;}
final class DeliveryQueue implements \WorkerAdmin\Contracts\MessageDeliveryQueue {
 public array $rows=[];public array $done=[];public array $failed=[];public array $consumed=[];public array $invalid=[];public array $fallback=[];public bool $blocked=false;public bool $claimable=true;public int $recoveries=0;
 public function assertSendBoundary():void{if($this->blocked)throw new RuntimeException('transaction blocked');}
 public function recoverExpiredClaims():void{++$this->recoveries;}
 public function pending(int $limit):array{return array_slice($this->rows,0,$limit);}
 public function claim(int $id):bool{return $this->claimable;}
 public function complete(int $id):void{$this->done[]=$id;}
 public function fail(int $id,string $error,?int $delay):void{$this->failed[]=[$id,$error,$delay];}
 public function consumeSubscription(int $id,string $scene):void{$this->consumed[]=[$id,$scene];}
 public function invalidateSubscription(int $id,string $scene):void{$this->invalid[]=[$id,$scene];}
 public function enqueueFallback(array $data):bool{$this->fallback[]=$data;return true;}
}
final class DeliverySms implements \WorkerAdmin\Contracts\SmsGateway {
 public array $sent=[];public array $receipts=[];public string $failure='';public array $outcome=['outcome'=>'accepted','biz_id'=>'biz','request_id'=>'request','provider_code'=>'OK'];public array $details=[];
 public function enabled():bool{return true;}
 public function send(string $m,string $c,array $p,string $o='',bool $test=false):array{$this->sent[]=[$m,$c,$p,$o];if($this->failure!=='')throw new RuntimeException($this->failure);return [];}
 public function sendWithOutcome(string $m,string $c,array $p,string $o='',bool $test=false):array{$this->sent[]=[$m,$c,$p,$o];return $this->outcome;}
 public function querySendDetails(string $m,string $d,?string $b=null):array{$this->receipts[]=[$m,$d,$b];return ['outcome'=>'ok','details'=>$this->details];}
}
final class DeliverySubscribe implements \WorkerAdmin\Contracts\SubscribeMessageGateway {
 public array $sent=[];public string $failure='';
 public function categoryList():array{return [];}
 public function publicTemplateTitles(array $a,int $s=0,int $l=30):array{return [];}
 public function publicTemplateKeywords(string $tid):array{return [];}
 public function addTemplate(string $tid,array $kids,string $desc=''):string{return '';}
 public function templateList():array{return [];}
 public function send(array $p):array{$this->sent[]=$p;if($this->failure!=='')throw new RuntimeException($this->failure);return [];}
}
final class DeliveryAdminAlert implements \WorkerAdmin\Contracts\AdminAlertGateway {public array $sent=[];public function send(string $text,?string $path=null):array{$this->sent[]=[$text,$path];return ['errcode'=>0,'errmsg'=>'ok'];}}
$queue=new DeliveryQueue();$sms=new DeliverySms();$wechat=new DeliverySubscribe();$wecom=new DeliveryAdminAlert();$dispatch=new \WorkerAdmin\Application\Messaging\MessageOutboxDispatch($queue,$sms,$wechat,$wecom);
$queue->rows=[['id'=>1,'topic'=>'aliyun_sms','business_key'=>'one','payload_json'=>'{"mobile":"13800131234","template_code":"SMS_12345678","parameters":{}}','attempt_count'=>0],['id'=>2,'topic'=>'wechat_subscribe','business_key'=>'two','payload_json'=>'{"user_id":42,"scene_key":"reviewResult"}','attempt_count'=>0],['id'=>3,'topic'=>'wecom_webhook','business_key'=>'three','payload_json'=>'{"content":"hello","action_path":"/admin/?route=workers"}','attempt_count'=>0]];
$result=$dispatch->dispatch();deliveryAssert($result===['scanned'=>3,'success'=>3,'failure'=>0] && $queue->done===[1,2,3],'three channels acknowledged');
deliveryAssert($queue->consumed===[[42,'reviewResult']] && $wecom->sent[0][1]==='/admin/?route=workers','quota consumption and action target');
$before=count($sms->sent)+count($wechat->sent)+count($wecom->sent);$queue->claimable=false;$result=$dispatch->dispatch();deliveryAssert($result['success']===0 && count($sms->sent)+count($wechat->sent)+count($wecom->sent)===$before,'lost claim never sends');$queue->claimable=true;
$queue->blocked=true;deliveryReject(static function()use($dispatch){$dispatch->dispatch();},'transaction');deliveryAssert(count($sms->sent)+count($wechat->sent)+count($wecom->sent)===$before,'outer transaction cannot send');$queue->blocked=false;
$queue->rows=[['id'=>4,'topic'=>'wechat_subscribe','business_key'=>'four','payload_json'=>'{"user_id":42,"scene_key":"reviewResult","sms_fallback":{"business_key":"fallback"}}','attempt_count'=>0]];
$wechat->failure='微信订阅消息发送失败（43101）';$result=$dispatch->dispatch();deliveryAssert($result['failure']===1 && $queue->invalid===[[42,'reviewResult']] && end($queue->failed)[2]===null,'permanent authorization error terminates and clears quota');
deliveryAssert(end($queue->fallback)['fallback_reason']==='wechat_permanent_43101','permanent error immediately falls back');
$wechat->failure='network timeout';$queue->rows[0]['attempt_count']=0;$count=count($queue->fallback);$dispatch->dispatch();deliveryAssert(end($queue->failed)[2]===1 && count($queue->fallback)===$count,'first transient error retries without fallback');
$queue->rows[0]['attempt_count']=3;$dispatch->dispatch();deliveryAssert(end($queue->failed)[2]===8,'fourth attempt exponential delay');
$queue->rows[0]['attempt_count']=4;$dispatch->dispatch();deliveryAssert(end($queue->failed)[2]===null && end($queue->fallback)['fallback_reason']==='wechat_retry_exhausted','fifth failure terminal fallback');
$queue->rows=[['id'=>5,'topic'=>'aliyun_sms','business_key'=>'bad','payload_json'=>'bad','attempt_count'=>0]];$count=count($sms->sent);$dispatch->dispatch();deliveryAssert(count($sms->sent)===$count && end($queue->failed)[2]===1,'invalid payload never calls provider');
// Paid send/receipt App is tested through the explicit repository port, not old private methods.
final class PaidDeliveryRepo implements \WorkerAdmin\Contracts\PaidSmsDeliveryRepository {
 public array $invite=['id'=>1,'public_no'=>'PAID-ONE','delivery_status'=>'queued','billing_status'=>'held','mobile_ciphertext_snapshot'=>'13800131234','distance_m_snapshot'=>1200,'category_names_snapshot'=>'墙布 https://x.test 13800131234','category_name_snapshot'=>'墙布','workflow_profile'=>'service','district'=>'未知位置','receipt_attempt_count'=>0,'attempted_at'=>'2026-10-08 00:30:00','aliyun_biz_id'=>null];
 public bool $blocked=false;public string $claim='claimed';public bool $valid=true;public array $outcomes=[];public array $matched=[];public array $schedules=[];public array $cancelled=[];public array $processed=[];
 public function pending(int $l):array{return ['rows'=>[],'recovered'=>0];}
 public function receiptPending(int $l):array{return ['rows'=>[],'reconciled'=>0];}
 public function claimOutbox(int $o,int $i):bool{return true;}
 public function failDispatch(int $o,int $i,Throwable $e):void{}
 public function inviteForSend(int $id):?array{return $this->invite;}
 public function markUncertain(int $id):void{$this->invite['delivery_status']='uncertain';}
 public function recordOutcome(array $invite,array $outcome,?int $outbox):void{$this->outcomes[]=$outcome;$this->invite['delivery_status']=$outcome['outcome']==='accepted'?'provider_accepted':'uncertain';}
 public function prepareReceipt(int $id):?array{return $this->invite;}
 public function applyReceipt(array $invite,array $matched):void{$this->matched[]=$matched;}
 public function cleanupTerminalMobileCiphertexts():int{return 0;}
 public function claimSendAttempt(array $invite,int $hours):string{if($this->claim==='claimed')$this->invite['delivery_status']='sending';return $this->claim;}
 public function preSendValid(array $invite):bool{return $this->valid;}
 public function cancelBeforeSend(array $invite,string $why):void{$this->cancelled[]=$why;}
 public function scheduleReceipt(int $id,int $attempt,string $why):void{$this->schedules[]=[$id,$attempt,$why];}
 public function markOutboxProcessed(int $id):void{$this->processed[]=$id;}
 public function refreshBatch(int $id):void{}
 public function templateCode():string{return 'SMS_12345678';}
 public function assertSendBoundary():void{if($this->blocked)throw new RuntimeException('transaction blocked');}
}
$paidRepo=new PaidDeliveryRepo();$paidSms=new DeliverySms();
$cipher=new class implements \WorkerAdmin\Contracts\PrivateValueCipher{public function hmac(string $s,string $c):string{return $s;}public function encrypt(string $s,string $c):?string{return $s;}public function decrypt(string $s,string $c):string{return $s;}};
$settings=new class implements \WorkerAdmin\Contracts\SettingsProvider{public function get(string $k,$d=null){return $d;}public function bool(string $k,bool $d=false):bool{return $d;}public function int(string $k,int $d=0):int{return $d;}};
$diagnostics=new class implements \WorkerAdmin\Contracts\MessageDiagnostics{public function write(string $c,string $x,Throwable $e):void{}};
$paid=new \WorkerAdmin\Application\Messaging\PaidSmsDelivery($paidRepo,$paidSms,new \WorkerAdmin\Application\Identity\MobileNumberProtection($cipher),$settings,$diagnostics);
$paid->dispatchInvite(1,10);deliveryAssert(count($paidSms->sent)===1 && $paidSms->sent[0][3]==='nearby-invite:PAID-ONE','paid send stable OutId');
deliveryAssert($paidSms->sent[0][2]['district']==='您附近' && strpos($paidSms->sent[0][2]['category'],'http')===false && strpos($paidSms->sent[0][2]['category'],'13800131234')===false,'paid params never contain detailed/private content');
$paid->dispatchInvite(1,10);deliveryAssert(count($paidSms->sent)===1,'accepted invitation never re-sends');
$paidRepo->invite['delivery_status']='sending';$paid->dispatchInvite(1,10);deliveryAssert(count($paidSms->sent)===1 && $paidRepo->invite['delivery_status']==='uncertain','crash-after-send transitions to uncertain');
$paidRepo->invite['delivery_status']='queued';$paidRepo->valid=false;$paid->dispatchInvite(1,10);deliveryAssert(count($paidSms->sent)===1 && count($paidRepo->cancelled)===1,'invalid candidate cancelled before provider call');$paidRepo->valid=true;
$paidRepo->claim='hidden';$paid->dispatchInvite(1,10);deliveryAssert(count($paidSms->sent)===1 && count($paidRepo->cancelled)===2,'mobile touch threshold does not send');
$paidRepo->blocked=true;deliveryReject(static function()use($paid){$paid->dispatchInvite(1);},'transaction');$paidRepo->blocked=false;
$paidRepo->invite['delivery_status']='uncertain';$paidSms->details=[['out_id'=>'OTHER','send_status'=>3,'phone_num'=>'13800131234']];$paid->checkReceipt(1);deliveryAssert(!$paidRepo->matched && count($paidRepo->schedules)===1,'uncertain receipt requires exact OutId');
$paidSms->details=[['out_id'=>'nearby-invite:PAID-ONE','send_status'=>3,'phone_num'=>'13800131234']];$paid->checkReceipt(1);deliveryAssert(count($paidRepo->matched)===1 && $paidSms->receipts[0][1]==='20261008','matching receipt and China natural day');
$paidSms->details[0]['phone_num']='13900131234';$paid->checkReceipt(1);deliveryAssert(count($paidRepo->matched)===1,'another phone receipt never applied');
$paidRepo->invite['aliyun_biz_id']='biz';$paidSms->details=[['out_id'=>'','send_status'=>3,'phone_num'=>'13800131234']];$paid->checkReceipt(1);deliveryAssert(count($paidRepo->matched)===2,'BizId query accepts absent OutId');
// Formal owners remain SQL/HTTP free at inward layers; old service files are delegation-only.
foreach(['OrderNotificationService','MessageEventCatalog','AliyunSmsService','WeChatSubscribeMessageService','PaidSmsDispatchService']as $name){$s=(string)file_get_contents($root.'/server/app/Services/'.$name.'.php');deliveryAssert(!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b|curl_exec\s*\(/',$s),'legacy '.$name.' has no implementation');}
$source=(string)file_get_contents($root.'/server/app/Services/SchedulerService.php');$begin=strpos($source,'private function runSmsDispatch()');$end=strpos($source,'private function runDraftCleanup(', $begin);deliveryAssert(strpos(substr($source,$begin,$end-$begin),'->dispatcher()->dispatch')!==false && strpos(substr($source,$begin,$end-$begin),'UPDATE ')===false,'old scheduler message method delegates only');
foreach (['appointment_recorded','appointment_waiting_customer_notice','repair_appointment_recorded','repair_waiting_customer_notice'] as $eventType) {
    $assisted = \WorkerAdmin\Domain\Messaging\OrderMessageDefinition::definition($eventType,['assisted'=>true,'wait_customer_notice'=>strpos($eventType,'waiting')!==false]);
    deliveryAssert(strpos($assisted['status'],'管理员')===0,'assisted appointment message identifies the real administrator: '.$eventType);
    deliveryAssert($assisted['recipients']===['user','worker'],'assisted appointment notifies both parties once: '.$eventType);
    $normal = \WorkerAdmin\Domain\Messaging\OrderMessageDefinition::definition($eventType,[]);
    deliveryAssert($normal['recipients']===['user'],'normal worker appointment preserves original recipients: '.$eventType);
}
foreach (['completion_submitted','measurement_result_submitted','platform_measurement_auto_confirmed','repair_worker_completed'] as $eventType) {
    $assisted=\WorkerAdmin\Domain\Messaging\OrderMessageDefinition::definition($eventType,['assisted'=>true]);
    deliveryAssert($assisted!==null && strpos($assisted['status'],'管理员')===0,'result notification preserves real administrator source: '.$eventType);
    deliveryAssert($assisted['recipients']===['user','worker'],'result notifies current parties exactly once: '.$eventType);
    $normal=\WorkerAdmin\Domain\Messaging\OrderMessageDefinition::definition($eventType,[]);
    deliveryAssert(strpos($normal['status'],'管理员')!==0,'normal worker/system source is unchanged: '.$eventType);
}

(static function():void{
    $q=new DeliveryQueue();$sms=new DeliverySms();$sub=new DeliverySubscribe();$admin=new DeliveryAdminAlert();
    $dispatch=new \WorkerAdmin\Application\Messaging\MessageOutboxDispatch($q,$sms,$sub,$admin);
    $q->rows=[
        ['id'=>901,'topic'=>'wechat_subscribe','business_key'=>'old-payment','payload_json'=>'{"user_id":42,"scene_key":"payment_success","sms_fallback":{"business_key":"must-not-send"}}','attempt_count'=>0],
        ['id'=>902,'topic'=>'aliyun_sms','business_key'=>'old-refund','payload_json'=>'{"event_type":"refund_succeeded","mobile":"13800131234","template_code":"SMS_12345678"}','attempt_count'=>0],
    ];
    $dispatch->dispatch();
    deliveryAssert(!$sms->sent && !$sub->sent && !$admin->sent && !$q->fallback && !$q->consumed,'retired pending reminders never send, consume quota or fall back to SMS');
    deliveryAssert(count($q->failed)===2 && $q->failed[0][2]===null && $q->failed[1][2]===null,'retired queued messages terminate without retries');
})();

echo 'Complete message delivery: '.$checks.' checks passed.'.PHP_EOL;

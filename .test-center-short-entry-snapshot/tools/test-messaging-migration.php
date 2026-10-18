<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use($root): void {
    if(strpos($class,'WorkerAdmin\\')!==0)return;
    $path=$root.'/server/app/'.str_replace('\\','/',substr($class,12)).'.php';
    if(is_file($path))require_once $path;
});
$checks=0;
function messagingAssert(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks']; }
function messagingReject(callable $call,string $message): void {
    try{$call();throw new LogicException('Expected rejection');}catch(RuntimeException $e){messagingAssert(strpos($e->getMessage(),$message)!==false,'rejection: '.$message);}
}
function setting($key,$default=null){return $default;}
function app_config($key,$default=null){return $default;}
function request_id(){return 'messaging-test';}
function admin_url($route,$params=[]){return '/admin/?route='.$route.'&'.http_build_query($params);}
function flash($type,$message){$GLOBALS['flashes'][]=[$type,$message];}
final class MessagingRedirect extends Exception {}
function redirect($url){$GLOBALS['redirects'][]=$url;if(!empty($GLOBALS['terminateRedirect']))throw new MessagingRedirect($url);}
function old_input($data){$GLOBALS['old']=$data;}
function audit(...$args){$GLOBALS['audits'][]=$args;}
function write_log(...$args){$GLOBALS['logs'][]=$args;}
function view($name,$data){$GLOBALS['views'][]=[$name,$data];return 'rendered';}
final class MessagingResponse extends Exception {
    public array $payload; public int $status;
    public function __construct(array $payload,int $status){parent::__construct('response');$this->payload=$payload;$this->status=$status;}
}
function json_response($data,$status=200){throw new MessagingResponse($data,$status);}
final class MessagingInput {
    public $context; private int $offset=0; public static string $body='{}';
    public function stream_open($path,$mode,$options,&$opened){return $path==='php://input';}
    public function stream_read($size){$s=substr(self::$body,$this->offset,$size);$this->offset+=strlen($s);return $s;}
    public function stream_eof(){return $this->offset>=strlen(self::$body);}
    public function stream_stat(){return [];}
}
final class MessagingSettings implements \WorkerAdmin\Contracts\SettingsProvider {
    public bool $configured=true;
    public function get(string $key,$default=null){return $key==='wechat.miniapp_appid'?($this->configured?'test-app':''):$default;}
    public function bool(string $key,bool $default=false):bool{return $default;}
    public function int(string $key,int $default=0):int{return $default;}
}
final class MessagingActors implements \WorkerAdmin\Contracts\ApiActorRepository {
    public bool $found=true;public int $touches=0;
    public function findByTokenHash(string $hash,string $appid):?array{return $this->found?['session_id'=>1,'user_id'=>42,'worker_id'=>7,'payer_openid'=>'ordinary']:null;}
    public function touchSession(int $id):void{++$this->touches;}
    public function findDemoActor(int $id):?array{return null;}
    public function findTestActor(int $userId,int $instanceId):?array{return null;}
    public function clearDemoActor(int $id):void{}
    public function clearTestActor(int $id):void{}
}
final class MessagingInbox implements \WorkerAdmin\Contracts\NotificationInboxRepository {
    public array $calls=[];
    public function summary(array $actor):array{$this->calls[]=['summary',$actor];return ['unread_count'=>3];}
    public function list(array $actor,int $page):array{$this->calls[]=['list',$actor,$page];return ['page'=>$page,'items'=>[]];}
    public function read(array $actor,string $id):void{$this->calls[]=['read',$actor,$id];}
    public function readBatch(array $actor,array $ids):int{$this->calls[]=['batch',$actor,$ids];return count($ids);}
    public function readAll(array $actor):void{$this->calls[]=['all',$actor];}
}
final class MessagingSubscriptions implements \WorkerAdmin\Contracts\SubscriptionRepository {
    public array $saves=[];
    public function definitions():array {
        $base=['title'=>'Scene','description'=>'Description','template_id'=>'id','available'=>true,'global_enabled'=>true];
        return ['user'=>$base+['recipient'=>'user'],'worker'=>$base+['recipient'=>'worker'],'reviewResult'=>$base+['recipient'=>'worker'],'long'=>$base+['recipient'=>'user','subscription_type'=>'long_term']];
    }
    public function preferences(int $id):array{return ['long'=>['remaining_count'=>10],'user'=>['remaining_count'=>-1]];}
    public function save(int $id,string $key,bool $enabled,string $status):array{$this->saves[]=[$id,$key,$enabled,$status];return ['subscription_type'=>'one_time','authorization_status'=>$status];}
    public function diagnose(int $id):array{return ['pending_count'=>$id];}
}
final class MessagingCipher implements \WorkerAdmin\Contracts\PrivateValueCipher {
    public function hmac(string $plain,string $context):string{return hash('sha256',$context.':'.$plain);}
    public function encrypt(string $plain,string $context):?string{return $plain;}
    public function decrypt(string $plain,string $context):string{return $plain;}
}
final class MessagingOutbox implements \WorkerAdmin\Contracts\MessageOutboxRepository {
    public array $calls=[]; public array $rows=[];
    public function dashboard(string $status,string $q,int $page,string $tab='records'):array{$this->calls[]=['dashboard',$status,$q,$page,$tab];return ['result'=>['rows'=>$this->rows],'suppressionResult'=>['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'per_page'=>20]];}
    public function retry(int $id):array{$this->calls[]=['retry',$id];return ['topic'=>'wechat_subscribe'];}
    public function close(int $id):array{$this->calls[]=['close',$id];return ['topic'=>'aliyun_sms'];}
    public function addSuppression(string $hash,string $masked,string $reason,string $note,int $admin):void{$this->calls[]=['add',$hash,$masked,$reason,$note,$admin];}
    public function removeSuppression(int $id):array{$this->calls[]=['remove',$id];return ['mobile_masked'=>'138****1234'];}
}
$inboxRepo=new MessagingInbox();$inbox=new \WorkerAdmin\Application\Messaging\NotificationInbox($inboxRepo);
$actor=['user_id'=>42,'worker_id'=>7];
messagingReject(static function()use($inbox,$actor){$inbox->read($actor,' ');},'请选择');
messagingReject(static function()use($inbox,$actor){$inbox->readBatch($actor,array_fill(0,51,'x'));},'50');
messagingReject(static function()use($inbox,$actor){$inbox->readBatch($actor,['',[]]);},'请选择');
messagingAssert(!$inboxRepo->calls,'invalid writes never reach repository');
messagingAssert($inbox->readBatch($actor,[' x ','x','y'])===['updated_count'=>2],'batch trims and deduplicates');
$inbox->list($actor,99999);messagingAssert(end($inboxRepo->calls)[2]===10000,'page maximum');
$inbox->list($actor,-1);messagingAssert(end($inboxRepo->calls)[2]===1,'page minimum');
$settings=new MessagingSettings();$subRepo=new MessagingSubscriptions();$subs=new \WorkerAdmin\Application\Messaging\SubscriptionPreferences($subRepo,$settings);
$user=['user_id'=>42,'worker_id'=>null];
messagingAssert(array_column($subs->status($user,false)['items'],'key')===['user','long'],'ordinary account hides worker scenes');
messagingAssert(array_column($subs->status($user,true)['items'],'key')===['user','reviewResult','long'],'application only allows review result exception');
$items=$subs->status($actor,false)['items'];messagingAssert(count($items)===4 && $items[3]['remaining_count']===null && $items[0]['remaining_count']===0,'worker scenes and long term/count projection');
messagingReject(static function()use($subs,$user){$subs->save($user,['key'=>'worker']);},'没有师傅身份');
try{$subs->save($actor+['is_demo_actor'=>true],['key'=>'user']);throw new LogicException('demo allowed');}
catch(\WorkerAdmin\Domain\Order\OrderOperationException $e){messagingAssert($e->apiCode()==='DEMO_EXTERNAL_ACTION_BLOCKED' && $e->getCode()===403,'demo preference blocks before persistence');}
messagingAssert(!$subRepo->saves,'blocked preferences never saved');
$subs->save($user,['key'=>'reviewResult','enabled'=>true,'authorization_status'=>'acceptWithAudio']);
messagingAssert($subRepo->saves[0][0]===42 && $subRepo->saves[0][1]==='reviewResult','onboarding subscription uses trusted actor');
$outRepo=new MessagingOutbox();$console=new \WorkerAdmin\Application\Messaging\MessageOutboxConsole($outRepo,new \WorkerAdmin\Application\Identity\MobileNumberProtection(new MessagingCipher()));
messagingReject(static function()use($console){$console->close(1,'bad');},'5～200');
messagingReject(static function()use($console){$console->retry(0);},'编号');
messagingReject(static function()use($console){$console->addSuppression('bad','manual','',3);},'手机号');
messagingReject(static function()use($console){$console->addSuppression('13800131234','manual',str_repeat('字',301),3);},'300');
messagingAssert(!$outRepo->calls,'invalid admin commands never persist');
$console->dashboard('bogus',str_repeat('字',110),-9,'bad-tab');$call=end($outRepo->calls);
messagingAssert($call[1]==='' && mb_strlen($call[2])===100 && $call[3]===1 && $call[4]==='records','dashboard filters and tab normalized');
$outRepo->rows=[['topic'=>'wecom_webhook','business_key'=>'worker:42','_payload_json'=>json_encode(['event_type'=>'worker_profile_submitted','title'=>'新的师傅入驻资料待审核'],JSON_UNESCAPED_UNICODE)]];
$dashboard=$console->dashboard('','',2,'suppressions');$call=end($outRepo->calls);
messagingAssert($call[3]===2 && $call[4]==='suppressions' && $dashboard['activeTab']==='suppressions','suppression tab and page preserved');
messagingAssert($dashboard['result']['rows'][0]['business_label']==='师傅入驻 / 资料审核' && !isset($dashboard['result']['rows'][0]['_payload_json']),'business label is readable and raw payload never reaches view');
$outRepo->rows=[];
$result=$console->addSuppression('+86 13800131234','bogus',' note ',3);$call=end($outRepo->calls);
messagingAssert($result===['masked'=>'138****1234','reason'=>'manual'] && $call[2]==='138****1234' && $call[4]==='note' && $call[5]===3,'suppression masks and retains admin identity');
messagingAssert($call[1]===hash('sha256','sms-recipient-mobile:13800131234'),'canonical privacy context');
foreach(['pending','processing','processed','closed']as $status)messagingReject(static function()use($status){\WorkerAdmin\Domain\Messaging\MessageOutboxPolicy::requireDeadLetter(['topic'=>'aliyun_sms','status'=>$status,'attempt_count'=>5],'retry');},'仅可');
messagingReject(static function(){\WorkerAdmin\Domain\Messaging\MessageOutboxPolicy::requireDeadLetter(['topic'=>'aliyun_sms','status'=>'failed','attempt_count'=>4],'close');},'5 次');
messagingReject(static function(){\WorkerAdmin\Domain\Messaging\MessageOutboxPolicy::requireDeadLetter(['topic'=>'aliyun_paid_sms','status'=>'failed','attempt_count'=>5],'retry');},'付费短信');
foreach(['aliyun_sms','wechat_subscribe','wecom_webhook']as $topic){\WorkerAdmin\Domain\Messaging\MessageOutboxPolicy::requireDeadLetter(['topic'=>$topic,'status'=>'failed','attempt_count'=>5],'retry');messagingAssert(true,'eligible general dead letter');}
// Public API dispatch via Kernel, including status/error envelopes and trusted identity.
$db=new \WorkerAdmin\Core\Database(['database'=>'offline']);$actorRepo=new MessagingActors();$resolver=new \WorkerAdmin\Application\Identity\ApiActorResolver($actorRepo,$settings);
$module=new \WorkerAdmin\Http\Api\Modules\MessagingApiModule($db,$resolver,$inbox,$subs);
$gate=new class implements \WorkerAdmin\Contracts\RequestContentSecurityGate{public function inspect(string $route):void{throw new LogicException('message read does not need moderation');}};
$kernel=new \WorkerAdmin\Http\Api\ApiKernel($db,[$module],$gate);
function messagingDispatch($kernel,string $route,string $method,string $body='{}',string $token='valid'):MessagingResponse {
    $_SERVER['REQUEST_METHOD']=$method;$_SERVER['HTTP_AUTHORIZATION']=$token==='valid'?'Bearer '.str_repeat('a',64):$token;MessagingInput::$body=$body;
    try{$kernel->dispatch($route);throw new LogicException('no response');}catch(MessagingResponse $response){return $response;}
}
stream_wrapper_unregister('php');stream_wrapper_register('php',MessagingInput::class);
try{
    $_GET=['page'=>2,'user_id'=>999,'worker_id'=>999];
    $response=messagingDispatch($kernel,'notifications.list','GET');$call=end($inboxRepo->calls);
    messagingAssert($response->status===200 && $call[1]['user_id']===42 && $call[1]['worker_id']===7,'client recipient IDs ignored');
    messagingAssert(messagingDispatch($kernel,'notifications.summary','GET')->payload['data']['unread_count']===3,'summary envelope');
    messagingAssert(messagingDispatch($kernel,'subscriptions.status','GET')->payload['ok']===true,'subscription status envelope');
    messagingAssert(messagingDispatch($kernel,'subscriptions.diagnose','GET')->payload['data']['pending_count']===42,'diagnostic actor scope');
    messagingAssert(messagingDispatch($kernel,'subscriptions.preference','POST','{"key":"user","enabled":true,"authorization_status":"accept"}')->payload['ok']===true,'preference success envelope');
    messagingAssert(messagingDispatch($kernel,'notifications.read','POST','{"id":"one"}')->payload['message']==='已标记为已读','single read copy');
    messagingAssert(messagingDispatch($kernel,'notifications.read_batch','POST','{"ids":["x","x","y"]}')->payload['data']['updated_count']===2,'batch envelope');
    messagingAssert(messagingDispatch($kernel,'notifications.read_all','POST')->payload['message']==='未读消息已清除','read all copy');
    messagingAssert(messagingDispatch($kernel,'notifications.read','POST','bad')->payload['code']==='NOTIFICATION_REQUIRED','legacy malformed body fallback');
    messagingAssert(messagingDispatch($kernel,'notifications.list','POST')->status===405,'method boundary');
    messagingAssert(messagingDispatch($kernel,'notifications.list','GET','{}','bad')->status===401,'invalid bearer');
    $actorRepo->found=false;messagingAssert(messagingDispatch($kernel,'notifications.list','GET')->payload['code']==='UNAUTHORIZED','expired token');$actorRepo->found=true;
    $settings->configured=false;$missing=messagingDispatch($kernel,'notifications.list','GET');messagingAssert($missing->status===503 && $missing->payload['code']==='MINIAPP_APPID_MISSING','legacy missing app config code');$settings->configured=true;
}finally{stream_wrapper_restore('php');}
// Public Admin Kernel applies first-password/CSRF before module permissions and commands.
$auth=new class($db) extends \WorkerAdmin\Core\Auth {
    public bool $allowed=true;public bool $force=false;public array $permissions=[];
    public function requireLogin(){}
    public function user(){return ['id'=>9,'must_change_password'=>$this->force];}
    public function requirePermission($p){$this->permissions[]=$p;if(!$this->allowed)throw new RuntimeException('permission denied');}
};
$csrf=new class extends \WorkerAdmin\Core\Csrf {public bool $valid=true;public function verifyRequest(){if(!$this->valid)throw new RuntimeException('csrf invalid');}};
$adminModule=new \WorkerAdmin\Http\Admin\Modules\MessageOutboxAdminModule($db,$auth,$csrf,$console);
$adminKernel=new \WorkerAdmin\Http\Admin\AdminKernel($db,$auth,$csrf,[$adminModule]);
$_SERVER['REQUEST_METHOD']='GET';$_GET=['tab'=>'suppressions','page'=>'3'];ob_start();$adminKernel->dispatch('message-outbox');ob_end_clean();
$call=end($outRepo->calls);$view=end($GLOBALS['views']);
messagingAssert(end($auth->permissions)==='settings.manage' && $view[0]==='admin/message-outbox' && $call[3]===3 && $call[4]==='suppressions' && $view[1]['activeTab']==='suppressions','dashboard permission, tab, page and view');
$auth->allowed=false;$count=count($outRepo->calls);messagingReject(static function()use($adminKernel){$adminKernel->dispatch('message-outbox');},'permission');messagingAssert(count($outRepo->calls)===$count,'permission denies before read');$auth->allowed=true;
$_SERVER['REQUEST_METHOD']='POST';$_GET=['id'=>'4'];$_POST=['_csrf'=>'secret','token'=>'secret','close_reason'=>'原因说明内容'];
$adminKernel->dispatch('message-outbox.retry');messagingAssert(end($GLOBALS['audits'])[0]==='message.outbox.retry' && end($outRepo->calls)===['retry',4],'retry public route audit');
$adminKernel->dispatch('message-outbox.close');messagingAssert(end($GLOBALS['audits'])[0]==='message.outbox.close' && end($outRepo->calls)===['close',4],'close public route audit');
$_POST=['mobile'=>'13800131234','reason'=>'opt_out','note'=>'test'];$adminKernel->dispatch('message-outbox.suppression.add');messagingAssert(end($GLOBALS['audits'])[0]==='sms.suppression.add' && strpos((string)end($GLOBALS['redirects']),'tab=suppressions')!==false,'suppression add audit and tab return');
$adminKernel->dispatch('message-outbox.suppression.remove');messagingAssert(end($GLOBALS['audits'])[0]==='sms.suppression.remove' && strpos((string)end($GLOBALS['redirects']),'tab=suppressions')!==false,'suppression remove audit and tab return');
$_POST=['token'=>'secret','_csrf'=>'secret','close_reason'=>'bad'];$adminKernel->dispatch('message-outbox.close');messagingAssert(end($GLOBALS['flashes'])[0]==='danger' && !isset($GLOBALS['old']['token']) && !isset($GLOBALS['old']['_csrf']),'business error safely flashes and preserves only form input');
// Guard redirects terminate in production. Throw a sentinel on guard-only calls to test before dispatch.
$csrf->valid=false;$count=count($outRepo->calls);$GLOBALS['terminateRedirect']=true;
try{$adminKernel->dispatch('message-outbox.retry');throw new LogicException('missing csrf redirect');}catch(MessagingRedirect $e){}
// The response boundary terminates the guarded request before module dispatch.
messagingAssert(count($outRepo->calls)===$count,'CSRF denies all business calls');
$csrf->valid=true;$auth->force=true;
try{$adminKernel->dispatch('message-outbox.retry');throw new LogicException('missing password redirect');}catch(MessagingRedirect $e){messagingAssert(strpos($e->getMessage(),'profile')!==false,'forced password return');}
messagingAssert(count($outRepo->calls)===$count,'first password guard prevents business calls');
echo 'Messaging public boundary regression: '.$checks.' checks passed.'.PHP_EOL;
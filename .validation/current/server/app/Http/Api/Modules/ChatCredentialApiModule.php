<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Foundation\ChatApplicationContainer;
use WorkerAdmin\Foundation\ApplicationContainer;
use WorkerAdmin\Application\Chat\ChatOperationalTelemetry;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Api\ApiModule;

final class ChatCredentialApiModule implements ApiModule
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function supports(string $route): bool { return str_starts_with($route,'chat.sms.') || str_starts_with($route,'chat.collaboration.') || str_starts_with($route,'chat.direct.') || in_array($route,['chat.support.entry','chat.support.prepare','chat.support.settings','chat.support.configure','chat.credentials','chat.audit.credentials','chat.moderation.credentials','chat.participants','chat.participant.profile','chat.availability','chat.voice-route','chat.simulation','chat.telemetry'],true); }
    public function requiresContentSecurity(string $route): bool { return false; }
    public function dispatch(string $route): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            header('Allow: POST');
            $this->fail('请求方式不正确', 'CHAT_METHOD_NOT_ALLOWED', 405);
        }
        header('Cache-Control: private, no-store');
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer ([A-Fa-f0-9]{64})$/D', $authorization, $match) !== 1) $this->fail('请重新登录', 'CHAT_UNAUTHORIZED', 401);
        if($route==='chat.telemetry'){$this->recordClientTelemetry($match[1]);return;}
        $credentialStarted=$route==='chat.credentials'?hrtime(true):0;
        try {
            if($route==='chat.credentials'){
                $ip=(string)($_SERVER['REMOTE_ADDR']??'');
                $redis=new \WorkerAdmin\Infrastructure\Persistence\RedisRuntime($this->db);
                // The token is hashed inside the limiter. Avoid a small shared NAT/proxy quota.
                if((filter_var($ip,FILTER_VALIDATE_IP)!==false&&$redis->allow('credential-ip',$ip,600,60)===false)
                    ||$redis->allow('credential-token',strtolower($match[1]),60,60)===false)throw new RuntimeException('CHAT_CREDENTIAL_RATE_LIMIT',429);
            }
            $chat = new ChatApplicationContainer($this->db);
            $issuer = $chat->credentials();
            $ordersOnly=($_GET['chat_scope']??null)==='orders';
            if(str_starts_with($route,'chat.direct.')){
                $identity=$issuer->exchangeMini($match[1]);
                if(($identity['transport']??'')==='simulation'||!empty($identity['test_sandbox']))throw new RuntimeException('CHAT_FORBIDDEN',403);
                $raw=file_get_contents('php://input',false,null,0,2049);
                $body=is_string($raw)&&strlen($raw)<=2048&&str_starts_with(ltrim($raw),'{')?json_decode($raw,true):null;
                if(!is_array($body))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                $data=$chat->direct()->execute((string)$identity['actor_id'],$match[1],substr($route,12),$body);
            }elseif(in_array($route,['chat.support.entry','chat.support.prepare','chat.support.settings','chat.support.configure'],true)){
                $identity=$issuer->exchangeMini($match[1]);
                if(($identity['transport']??'')==='simulation')throw new RuntimeException('CHAT_FORBIDDEN',403);
                $sandboxSupport=false;
                if(!empty($identity['test_sandbox'])){
                    if(!\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active())throw new RuntimeException('CHAT_FORBIDDEN',403);
                    $test=(new \WorkerAdmin\Application\Chat\ChatSimulation($this->db))->identity($match[1]);
                    $sandboxSupport=is_array($test)&&!empty($test['sandbox'])&&empty($test['mini_admin'])
                        && in_array((string)($test['role']??''),['customer','worker'],true)
                        && (string)($test['actor_id']??'')===(string)$identity['actor_id'];
                    if(!$sandboxSupport||in_array($route,['chat.support.settings','chat.support.configure'],true))throw new RuntimeException('CHAT_FORBIDDEN',403);
                }
                $limit=$route==='chat.support.configure'?4097:257;
                $raw=file_get_contents('php://input',false,null,0,$limit);
                if($raw===false||strlen($raw)>$limit-1)throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                $actor=(string)$identity['actor_id'];
                $chat->identityDisplay()->effective($actor,true);
                $support=$chat->support();
                if($route==='chat.support.prepare'){
                    if(!in_array(trim($raw),['','{}'],true) && !preg_match('/^\s*\{\s*"client_message_id"\s*:\s*"[1-9][0-9]{9}\.[a-f0-9]{32}"\s*\}\s*$/D',$raw))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                    $data=$support->prepare($actor,$match[1],$sandboxSupport);
                }elseif($route==='chat.support.configure'){
                    $body=json_decode($raw,true);
                    if(!is_array($body))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                    $keys=array_keys($body);sort($keys);
                    if($keys!==['auto_reply_enabled','auto_reply_text','name','revision']||!is_bool($body['auto_reply_enabled']??null)
                        ||!is_string($body['auto_reply_text']??null)||!is_string($body['name']??null)||!is_string($body['revision']??null))
                        throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                    $data=$support->configureFrontend($actor,$body['revision'],$body['name'],$body['auto_reply_enabled'],$body['auto_reply_text']);
                }else{
                    if(!in_array(trim($raw),['','{}'],true))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                    $data=$route==='chat.support.settings'?$support->frontendSettings($actor):$support->entry($actor,$sandboxSupport);
                }
            }elseif($route==='chat.voice-route'){
                $identity=$issuer->exchangeMini($match[1],false,$ordersOnly);
                $data=($identity['transport']??'')==='simulation'?['provider'=>'tencent_cloud']:(new \WorkerAdmin\Application\Chat\ChatVoiceRecognitionRoute($this->db))->read();
            }elseif($route==='chat.availability'){
                $data=$issuer->availabilityMini($match[1],$ordersOnly);
            }elseif($route==='chat.simulation'){
                $simulation=new \WorkerAdmin\Application\Chat\ChatSimulation($this->db);$identity=$simulation->identity($match[1]);
                if(!$identity)throw new RuntimeException('模拟会话身份已失效',403);
                if(!empty($identity['sandbox']))throw new RuntimeException('此会话已接入真实聊天链路，请重新进入消息页',409);
                $raw=file_get_contents('php://input',false,null,0,16385);$body=is_string($raw)&&strlen($raw)<=16384?json_decode($raw,true):null;
                if(!is_array($body)||!is_string($body['path']??null)||!is_array($body['payload']??null))throw new RuntimeException('模拟会话请求无效',400);
                $data=$simulation->execute($identity,$body['path'],$body['payload']);
            }elseif(str_starts_with($route,'chat.sms.')){
                header('Cache-Control: private, no-store');$identity=$issuer->exchangeMini($match[1],false,$ordersOnly);if(($identity['transport']??'')==='simulation'||!empty($identity['test_sandbox']))throw new RuntimeException('模拟身份不能调用真实聊天渠道',403);$raw=file_get_contents('php://input',false,null,0,8193);
                $body=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true):null;
                if(!is_array($body))throw new RuntimeException('CHAT_SMS_INVALID',400);
                $service=(new \WorkerAdmin\Foundation\MessagingApplicationContainer($this->db))->chatNudges();
                $actor=(string)$identity['actor_id'];$action=substr($route,9);
                if($action==='quote'){
                    $data=$service->quote($actor,$body);
                    if($data['items']){
                        $names=[];foreach(array_chunk($data['items'],50) as $chunk){
                            $profiles=$issuer->participantsMini($match[1],[['conversation_id'=>$body['conversation_id'],'policy_version'=>$chunk[0]['policy_version'],'sender_ids'=>array_column($chunk,'recipient_id')]],$ordersOnly);
                            foreach($profiles['items'][0]['members']??[] as $person)$names[(string)$person['actor_id']]=(string)$person['display_name'];
                        }
                        $data['items']=array_values(array_filter(array_map(static function(array $row)use($names):array{$row['display_name']=$names[$row['recipient_id']]??'';return $row;},$data['items']),static fn(array $row):bool=>$row['display_name']!==''));
                    }
                }
                elseif($action==='create')$data=$service->create($actor,$body);
                elseif($action==='status')$data=$service->status($actor,$body);
                else throw new RuntimeException('CHAT_SMS_INVALID',400);
            }elseif(str_starts_with($route,'chat.collaboration.')){
                header('Cache-Control: private, no-store');
                $identity=$issuer->exchangeMini($match[1],false,$ordersOnly);if(($identity['transport']??'')==='simulation')throw new RuntimeException('模拟身份不能加入真实聊天群',403);$raw=file_get_contents('php://input',false,null,0,8193);
                $body=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true):null;
                if(!is_array($body))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                if(!empty($identity['test_sandbox'])){
                    $data=(new \WorkerAdmin\Application\Chat\ChatSandbox($this->db))->collaborate($match[1],substr($route,19),$body);
                    \json_response(['ok'=>true,'data'=>$data]);return;
                }
                $collaboration=$chat->collaboration((string)$identity['environment']);$collaborationAction=substr($route,19);
                $data=$collaboration->execute((string)$identity['actor_id'],hash('sha256',$match[1]),$collaborationAction,$body);
                if($collaborationAction==='discover'){
                    $telemetry=new ChatOperationalTelemetry();
                    $telemetry->record('discover_scanned_orders',(float)($data['scanned_orders']??0));
                    $telemetry->record('discover_synchronized_orders',(float)($data['synchronized_orders']??0));
                }
                if(isset($data['poster_path'])){
                    $data=$collaboration->poster($data);
                }
            }elseif($route==='chat.participants'){
                header('Cache-Control: private, no-store');
                $raw=file_get_contents('php://input',false,null,0,32769);
                $body=is_string($raw)&&strlen($raw)<=32768?json_decode($raw,true):null;
                if(!is_array($body)||array_keys($body)!==['conversations']||!is_array($body['conversations']))throw new RuntimeException('展示范围无效',400);
                $data=$issuer->participantsMini($match[1],$body['conversations'],$ordersOnly);
            }elseif($route==='chat.participant.profile'){
                header('Cache-Control: private, no-store');
                $identity=$issuer->exchangeMini($match[1],false,$ordersOnly);
                if(($identity['transport']??'')==='simulation')throw new RuntimeException('CHAT_FORBIDDEN',403);
                $raw=file_get_contents('php://input',false,null,0,257);
                $body=is_string($raw)&&strlen($raw)<=256?json_decode($raw,true):null;
                if(!is_array($body)){throw new RuntimeException('CHAT_INVALID_REQUEST',400);}
                $keys=array_keys($body);sort($keys);
                if($keys!==['actor_id','conversation_id']||!is_string($body['actor_id'])||!is_string($body['conversation_id']))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                $data=$chat->identityDisplay()->participantProfile((string)$identity['actor_id'],$body['conversation_id'],$body['actor_id'],$match[1],!empty($identity['test_sandbox']),$ordersOnly);
                $actor=(new ApplicationContainer($this->db))->apiActorResolver()->resolve($match[1]);
                $data['can_moderate']=!empty($actor['is_admin'])
                    && (string)($actor['user_id']??'')===(string)$identity['actor_id']
                    && $body['actor_id']!==(string)$identity['actor_id']
                    && !in_array('admin',is_array($data['roles']??null)?$data['roles']:[],true);
            }elseif($route==='chat.moderation.credentials'){
                $raw=file_get_contents('php://input',false,null,0,17);
                if($raw===false||strlen($raw)>16||!in_array(trim($raw),['','{}'],true))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                $data=$issuer->exchangeMiniModerator($match[1]);
            }else $data=$issuer->exchangeMini($match[1],$route==='chat.audit.credentials',$ordersOnly);
            if($credentialStarted>0)$this->recordCredentialLatency($credentialStarted);
            \json_response(['ok'=>true,'data'=>$data]);
        } catch (Throwable $exception) {
            if($credentialStarted>0)$this->recordCredentialLatency($credentialStarted);
            if($route==='chat.credentials'&&$exception->getCode()!==429){
                $reason=in_array($exception->getMessage(),['CHAT_SIGNING_NOT_CONFIGURED','CHAT_RECOVERY_NOT_READY','CHAT_DISABLED'],true)?$exception->getMessage():'CHAT_DEPENDENCY_UNAVAILABLE';
                \write_log('chat-readiness','['.gmdate('c').'] request_id='.\request_id().' reason='.$reason."\n");
            }
            // Deliberately don't pass the exception/SQL/token to the global API logger.
            $status = $exception instanceof RuntimeException && in_array($exception->getCode(),[400,401,403,404,409,429],true) ? $exception->getCode() : 503;
            if($exception instanceof RuntimeException && str_starts_with($exception->getMessage(),'CHAT_')){
                $messages=['CHAT_CREDENTIAL_RATE_LIMIT'=>'请求过于频繁，请稍后再试','CHAT_SIGNING_NOT_CONFIGURED'=>'沟通服务暂不可用，请稍后再试','CHAT_RECOVERY_NOT_READY'=>'沟通服务暂不可用，请稍后再试','CHAT_DISABLED'=>'订单聊天已暂停','CHAT_SMS_BALANCE_INSUFFICIENT'=>'余额不足，请先充值','CHAT_SMS_PRICE_CHANGED'=>'费用或确认已更新，请重新确认','CHAT_SMS_INELIGIBLE'=>'对方可能已读或沟通状态变化，未扣费','CHAT_SMS_RATE_LIMIT'=>'该对象近期提醒次数已达限制，请稍后再试','CHAT_SMS_CONFLICT'=>'请先核对上次短信结果','CHAT_SMS_UNAVAILABLE'=>'短信提醒暂不可用','CHAT_SMS_INVALID'=>'短信请求无效','CHAT_GROUP_FULL'=>'协作群已满，请联系邀请人调整人数或等待成员退出','CHAT_VERSION_CONFLICT'=>'群信息已变化，请刷新后重试','CHAT_INVITE_INVALID'=>'分享邀请已失效，请联系邀请人重新生成','CHAT_INVITE_REGENERATE_REQUIRED'=>'请联系邀请人重新生成分享后再加入'];
                $this->fail($messages[$exception->getMessage()]??'当前操作不可用，请刷新后重试',$exception->getMessage(),$status);return;
            }
            $this->fail($status === 400 ? '展示范围无效' : ($status === 401 ? '登录状态已失效，请重新进入' : '聊天身份暂不可用，请稍后重试'),
                $status === 400 ? 'CHAT_INVALID_REQUEST' : ($status === 401 ? 'CHAT_UNAUTHORIZED' : ($status===403?'CHAT_FORBIDDEN':'CHAT_DEPENDENCY_UNAVAILABLE')), $status);
        }
    }
    private function recordCredentialLatency(int $started):void
    {
        if($started<=0)return;
        $elapsed=max(0,(hrtime(true)-$started)/1000000);
        (new ChatOperationalTelemetry())->record('credential_latency_ms',$elapsed);
    }

    private function recordClientTelemetry(string $token):void
    {
        if((string)\app_config('app.env','production')!=='testing')$this->fail('接口未开放','CHAT_ROUTE_NOT_FOUND',404);
        $session=$this->db->fetchValue("SELECT s.id FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
            WHERE s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
            AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0
            AND COALESCE(s.test_instance_id,0)=0 AND s.test_actor_expires_at IS NULL LIMIT 1",['hash'=>hash('sha256',$token)]);
        if(!$session)$this->fail('请重新登录','CHAT_UNAUTHORIZED',401);
        $raw=file_get_contents('php://input',false,null,0,1025);$body=is_string($raw)&&strlen($raw)<=1024?json_decode($raw,true):null;
        if(!is_array($body))$this->fail('请求格式不正确','CHAT_INVALID_REQUEST',400);
        $keys=array_keys($body);sort($keys);
        if($keys!==['metric','value_ms']||($body['metric']??'')!=='lockscreen_recovery_ms'||!is_numeric($body['value_ms']??null))
            $this->fail('请求格式不正确','CHAT_INVALID_REQUEST',400);
        $value=(float)$body['value_ms'];
        if(!is_finite($value)||$value<0||$value>120000)$this->fail('请求格式不正确','CHAT_INVALID_REQUEST',400);
        (new ChatOperationalTelemetry())->record('lockscreen_recovery_ms',$value);
        \json_response(['ok'=>true,'data'=>['recorded'=>true]]);
    }

    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id(),
            'retryable'=>$status === 503,'server_time'=>gmdate('Y-m-d\TH:i:s\Z')], $status);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Chat\ChatCredentialIssuer;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Api\ApiModule;

final class ChatCredentialApiModule implements ApiModule
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function supports(string $route): bool { return str_starts_with($route,'chat.sms.') || str_starts_with($route,'chat.collaboration.') || in_array($route,['chat.credentials','chat.audit.credentials','chat.participants'],true); }
    public function requiresContentSecurity(string $route): bool { return false; }
    public function dispatch(string $route): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            header('Allow: POST');
            $this->fail('请求方式不正确', 'CHAT_METHOD_NOT_ALLOWED', 405);
        }
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer ([A-Fa-f0-9]{64})$/D', $authorization, $match) !== 1) $this->fail('请重新登录', 'CHAT_UNAUTHORIZED', 401);
        try {
            $issuer = new ChatCredentialIssuer($this->db, (array)\app_config('chat', []), (string)\app_config('app.env', 'production'));
            if(str_starts_with($route,'chat.sms.')){
                header('Cache-Control: private, no-store');$identity=$issuer->exchangeMini($match[1]);$raw=file_get_contents('php://input',false,null,0,8193);
                $body=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true):null;
                if(!is_array($body))throw new RuntimeException('CHAT_SMS_INVALID',400);
                $service=(new \WorkerAdmin\Foundation\MessagingApplicationContainer($this->db))->chatNudges();
                $actor=(string)$identity['actor_id'];$action=substr($route,9);
                if($action==='quote'){
                    $data=$service->quote($actor,$body);
                    if($data['items']){
                        $names=[];foreach(array_chunk($data['items'],50) as $chunk){
                            $profiles=$issuer->participantsMini($match[1],[['conversation_id'=>$body['conversation_id'],'policy_version'=>$chunk[0]['policy_version'],'sender_ids'=>array_column($chunk,'recipient_id')]]);
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
                $identity=$issuer->exchangeMini($match[1]);$raw=file_get_contents('php://input',false,null,0,8193);
                $body=is_string($raw)&&strlen($raw)<=8192?json_decode($raw,true):null;
                if(!is_array($body))throw new RuntimeException('CHAT_INVALID_REQUEST',400);
                $data=(new \WorkerAdmin\Application\Chat\ChatCollaboration($this->db,(string)$identity['environment'],(string)\app_config('app.key','')))
                    ->execute((string)$identity['actor_id'],hash('sha256',$match[1]),substr($route,19),$body);
                if(isset($data['poster_path'])){
                    $code=(new \WorkerAdmin\Services\MiniProgramCodeService())->generate((int)$data['poster_order_id'],$data['poster_type'],$data['poster_path']);
                    $data=['code_image'=>$code['data_uri'],'expires_at'=>$data['expires_at'],'title'=>$data['poster_title']];
                }
            }elseif($route==='chat.participants'){
                header('Cache-Control: private, no-store');
                $raw=file_get_contents('php://input',false,null,0,32769);
                $body=is_string($raw)&&strlen($raw)<=32768?json_decode($raw,true):null;
                if(!is_array($body)||array_keys($body)!==['conversations']||!is_array($body['conversations']))throw new RuntimeException('展示范围无效',400);
                $data=$issuer->participantsMini($match[1],$body['conversations']);
            }else $data=$issuer->exchangeMini($match[1],$route==='chat.audit.credentials');
            \json_response(['ok'=>true,'data'=>$data]);
        } catch (Throwable $exception) {
            // Deliberately don't pass the exception/SQL/token to the global API logger.
            $status = $exception instanceof RuntimeException && in_array($exception->getCode(),[400,401,403,404,409,429],true) ? $exception->getCode() : 503;
            if($exception instanceof RuntimeException && str_starts_with($exception->getMessage(),'CHAT_')){
                $messages=['CHAT_SMS_BALANCE_INSUFFICIENT'=>'余额不足，请先充值','CHAT_SMS_PRICE_CHANGED'=>'费用或确认已更新，请重新确认','CHAT_SMS_INELIGIBLE'=>'对方可能已读或沟通状态变化，未扣费','CHAT_SMS_RATE_LIMIT'=>'该对象近期已收到提醒，请稍后再试','CHAT_SMS_CONFLICT'=>'请先核对上次短信结果','CHAT_SMS_UNAVAILABLE'=>'短信提醒暂不可用','CHAT_SMS_INVALID'=>'短信请求无效','CHAT_GROUP_FULL'=>'协作群已满，请联系邀请人调整人数或等待成员退出','CHAT_VERSION_CONFLICT'=>'群信息已变化，请刷新后重试','CHAT_INVITE_INVALID'=>'分享邀请已失效，请联系邀请人重新生成','CHAT_INVITE_REGENERATE_REQUIRED'=>'请联系邀请人重新生成分享后再加入'];
                $this->fail($messages[$exception->getMessage()]??'当前操作不可用，请刷新后重试',$exception->getMessage(),$status);return;
            }
            $this->fail($status === 400 ? '展示范围无效' : ($status === 401 ? '登录状态已失效，请重新进入' : '聊天身份暂不可用，请稍后重试'),
                $status === 400 ? 'CHAT_INVALID_REQUEST' : ($status === 401 ? 'CHAT_UNAUTHORIZED' : ($status===403?'CHAT_FORBIDDEN':'CHAT_DEPENDENCY_UNAVAILABLE')), $status);
        }
    }
    private function fail(string $message, string $code, int $status): void
    {
        \json_response(['ok'=>false,'code'=>$code,'message'=>$message,'request_id'=>\request_id(),
            'retryable'=>$status === 503,'server_time'=>gmdate('Y-m-d\TH:i:s\Z')], $status);
    }
}

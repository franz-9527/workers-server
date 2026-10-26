<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use RuntimeException;
use WorkerAdmin\Core\Database;
/** Internal shared key is independent of access-token signing and channel keys. */
final class ChatInternalAuth
{
    private Database $db; private string $env; private string $kid; private string $key;
    public function __construct(Database $db,string $env,string $kid,string $key)
    {
        if (!in_array($env,['production','development','test'],true) || preg_match('/^[a-z0-9_-]{1,32}$/D',$kid)!==1 || strlen($key)!==32) throw new RuntimeException('CHAT_INTERNAL_CONFIG');
        $this->db=$db;$this->env=$env;$this->kid=$kid;$this->key=$key;
    }
    public static function configured(Database $db):self
    {
        $env=['testing'=>'test','development'=>'development','production'=>'production'][(string)\app_config('app.env','')]??'';
        $name=(string)\app_config('chat.internal_key_env','');
        if(preg_match('/^CHAT_[A-Z0-9_]{1,60}$/D',$name)!==1)throw new RuntimeException('CHAT_INTERNAL_CONFIG');
        $encoded=getenv($name);$key=is_string($encoded)?base64_decode($encoded,true):false;
        if(!is_string($key)||base64_encode($key)!==$encoded)throw new RuntimeException('CHAT_INTERNAL_CONFIG');
        return new self($db,$env,(string)\app_config('chat.internal_key_id',''),$key);
    }
    private function canonical(string $uri,string $body,string $stamp,string $nonce):string
    {return implode("\n",['chat-internal-v1','POST',$uri,hash('sha256',$body),$stamp,$nonce,$this->env,$this->kid]);}
    public function sign(string $uri,string $body):array
    {
        $stamp=(string)time();$nonce=bin2hex(random_bytes(32));
        return ['X-Chat-Environment: '.$this->env,'X-Chat-Key-ID: '.$this->kid,'X-Chat-Timestamp: '.$stamp,'X-Chat-Nonce: '.$nonce,
            'X-Chat-Signature: '.hash_hmac('sha256',$this->canonical($uri,$body,$stamp,$nonce),$this->key)];
    }
    public function verify(string $uri,string $body,array $headers):void
    {
        if(!in_array($uri,['/chat-internal.php','/chat-calls.php'],true)||strlen($body)>8192)throw new RuntimeException('CHAT_INTERNAL_AUTH');
        $get=static fn(string $key):string=>(string)($headers[$key]??'');
        $stamp=$get('timestamp');$nonce=$get('nonce');$signature=$get('signature');
        if($get('environment')!==$this->env||$get('key-id')!==$this->kid||preg_match('/^[1-9][0-9]{9}$/D',$stamp)!==1||abs(time()-(int)$stamp)>30
            ||preg_match('/^[a-f0-9]{64}$/D',$nonce)!==1||preg_match('/^[a-f0-9]{64}$/D',$signature)!==1
            ||!hash_equals(hash_hmac('sha256',$this->canonical($uri,$body,$stamp,$nonce),$this->key),$signature))throw new RuntimeException('CHAT_INTERNAL_AUTH');
        $insert=$this->db->execute('INSERT IGNORE INTO chat_internal_nonces(nonce_hash,expires_at) VALUES(:hash,UTC_TIMESTAMP()+INTERVAL 61 SECOND)',['hash'=>hash('sha256',$this->kid.':'.$nonce)]);
        if($insert!==1)throw new RuntimeException('CHAT_INTERNAL_AUTH');
    }
    public function eligible(string $operation):bool
    {
        $origin=rtrim((string)\app_config('chat.internal_go_origin',''),'/');$url=parse_url($origin);
        if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!empty($url['path']))throw new RuntimeException('CHAT_ELIGIBILITY_UNAVAILABLE');
        $uri='/chat/internal/v1/reminders/eligible';$body=json_encode(['operation_id'=>$operation],JSON_THROW_ON_ERROR);
        $response='';$ch=curl_init($origin.$uri);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$this->sign($uri,$body)),CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>1024)return 0;$response.=$chunk;return strlen($chunk);},CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>3000,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $ok=curl_exec($ch);$raw=$ok===false?false:$response;$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($status!==200||!is_string($raw)||strlen($raw)>1024)throw new RuntimeException('CHAT_ELIGIBILITY_UNAVAILABLE');
        $result=json_decode($raw,true);if(!is_array($result)||!isset($result['eligible'])||!is_bool($result['eligible']))throw new RuntimeException('CHAT_ELIGIBILITY_UNAVAILABLE');return $result['eligible'];
    }
    public function confirmCallStopped(string $call,string $epoch,string $proof):void
    {
        $origin=rtrim((string)\app_config('chat.internal_go_origin',''),'/');$url=parse_url($origin);
        if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!empty($url['path']))throw new RuntimeException('通话内部连接未配置');
        $uri='/chat/internal/v1/calls/stop-confirmed';$body=json_encode(['call_id'=>$call,'attempt_epoch'=>$epoch,'evidence_hash'=>$proof],JSON_THROW_ON_ERROR);
        $ch=curl_init($origin.$uri);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$this->sign($uri,$body)),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>1000,CURLOPT_TIMEOUT_MS=>3000,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        $reply=is_string($raw)&&strlen($raw)<=1024?json_decode($raw,true):null;
        if($status!==200||!is_array($reply)||($reply['ok']??null)!==true)throw new RuntimeException('已记录核验理由，通话状态尚未确认，请刷新后重试；资源仍保留');
    }
}
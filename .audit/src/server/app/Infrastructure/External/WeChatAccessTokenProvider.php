<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;
use Throwable;

/** One cross-process refresh coordinator for every PHP caller; Go never owns AppSecret. */
final class WeChatAccessTokenProvider
{
    private string $environment;
    private string $appId;
    private string $secret;
    private string $directory;
    private ?string $exportDirectory;
    private $request;
    private ?string $rejectionDirectory;

    public function __construct(string $environment,string $appId,#[\SensitiveParameter] string $secret,string $directory,?string $exportDirectory=null,?callable $request=null,?string $rejectionDirectory=null)
    {
        if(!in_array($environment,['production','development','test'],true)||preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$appId)!==1||$secret==='')throw new RuntimeException('微信统一凭证配置不可用');
        $this->environment=$environment;$this->appId=$appId;$this->secret=$secret;
        $this->directory=$directory;$this->exportDirectory=$exportDirectory;$this->request=$request;$this->rejectionDirectory=$rejectionDirectory;
    }

    public static function fromSettings(): self
    {
        $env=(string)\app_config('app.env','production');if($env==='testing')$env='test';
        return new self($env,trim((string)\setting('wechat.miniapp_appid','')),trim((string)\setting('wechat.miniapp_secret','')),
            \base_path('storage/private/wechat-tokens'),\app_config('chat.safety_credential_export_enabled',false)===true?\base_path('storage/private/chat-credentials'):null,null,\app_config('chat.safety_rejection_directory','')?:null);
    }

    public function get(): string
    {
        $this->ensureDirectory($this->directory,0700);
        $cached=$this->read();
        $this->consumeRejection($cached);$cached=$this->read();
        if($this->valid($cached,300)&&$this->exportMatches($cached)){ return $cached['token']; }
        $lock=$this->lock($cached);
        if($lock===null){ return $cached['token']; }
        try{
            $cached=$this->read();
            if($this->valid($cached,300)){ $this->export($cached);return $cached['token']; }
            if(!$this->valid($cached,5))$this->revokeExport();
            $started=time();
            try{
                $response=$this->request!==null?($this->request)($this->appId,$this->secret):$this->requestToken();
                if(!is_array($response)||isset($response['errcode'])&&$response['errcode']!==0
                    ||!is_string($response['access_token']??null)||preg_match('/^[A-Za-z0-9_-]{10,2048}$/D',$response['access_token'])!==1
                    ||!is_int($response['expires_in']??null)||$response['expires_in']<1||$response['expires_in']>7200)throw new RuntimeException('微信统一凭证响应不可用');
                // Never extend a provider TTL with max(600, expires_in).
                $next=['schema_version'=>1,'env'=>$this->environment,'app_id'=>$this->appId,'token'=>$response['access_token'],
                    'issued_at'=>$started,'expires_at'=>$started+$response['expires_in'],'credential_hash'=>$this->credentialHash()];
                if(!$this->valid($next,5))throw new RuntimeException('微信统一凭证剩余有效期不足');
                $this->write($this->cachePath(),$next,0600);
                $this->export($next);
                return $next['token'];
            }catch(Throwable $error){
                // A failed refresh does not invent a new expiry or discard a
                // still-valid credential. Rejected/rotated credentials fail this.
                if($this->valid($cached,5)){ $this->export($cached);return $cached['token']; }
                throw new RuntimeException('微信统一凭证暂不可用');
            }
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    /** Only invalidate the exact generation actually rejected by a caller. */
    public function rejectGeneration(string $generation): bool
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$generation)!==1)return false;
        $this->ensureDirectory($this->directory,0700);$lock=$this->lock(null);
        try{
            $cached=$this->read();
            if(!is_array($cached)||!hash_equals($this->generation($cached['token']),$generation))return false;
            if(is_file($this->cachePath())&&!unlink($this->cachePath()))throw new RuntimeException('微信统一凭证撤销失败');
            if($this->exportDirectory!==null){$this->revokeExport();}
            return true;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function reject(#[\SensitiveParameter] string $usedToken): bool
    {
        return $usedToken!==''&&$this->rejectGeneration($this->generation($usedToken));
    }
    public function generation(#[\SensitiveParameter] string $token): string {return hash('sha256',$this->environment."\n".$this->appId."\n".$token);}
    public function exportPath(): string
    {
        if($this->exportDirectory===null)throw new RuntimeException('聊天凭证导出未启用');
        return $this->exportDirectory.DIRECTORY_SEPARATOR.$this->environment.'-'.hash('sha256',$this->appId).'.json';
    }
    /** Read-only preflight, including permissions and exact current configuration. */
    public function metadata(): array
    {
        $cached=$this->read();
        if(!$this->valid($cached,5)||!$this->exportMatches($cached))throw new RuntimeException('微信聊天导出凭证不可用');
        return ['status'=>'ready','credential_file'=>$this->exportPath(),'env'=>$this->environment,'generation'=>$this->generation($cached['token']),'expires_at'=>$cached['expires_at']];
    }
    private function consumeRejection(?array $cached): void
    {
        if($this->rejectionDirectory===null||$cached===null)return;
        $dir=$this->rejectionDirectory;
        if($dir===''||$dir[0]!=='/'||is_link($dir)||!is_dir($dir)||(fileperms($dir)&0007)!==0)throw new RuntimeException('聊天凭证拒绝目录不可用');
        $generation=$this->generation($cached['token']);
        $path=$dir.'/'.$this->environment.'-'.hash('sha256',$this->appId).'-'.$generation.'.json';
        clearstatcache(true,$path);
        if(!is_file($path))return;
        if(is_link($path)||filesize($path)>512||(fileperms($path)&0027)!==0)throw new RuntimeException('聊天凭证拒绝提示不可用');
        $hint=json_decode((string)file_get_contents($path),true);
        if(!is_array($hint)||($hint['schema_version']??null)!==1||($hint['env']??null)!==$this->environment||($hint['app_id']??null)!==$this->appId||($hint['generation']??null)!==$generation)throw new RuntimeException('聊天凭证拒绝提示不可用');
        // rejectGeneration locks and compares again: an old hint cannot erase a
        // new token installed by another PHP process while we were reading.
        $this->rejectGeneration($generation);
        @unlink($path);
    }
    private function credentialHash(): string {return hash('sha256',$this->environment."\n".$this->appId."\n".$this->secret);}
    private function cachePath(): string {return $this->directory.DIRECTORY_SEPARATOR.hash('sha256',$this->environment."\n".$this->appId).'.json';}
    private function read(): ?array
    {
        $path=$this->cachePath();clearstatcache(true,$path);
        if(is_link($path)||!is_file($path)||filesize($path)>4096)return null;
        $data=json_decode((string)@file_get_contents($path),true);
        return $this->valid($data,0)?$data:null;
    }
    private function valid(#[\SensitiveParameter] $data,int $margin): bool
    {
        return is_array($data)&&($data['schema_version']??null)===1&&($data['env']??null)===$this->environment&&($data['app_id']??null)===$this->appId
            &&is_string($data['token']??null)&&preg_match('/^[A-Za-z0-9_-]{10,2048}$/D',$data['token'])===1
            &&is_int($data['issued_at']??null)&&$data['issued_at']<=time()+5&&is_int($data['expires_at']??null)&&$data['expires_at']>time()+$margin
            &&$data['expires_at']>$data['issued_at']&&$data['expires_at']-$data['issued_at']<=7200
            &&is_string($data['credential_hash']??null)&&hash_equals($this->credentialHash(),$data['credential_hash']);
    }
    private function lock(#[\SensitiveParameter] ?array $fallback)
    {
        $path=$this->cachePath().'.lock';if(is_link($path))throw new RuntimeException('微信统一凭证锁不可用');
        $handle=@fopen($path,'c+');if($handle===false)throw new RuntimeException('微信统一凭证锁不可用');@chmod($path,0600);
        $until=microtime(true)+1.0;
        do{
            if(flock($handle,LOCK_EX|LOCK_NB))return $handle;
            if($this->valid($fallback,5)){fclose($handle);return null;}
            usleep(20000);
        }while(microtime(true)<$until);
        fclose($handle);throw new RuntimeException('微信统一凭证刷新繁忙');
    }
    private function revokeExport(): void
    {
        if($this->exportDirectory===null)return;
        $this->ensureDirectory($this->exportDirectory,0750);
        $this->write($this->exportPath(),['schema_version'=>1,'env'=>$this->environment,'app_id'=>$this->appId,'token'=>'','issued_at'=>time(),'expires_at'=>0,'generation'=>''],0640);
    }
    private function exportMatches(#[\SensitiveParameter] array $cached): bool
    {
        if($this->exportDirectory===null)return true;
        $file=$this->exportPath();if(is_link($file)||!is_file($file)||filesize($file)>4096||(fileperms($file)&0027)!==0||(fileperms(dirname($file))&0022)!==0)return false;
        $data=json_decode((string)@file_get_contents($file),true);
        return is_array($data)&&($data['schema_version']??null)===1&&($data['env']??null)===$this->environment&&($data['app_id']??null)===$this->appId&&($data['token']??null)===$cached['token']&&($data['issued_at']??null)===$cached['issued_at']&&($data['generation']??'')===$this->generation($cached['token'])&&($data['expires_at']??0)===$cached['expires_at'];
    }
    private function export(#[\SensitiveParameter] array $cached): void
    {
        if($this->exportDirectory===null)return;
        $this->ensureDirectory($this->exportDirectory,0750);
        $data=['schema_version'=>1,'env'=>$this->environment,'app_id'=>$this->appId,'token'=>$cached['token'],
            'issued_at'=>$cached['issued_at'],'expires_at'=>$cached['expires_at'],'generation'=>$this->generation($cached['token'])];
        $this->write($this->exportPath(),$data,0640);
    }
    private function ensureDirectory(string $directory,int $mode): void
    {
        if(is_link($directory)||!is_dir($directory)&&!@mkdir($directory,$mode,true)&&!is_dir($directory))throw new RuntimeException('微信统一凭证目录不可用');
        clearstatcache(true,$directory);$permissions=fileperms($directory);
        if($permissions===false||($permissions&0022)!==0)throw new RuntimeException('微信统一凭证目录权限过宽');
    }
    private function write(string $path,#[\SensitiveParameter] array $data,int $mode): void
    {
        if(is_link($path))throw new RuntimeException('微信统一凭证文件不可用');
        $temporary=dirname($path).DIRECTORY_SEPARATOR.'.token-'.bin2hex(random_bytes(8));
        $handle=@fopen($temporary,'x');if($handle===false)throw new RuntimeException('微信统一凭证保存失败');
        try{
            if(!chmod($temporary,$mode))throw new RuntimeException('微信统一凭证权限设置失败');
            $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if(fwrite($handle,$json)!==strlen($json)||!fflush($handle))throw new RuntimeException('微信统一凭证保存失败');
            fclose($handle);$handle=null;
            if(!rename($temporary,$path))throw new RuntimeException('微信统一凭证保存失败');
        }finally{if(is_resource($handle))fclose($handle);if(is_file($temporary))@unlink($temporary);}
    }
    private function requestToken(): array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('微信统一凭证需要cURL');
        $url='https://api.weixin.qq.com/cgi-bin/token?'.http_build_query(['grant_type'=>'client_credential','appid'=>$this->appId,'secret'=>$this->secret]);
        $curl=curl_init($url);if($curl===false)throw new RuntimeException('微信统一凭证暂不可用');$raw='';
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>4096)return 0;$raw.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($ok===false||$status!==200)throw new RuntimeException('微信统一凭证暂不可用');
        $data=json_decode($raw,true);if(!is_array($data))throw new RuntimeException('微信统一凭证响应不可用');return $data;
    }
}


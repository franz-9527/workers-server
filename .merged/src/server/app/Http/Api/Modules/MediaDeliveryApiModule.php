<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Media\MediaOperations;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Media\MediaOperationException;
use WorkerAdmin\Foundation\MediaComposition;
use WorkerAdmin\Http\Api\ApiModule;

/** 私有签名图片与公开师傅图片输出。 */
final class MediaDeliveryApiModule implements ApiModule
{
    private MediaOperations $operations;
    private ObjectStorage $storage;

    public function __construct(Database $db)
    {
        $services=MediaComposition::build($db);$this->operations=$services['operations'];$this->storage=$services['storage'];
    }

    public function supports(string $route): bool { return in_array($route,['media.order','media.worker'],true); }
    public function requiresContentSecurity(string $route): bool { return false; }

    public function dispatch(string $route): void
    {
        if(!$this->supports($route))throw new RuntimeException('接口不存在');
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='GET'){$this->plain('请求方式不正确',405);}
        if($route==='media.order'){$this->privateMedia();return;}
        $this->workerMedia();
    }

    private function privateMedia(): void
    {
        $id=trim((string)($_GET['id']??''));$token=$this->decodeToken((string)($_GET['access_token']??''));
        if(!$token){$this->plain('图片链接已失效',403);}
        try{$media=$this->operations->privateAsset($id,$token);}catch(MediaOperationException $e){$this->plain($e->getMessage(),(int)$e->getCode());return;}
        $this->deliver($media,false);
    }

    private function workerMedia(): void
    {
        $id=trim((string)($_GET['id']??''));
        try{$media=$this->operations->publicWorkerAsset($id);}catch(MediaOperationException $e){$this->plain($e->getMessage(),(int)$e->getCode());return;}
        $this->deliver($media,true,$id);
    }

    /** @param array<string,mixed> $media */
    private function deliver(array $media,bool $public,string $etagSeed=''): void
    {
        $mime=(string)($media['mime_type']??'application/octet-stream');$key=(string)($media['storage_key']??'');
        if((string)($media['storage_disk']??'private_local')==='qiniu'){
            try{
                if($this->storage->usesHttp()){
                    $raw=$this->storage->read($key,900);
                    header('Content-Type: '.$mime);header('Content-Length: '.strlen($raw));
                    header('Cache-Control: '.($public?'public, max-age=86400':'private, no-store, max-age=0'));
                    header('Access-Control-Allow-Origin: *');header('Cross-Origin-Resource-Policy: cross-origin');header('X-Content-Type-Options: nosniff');
                    echo $raw;exit;
                }
                header('Access-Control-Allow-Origin: *');
                header('Cache-Control: '.($public?'public, max-age=86400':'private, no-store, max-age=0'));
                header('Location: '.$this->storage->downloadUrl($key,$public?3600:600),true,302);exit;
            }catch(Throwable $e){$this->plain('七牛云图片暂时无法读取：'.$e->getMessage(),502);}
        }

        $base=realpath(\base_path('storage/private'));$storageKey=str_replace('\\','/',trim($key));
        if(strpos($storageKey,'storage/private/')===0)$storageKey=substr($storageKey,16);
        $path=realpath(\base_path('storage/private/'.$storageKey));
        if(!$base||!$path||strpos($path,$base.DIRECTORY_SEPARATOR)!==0||!is_file($path)){
            if(!$public){$meta=json_decode((string)($media['metadata_json']??''),true);if(is_array($meta)&&!empty($meta['demo'])){$this->demoPlaceholder();}}
            $this->plain('图片不存在',404);
        }
        $size=(int)filesize($path);$modified=(int)filemtime($path);
        header('Content-Type: '.$mime);header('Access-Control-Allow-Origin: *');header('Cross-Origin-Resource-Policy: cross-origin');header('X-Content-Type-Options: nosniff');
        if($public){
            $etag='"'.hash('sha256',$etagSeed.'|'.$size.'|'.$modified).'"';
            header('Cache-Control: public, max-age=31536000, immutable');header('ETag: '.$etag);header('Last-Modified: '.gmdate('D, d M Y H:i:s',$modified).' GMT');
            if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
        }else{header('Cache-Control: private, no-store, max-age=0');}
        header('Content-Length: '.(string)$size);readfile($path);exit;
    }

    /** @return array<string,mixed>|null */
    private function decodeToken(string $token): ?array
    {
        if($token===''||strlen($token)>4096)return null;$parts=explode('.',$token);
        if(count($parts)!==2||preg_match('/^[A-Za-z0-9_-]+$/',$parts[0])!==1||preg_match('/^[a-f0-9]{64}$/',$parts[1])!==1)return null;
        if(!hash_equals(hash_hmac('sha256',$parts[0],(string)\app_config('app.key','')),$parts[1]))return null;
        $encoded=strtr($parts[0],'-_','+/');$padding=strlen($encoded)%4;if($padding>0)$encoded.=str_repeat('=',4-$padding);$decoded=base64_decode($encoded,true);if(!is_string($decoded))return null;
        $payload=json_decode($decoded,true);if(!is_array($payload)||(int)($payload['v']??0)!==2||($payload['scope']??'')!=='media'||(int)($payload['expires']??0)<time())return null;return $payload;
    }

    private function demoPlaceholder(): void
    {
        // 演示数据缺少物理文件时维持可渲染占位图，不影响真实媒体权限。
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',true);
        if(!is_string($png)){$this->plain('图片不存在',404);}
        header('Content-Type: image/png');header('Access-Control-Allow-Origin: *');header('Cross-Origin-Resource-Policy: cross-origin');header('Cache-Control: private, no-store, max-age=0');header('Content-Length: '.strlen($png));header('X-Content-Type-Options: nosniff');echo $png;exit;
    }

    private function plain(string $message,int $status): void { http_response_code($status);exit($message); }
}

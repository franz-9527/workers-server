<?php
declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;

/** Bounded cache for already authorized immutable ordinary images. */
final class QiniuMediaReader
{
    private const READ_LIMIT=20*1024*1024;
    private const SLOT_LIMIT=8*1024*1024;
    private const SLOTS=16;
    private const TTL=300;

    public static function read(string $url,string $sha='',string $identity=''):string
    {
        $sha=strtolower($sha);
        if(preg_match('/^[0-9a-f]{64}$/D',$sha)!==1){$sha='';$identity='';}
        if($identity===''||($root=self::root(true))===null)return self::fetch($url,$sha);
        $id=hash('sha256',$identity."\n".$sha);
        $slot=hexdec(substr($id,0,2))%self::SLOTS;
        $lock=self::lock($root,$slot,5.0);
        if($lock===null)throw new RuntimeException('图片正在加载，请稍后重试。');
        try{
            $path=$root.'/slot-'.$slot.'.bin';
            $hit=self::cached($path,$id,$sha);
            if($hit!==null)return $hit;
            $raw=self::fetch($url,$sha);
            if(strlen($raw)<=self::SLOT_LIMIT)self::save($root,$slot,$id,$raw);
            return $raw;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function fetch(string $url,string $sha):string
    {
        if(!function_exists('curl_init'))throw new RuntimeException('服务器 PHP 未启用 cURL，无法读取七牛云图片。');
        $month=QiniuBudget::reserve('get',self::READ_LIMIT+4096);
        $raw='';$curl=curl_init($url);
        if($curl===false)throw new RuntimeException('无法初始化七牛云图片读取请求。');
        curl_setopt_array($curl,[CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{
                if(strlen($raw)+strlen($chunk)>self::READ_LIMIT)return 0;
                $raw.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($ok===false||$raw===''||$status!==200)throw new RuntimeException('七牛云回源失败（HTTP '.$status.'）。');
        QiniuBudget::releaseDownload($month,self::READ_LIMIT-strlen($raw));
        if($sha!==''&&!hash_equals($sha,hash('sha256',$raw)))throw new RuntimeException('七牛云图片完整性校验失败。');
        return $raw;
    }

    private static function root(bool $create):?string
    {
        $parent=realpath(\base_path('storage/private'));
        if($parent===false)return null;
        $root=$parent.'/qiniu-media-cache';
        if($create&&!is_dir($root))@mkdir($root,0700);
        clearstatcache(true,$root);
        if(is_link($root)||!is_dir($root)||realpath($root)!==$root||((int)fileperms($root)&0077)!==0)return null;
        return $root;
    }
    private static function lock(string $root,int $slot,float $seconds)
    {
        $path=$root.'/slot-'.$slot.'.lock';
        if(is_link($path))return null;
        $file=@fopen($path,'c+b');
        if($file===false)return null;
        @chmod($path,0600);$deadline=microtime(true)+$seconds;
        do{
            if(flock($file,LOCK_EX|LOCK_NB))return $file;
            if($seconds<=0)break;
            usleep(20000);
        }while(microtime(true)<$deadline);
        fclose($file);return null;
    }
    private static function cached(string $path,string $id,string $sha):?string
    {
        clearstatcache(true,$path);
        if(is_link($path)||!is_file($path)||(int)filesize($path)>self::SLOT_LIMIT+128)return null;
        $f=@fopen($path,'rb');if($f===false)return null;
        try{
            $key=fgets($f,80);$expiry=fgets($f,32);
            if($key!==$id."\n"||!is_string($expiry)||!ctype_digit(trim($expiry))||(int)$expiry<=time())return null;
            $raw=stream_get_contents($f,self::SLOT_LIMIT+1);
            return is_string($raw)&&$raw!==''&&strlen($raw)<=self::SLOT_LIMIT&&hash_equals($sha,hash('sha256',$raw))?$raw:null;
        }finally{fclose($f);}
    }
    private static function save(string $root,int $slot,string $id,string $raw):void
    {
        $path=$root.'/slot-'.$slot.'.bin';$tmp=$root.'/slot-'.$slot.'.tmp';
        if(is_link($path)||is_link($tmp))return;
        $f=@fopen($tmp,'wb');if($f===false)return;
        try{
            @chmod($tmp,0600);$header=$id."\n".(time()+self::TTL)."\n";
            $ok=@fwrite($f,$header)===strlen($header)&&@fwrite($f,$raw)===strlen($raw)&&@fflush($f);
        }finally{fclose($f);}
        if($ok)@rename($tmp,$path);
        if(is_file($tmp)&&!is_link($tmp))@unlink($tmp);
    }
    /** Existing media-promotion scheduler invokes this even if cloud storage is paused. */
    public static function prune():void
    {
        $root=self::root(false);if($root===null)return;
        for($slot=0;$slot<self::SLOTS;$slot++){
            $lock=self::lock($root,$slot,0);
            if($lock===null)continue;
            try{
                $path=$root.'/slot-'.$slot.'.bin';$tmp=$root.'/slot-'.$slot.'.tmp';
                if(is_file($tmp)&&!is_link($tmp))@unlink($tmp);
                if(!is_file($path)||is_link($path))continue;
                $f=@fopen($path,'rb');if($f===false)continue;
                $id=fgets($f,80);$expiry=fgets($f,32);fclose($f);
                if(!is_string($id)||preg_match('/^[0-9a-f]{64}\n$/D',$id)!==1||
                    !is_string($expiry)||!ctype_digit(trim($expiry))||(int)$expiry<=time())@unlink($path);
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }
    }
}

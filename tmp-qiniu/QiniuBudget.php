<?php
declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;

/**
 * Local conservative admission ledger, not a supplier bill.
 * PHP and Go share state.json and a stable sidecar flock in the site private directory.
 */
final class QiniuBudget
{
    private const GIB=1073741824;
    public static function enabled():bool {return filter_var(\setting('qiniu.budget_guard',false),FILTER_VALIDATE_BOOLEAN);}
    public static function assertOrigin(string $origin):void
    {
        if(!self::enabled())return;
        if(!filter_var(\setting('qiniu.private_bucket',true),FILTER_VALIDATE_BOOLEAN)||parse_url($origin,PHP_URL_SCHEME)!=='http')
            throw new RuntimeException('七牛本地预算保护要求私有空间及已确认享有免费额度的 HTTP CDN 域名。');
        self::limits();
    }
    private static function environment():string
    {
        $env=(string)\app_config('app.env','production');
        return match($env){'testing'=>'test','production'=>'production',default=>throw new RuntimeException('当前环境未分配七牛免费预算。')};
    }
    private static function limits():array
    {
        $test=self::environment()==='test';
        return ['download_bytes'=>($test?2:6)*self::GIB,'get_requests'=>$test?200000:600000,
            'write_requests'=>$test?20000:60000,'upload_bytes_total'=>($test?2:6)*self::GIB];
    }
    private static function month():string {return gmdate('Y-m',time()+28800);}
    private static function emptyState():array
    {
        return ['version'=>1,'environment'=>self::environment(),'month'=>self::month(),
            'download_bytes'=>0,'get_requests'=>0,'write_requests'=>0,'upload_bytes_total'=>0];
    }
    private static function root():string
    {
        $parent=realpath(\base_path('storage/private'));
        if($parent===false||!is_dir($parent))throw new RuntimeException('七牛预算私有目录不可用。');
        $root=$parent.'/qiniu-budget';
        if(!is_dir($root)&&!@mkdir($root,0700)&&!is_dir($root))throw new RuntimeException('七牛预算目录不可写。');
        clearstatcache(true,$root);
        if(is_link($root)||realpath($root)!==$root||((int)fileperms($root)&0077)!==0)throw new RuntimeException('七牛预算目录权限不安全。');
        return $root;
    }
    private static function locked(callable $fn):array
    {
        $root=self::root();$lockPath=$root.'/state.lock';$path=$root.'/state.json';
        if(is_link($lockPath)||is_link($path))throw new RuntimeException('七牛预算记录路径不安全。');
        $lock=@fopen($lockPath,'c+b');
        if($lock===false)throw new RuntimeException('七牛预算锁不可用。');
        @chmod($lockPath,0600);
        $held=false;$tmp=null;
        try{
            $deadline=microtime(true)+1;
            do{if(flock($lock,LOCK_EX|LOCK_NB)){$held=true;break;}usleep(10000);}while(microtime(true)<$deadline);
            if(!$held)throw new RuntimeException('七牛预算记录繁忙，已暂停本次云请求。');
            $state=self::emptyState();
            rewind($lock);$marker=stream_get_contents($lock,32);
            if($marker!==''&&$marker!=="initialized\n")throw new RuntimeException('七牛预算锁标记损坏。');
            if(!file_exists($path)&&$marker!== '')throw new RuntimeException('七牛预算记录丢失；禁止自动清零。');
            if(file_exists($path)){
                $raw=@file_get_contents($path,false,null,0,4097);
                if(!is_string($raw)||strlen($raw)>4096)throw new RuntimeException('七牛预算记录无法读取。');
                try{$old=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('七牛预算记录损坏；禁止自动清零。');}
                if(!is_array($old)||($old['version']??null)!==1||($old['environment']??null)!==$state['environment']||
                    !is_string($old['month']??null)||preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$old['month'])!==1||$old['month']>$state['month'])
                    throw new RuntimeException('七牛预算记录版本、环境或月份不正确。');
                foreach(self::limits() as $key=>$limit)if(!isset($old[$key])||!is_int($old[$key])||$old[$key]<0||$old[$key]>$limit)
                    throw new RuntimeException('七牛预算记录数值异常；禁止自动清零。');
                if($old['month']===$state['month'])$state=$old;
                else $state['upload_bytes_total']=$old['upload_bytes_total'];
            }
            [$next,$write]=$fn($state);
            if($write){
                $json=json_encode($next,JSON_THROW_ON_ERROR);
                $tmp=$root.'/.state-'.bin2hex(random_bytes(12));$out=@fopen($tmp,'xb');
                if($out===false)throw new RuntimeException('七牛预算记录无法写入。');
                try{
                    @chmod($tmp,0600);
                    if(fwrite($out,$json)!==strlen($json)||!fflush($out)||!fsync($out))throw new RuntimeException('七牛预算记录无法持久化。');
                }finally{fclose($out);}
                if(!@rename($tmp,$path))throw new RuntimeException('七牛预算记录更新失败。');
                $tmp=null;
                if($marker===''){
                    rewind($lock);
                    if(fwrite($lock,"initialized\n")!==12||!fflush($lock)||!fsync($lock))throw new RuntimeException('七牛预算初始化标记无法持久化。');
                }
            }
            return $next;
        }finally{
            if($tmp!==null)@unlink($tmp);
            if($held)flock($lock,LOCK_UN);
            fclose($lock);
        }
    }
    /** Reserve before a request or upload grant. Failed/unknown responses keep their reservation. */
    public static function reserve(string $kind,int $bytes=0):?string
    {
        if(!self::enabled())return null;
        $limits=self::limits();
        if($bytes<0||$bytes>20*1024*1024+4096||!in_array($kind,['get','upload','write','cleanup'],true))throw new RuntimeException('七牛预算请求参数错误。');
        $state=self::locked(static function(array $s)use($kind,$bytes,$limits):array{
            if($kind==='get'){$s['download_bytes']+=$bytes;$s['get_requests']++;}
            else{
                $s['write_requests']++;
                if($kind==='upload')$s['upload_bytes_total']+=$bytes;
                // Reserve 10% of write allowance for deletion/cleanup.
                if($kind!=='cleanup'&&$s['write_requests']>intdiv($limits['write_requests']*9,10))throw new RuntimeException('七牛普通写请求预算已达保护线。');
            }
            foreach($limits as $key=>$limit)if($s[$key]>$limit)throw new RuntimeException('七牛本站预算已达保护线，本次云请求已阻止。');
            return [$s,true];
        });
        return $state['month'];
    }
    public static function releaseDownload(?string $month,int $unused):void
    {
        if($month===null||$unused<=0)return;
        try{self::locked(static function(array $s)use($month,$unused):array{
            if($s['month']!==$month||$unused>$s['download_bytes'])return [$s,false];
            $s['download_bytes']-=$unused;return [$s,true];
        });}catch(\Throwable $e){/* conservative: leave reservation charged */}
    }
    public static function status():array
    {
        if(!self::enabled())return ['enabled'=>false];
        $state=self::locked(static fn(array $s):array=>[$s,false]);
        return ['enabled'=>true,'state'=>$state,'limits'=>self::limits()];
    }
}

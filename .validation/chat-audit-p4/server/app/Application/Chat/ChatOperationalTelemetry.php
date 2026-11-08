<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

final class ChatOperationalTelemetry
{
    private const LIMITS=[
        'credential_latency_ms'=>60000,
        'discover_scanned_orders'=>100,
        'discover_synchronized_orders'=>100,
        'lockscreen_recovery_ms'=>120000,
    ];
    private string $path;

    public function __construct(?string $path=null)
    {
        $this->path=$path??\base_path('storage/private/chat-observability.json');
    }

    public function record(string $metric,float $value):void
    {
        if(!isset(self::LIMITS[$metric])||!is_finite($value)||$value<0||$value>self::LIMITS[$metric])return;
        $dir=dirname($this->path);
        if((!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))||is_link($dir))return;
        $file=@fopen($this->path,'c+b');if(!is_resource($file))return;
        try{
            if(!@flock($file,LOCK_EX))return;
            $state=$this->readLocked($file);
            $row=is_array($state['metrics'][$metric]??null)?$state['metrics'][$metric]:[];
            $count=min(1000000000,max(0,(int)($row['count']??0))+1);
            $sum=max(0.0,(float)($row['sum']??0))+$value;
            $state['schema']=1;$state['updated_at']=gmdate('c');
            $state['metrics'][$metric]=[
                'count'=>$count,'sum'=>$sum,'last'=>$value,'max'=>max($value,(float)($row['max']??0)),'last_at'=>gmdate('c'),
            ];
            $raw=json_encode($state,JSON_UNESCAPED_SLASHES);
            if(!is_string($raw)||strlen($raw)>65536)return;
            rewind($file);if(!@ftruncate($file,0))return;
            if(@fwrite($file,$raw)!==strlen($raw))return;
            @fflush($file);@chmod($this->path,0600);
        }finally{@flock($file,LOCK_UN);@fclose($file);}
    }

    public function snapshot():array
    {
        $file=@fopen($this->path,'rb');if(!is_resource($file))return ['status'=>'empty','updated_at'=>'','metrics'=>[]];
        try{
            if(!@flock($file,LOCK_SH))return ['status'=>'unavailable','updated_at'=>'','metrics'=>[]];
            $state=$this->readLocked($file);$metrics=[];
            foreach(self::LIMITS as $name=>$limit){
                $row=$state['metrics'][$name]??null;if(!is_array($row))continue;
                $count=max(0,(int)($row['count']??0));if($count<1)continue;
                $sum=max(0.0,(float)($row['sum']??0));$last=max(0.0,(float)($row['last']??0));$max=max(0.0,(float)($row['max']??0));
                $metrics[$name]=['count'=>$count,'last'=>$last,'max'=>$max,'average'=>round($sum/$count,2),'last_at'=>(string)($row['last_at']??'')];
            }
            return ['status'=>$metrics?'ready':'empty','updated_at'=>(string)($state['updated_at']??''),'metrics'=>$metrics];
        }finally{@flock($file,LOCK_UN);@fclose($file);}
    }

    private function readLocked($file):array
    {
        $stat=@fstat($file);if(!is_array($stat)||(int)($stat['size']??0)<1)return ['schema'=>1,'updated_at'=>'','metrics'=>[]];
        if((int)$stat['size']>65536)return ['schema'=>1,'updated_at'=>'','metrics'=>[]];
        rewind($file);$raw=@stream_get_contents($file,65537);
        $state=is_string($raw)?json_decode($raw,true):null;
        return is_array($state)&&($state['schema']??null)===1&&is_array($state['metrics']??null)?$state:['schema'=>1,'updated_at'=>'','metrics'=>[]];
    }
}

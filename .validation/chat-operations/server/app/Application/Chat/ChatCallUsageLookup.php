<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use RuntimeException;

/**
 * Read-only Agora Console usage. Historical seconds are never treated as
 * confirmed entitlement or as a real-time available-quota watermark.
 */
final class ChatCallUsageLookup
{
    public function __construct(private ChatCallCredentials $credentials,private ?\Closure $transport=null) {}
    public function read():array
    {
        $v=$this->credentials->runtime();$app=(string)($v['app_id']??'');
        $id=(string)($v['customer_id']??'');$secret=(string)($v['customer_secret']??'');
        if(preg_match('/^[a-f0-9]{32}$/D',$app)!==1||$id===''||$secret==='')
            throw new RuntimeException('声网 REST 凭据不完整，请先在功能与密钥页配置。');
        $end=new \DateTimeImmutable('yesterday',new \DateTimeZone('UTC'));
        $from=$end->modify('-29 days')->format('Y-m-d');$to=$end->format('Y-m-d');
        $catalog=$this->fetch('/dev/v1/projects',$id,$secret,[200,201]);
        if(!isset($catalog['projects'])||!is_array($catalog['projects'])||count($catalog['projects'])>1000)
            throw new RuntimeException('声网项目列表响应无效。');
        $matches=[];
        foreach($catalog['projects'] as $row) {
            if(!is_array($row)||($row['vendor_key']??null)!==$app)continue;
            $p=$row['id']??'';
            if(!is_string($p)||preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$p)!==1)
                throw new RuntimeException('声网项目标识无效。');
            $matches[$p]=true;
        }
        if(count($matches)!==1)throw new RuntimeException('REST 账号下未找到与声网 App ID 唯一匹配的项目。');
        $project=(string)array_key_first($matches);$totals=[];$errors=[];
        foreach(['default','miniapp'] as $business){
            $path='/dev/v3/usage?'.http_build_query(['project_id'=>$project,'from_date'=>$from,'to_date'=>$to,'business'=>$business],'','&',PHP_QUERY_RFC3986);
            try{$totals[$business]=$this->summarize($this->fetch($path,$id,$secret,[200]),$from,$to);}
            catch(\Throwable $e){$errors[$business]='官方统计暂不可读取，请核对 API 权限与套餐。';}
        }
        if(!$totals)throw new RuntimeException('声网官方用量读取失败，请检查 REST 凭据或接口权限。');
        return ['from'=>$from,'to'=>$to,'business'=>$totals,'errors'=>$errors,
            'checked_at'=>gmdate('Y-m-d H:i:s').' UTC'];
    }
    public function summarize(array $data,string $from,string $to):array
    {
        $fields=['durationAudioAll','durationVideoHd','durationVideoHdp','durationVideo1080P','durationVideo2K','durationVideo4K'];
        if(!isset($data['meta'],$data['usages'])||!is_array($data['meta'])||!is_array($data['usages'])||count($data['usages'])>32)
            throw new RuntimeException('官方统计数据不完整。');
        foreach($fields as $key)if(($data['meta'][$key]['unit']??null)!=='second')
            throw new RuntimeException('声网计量单位未知。');
        $sum=array_fill_keys($fields,0);$seen=[];
        foreach($data['usages'] as $day){
            if(!is_array($day)||!is_string($day['date']??null)||!is_array($day['usage']??null))
                throw new RuntimeException('官方统计日期格式异常。');
            $date=substr($day['date'],0,10);
            if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)!==1||$date<$from||$date>$to||isset($seen[$date]))
                throw new RuntimeException('官方统计日期不匹配。');
            $seen[$date]=true;
            foreach($fields as $key){
                $n=$day['usage'][$key]??null;
                if(!is_int($n)||$n<0||$n>100000000||$sum[$key]>PHP_INT_MAX-$n)
                    throw new RuntimeException('官方统计数值异常。');
                $sum[$key]+=$n;
            }
        }
        return ['days'=>count($seen),'seconds'=>$sum];
    }
    private function fetch(string $path,string $id,string $secret,array $success):array
    {
        $r=$this->transport?($this->transport)($path,$id,$secret):$this->request($path,$id,$secret);
        if(!is_array($r)||!in_array((int)($r['status']??0),$success,true)||!is_string($r['body']??null)||strlen($r['body'])>131072)
            throw new RuntimeException('声网接口连接或响应异常。');
        $json=json_decode($r['body'],true,32);
        if(!is_array($json))throw new RuntimeException('声网响应 JSON 无效。');
        return $json;
    }
    private function request(string $path,string $id,string $secret):array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('缺少 PHP cURL。');
        $curl=curl_init('https://api.agora.io'.$path);$body='';
        curl_setopt_array($curl,[
            CURLOPT_HTTPGET=>true,CURLOPT_USERPWD=>$id.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>131072)return 0;$body.=$chunk;return strlen($chunk);
            },
        ]);
        try{$ok=curl_exec($curl);return ['status'=>$ok===false?0:(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$ok===false?'':$body];}
        finally{curl_close($curl);}
    }
}

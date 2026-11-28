<?php
declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Account-wide, read-only financial snapshot; never a real-time quota counter. */
final class QiniuBillingUsage
{
    public function snapshot(): array
    {
        $ak=trim((string)\setting('qiniu.access_key',''));
        $sk=trim((string)\setting('qiniu.secret_key',''));
        if(!preg_match('/^[A-Za-z0-9_-]{20,128}$/D',$ak)||!preg_match('/^[A-Za-z0-9_-]{20,128}$/D',$sk)||!function_exists('curl_init'))throw new RuntimeException('七牛财务凭据或 PHP cURL 未就绪。');
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai'));
        if((int)$now->format('H')<8){
            if($now->format('d')==='01')throw new RuntimeException('每月1日的财务快照请在北京时间8点后查询。');
            $now=$now->modify('-1 day');
        }
        $date=$now->format('Y-m-d');
        $path='/billing-api/v2/bill/snapshot?date='.$date.'T00:00:00';
        $host='api.qiniu.com';$type='application/x-www-form-urlencoded';$clock=gmdate('Ymd\THis\Z');
        $canonical="GET ".$path."\nHost: ".$host."\nContent-Type: ".$type."\nX-Qiniu-Date: ".$clock."\n\n";
        $token=$ak.':'.strtr(base64_encode(hash_hmac('sha1',$canonical,$sk,true)),'+/','-_');
        $raw='';$ch=curl_init('https://'.$host.$path);
        if($ch===false)throw new RuntimeException('无法初始化七牛财务查询。');
        curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Authorization: Qiniu '.$token,'Content-Type: '.$type,'X-Qiniu-Date: '.$clock],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$raw):int{
                if(strlen($raw)+strlen($chunk)>2*1024*1024)return 0;
                $raw.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false||$status!==200)throw new RuntimeException('七牛财务快照不可用（HTTP '.$status.'）；请核对财务接口权限或在七牛控制台查询。');
        try{$payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\Throwable $e){throw new RuntimeException('七牛财务快照格式不正确。');}
        if(!is_array($payload)||($payload['code']??null)!==0||!is_array($payload['data']['list']??null))throw new RuntimeException('七牛未返回可用的财务快照；不能据此判断剩余额度。');
        return self::summarize($payload['data'],$date);
    }

    public static function summarize(array $data,string $date):array
    {
        $rows=[];$charged=false;$unknown=false;
        // The billing response is authoritative for posted usage and deductions,
        // not for current stock or still-unbilled traffic.
        $limits=['storage'=>10*1073741824,'origin'=>10*1073741824,'http_cn'=>10*1073741824,'http_other'=>10*1073741824,'get'=>1000000,'put_delete'=>100000];
        $used=array_fill_keys(array_keys($limits),null);
        $labels=['storage'=>'标准存储（账单口径）','origin'=>'标准存储 CDN 回源','http_cn'=>'HTTP CDN 中国大陆','http_other'=>'HTTP CDN 其它地区（旧账单合计）','get'=>'标准存储 GET','put_delete'=>'标准存储 PUT / DELETE'];
        $list=$data['list']??null;
        if(!is_array($list)||count($list)>2000)throw new RuntimeException('七牛财务快照明细超出可处理范围。');
        foreach($list as $item){
            if(!is_array($item)){$unknown=true;continue;}
            $product=is_string($item['product']??null)?$item['product']:'';
            if(!in_array($product,['对象存储','CDN加速'],true))continue;
            $name=is_string($item['item']??null)?$item['item']:'';$unit=is_string($item['usage_unit']??null)?$item['usage_unit']:'';
            $raw=$item['total_usage']??null;$coeff=$item['usage_coefficient']??null;$free=$item['package_usage']??null;$money=$item['item_money']??null;
            if(!is_numeric($raw)||!is_numeric($coeff)||(float)$raw<0||(float)$coeff<=0||!is_finite((float)$raw)||!is_finite((float)$coeff)||!is_numeric($money)||!is_finite((float)$money)){$unknown=true;continue;}
            $amount=(float)$money/100000000;$charged=$charged||$amount>0;
            $rows[]=['item'=>$name,'unit'=>$unit,'usage'=>(float)$raw/(float)$coeff,'free'=>is_numeric($free)?(float)$free/(float)$coeff:null,'money'=>$amount,'currency'=>(string)($item['currency']??$data['currency']??'')];
            $bucket=null;
            if($product==='对象存储'&&!preg_match('/低频|归档|智能分层|深度|直读/u',$name)){
                if($unit==='GB'&&preg_match('/^标准存储\s*CDN回源流出流量|^CDN回源流出流量/u',$name))$bucket='origin';
                elseif($unit==='GB'&&preg_match('/^(?:标准存储空间|存储空间)(?:-|－|$)/u',$name))$bucket='storage';
                elseif(in_array($unit,['次','千次','万次'],true)&&preg_match('/^(?:标准存储\s*)?GET请求(?:次数|数)/iu',$name))$bucket='get';
                elseif(in_array($unit,['次','千次','万次'],true)&&preg_match('/^(?:标准存储\s*)?(?:PUT|DELETE|PUT\s*\/\s*DELETE)请求(?:次数|数)/iu',$name))$bucket='put_delete';
            }elseif($product==='CDN加速'&&$unit==='GB'&&preg_match('/^CDN-HTTP(?:\s*流量)?(?:-|－)(中国大陆|其它地区|其他地区|亚洲|亚洲其他|欧洲北美|南美洲|大洋洲与其他)$/u',$name,$m)){
                $region=$m[1];
                if($region==='中国大陆')$bucket='http_cn';
                elseif(in_array($region,['其它地区','其他地区'],true))$bucket='http_other';
                else{
                    $bucket='http_region:'.$region;
                    if(!isset($limits[$bucket])){$limits[$bucket]=10*1073741824;$labels[$bucket]='HTTP CDN '.$region;$used[$bucket]=null;}
                }
            }
            if($bucket!==null)$used[$bucket]=($used[$bucket]??0)+(float)$raw;else $unknown=true;
        }
        $budgets=[];
        foreach($limits as $key=>$limit){
            $n=$used[$key];if($n===null&&$key!=='http_other')$unknown=true;$scale=in_array($key,['get','put_delete'],true)?1:1073741824;
            $budgets[]=['label'=>$labels[$key],'used'=>$n===null?null:$n/$scale,'limit'=>$limit/$scale,'unit'=>$scale===1?'次':'GB',
                'level'=>$n===null?'unknown':($n>=$limit*0.8?'stop':($n>=$limit*0.7?'warn':'observe'))];
        }
        return ['date'=>$date,'previous_month'=>substr($date,-2)==='01','rows'=>$rows,'budgets'=>$budgets,'charged'=>$charged,'unknown'=>$unknown];
    }
}

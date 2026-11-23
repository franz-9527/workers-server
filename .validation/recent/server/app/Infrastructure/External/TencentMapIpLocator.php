<?php
declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

/** Resolve a login IP to a coarse public region. Raw IP never leaves this service projection. */
final class TencentMapIpLocator
{
    public function __construct(private string $key) {$this->key=trim($this->key);}

    /** @return array{nation:string,province:string,city:string,district:string,display:string,detail:string}|null */
    public function locate(string $ip): ?array
    {
        $ip=trim($ip);
        if($this->key===''||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
        $payload=$this->request('https://apis.map.qq.com/ws/location/v1/ip?ip='.rawurlencode($ip).'&key='.rawurlencode($this->key));
        if(!$payload||(int)($payload['status']??-1)!==0)return null;
        $result=is_array($payload['result']??null)?$payload['result']:[];
        $ad=is_array($result['ad_info']??null)?$result['ad_info']:[];
        $nation=$this->safe($ad['nation']??$ad['nation_code']??'');
        $province=$this->safe($ad['province']??'');
        $city=$this->safe($ad['city']??'');
        $district=$this->safe($ad['district']??'');
        $display='';
        if($nation==='中国'||$nation==='中华人民共和国')$display=$province!==''?$province:($city!==''?$city:'中国');
        elseif($nation!=='')$display=$nation.($province!==''?' '.$province:'');
        elseif($province!=='')$display=$province;
        elseif($city!=='')$display=$city;
        if($display==='')return null;
        $detail=trim(implode(' ',array_values(array_filter([$province,$city,$district],static fn(string $v):bool=>$v!==''))));
        if($detail==='')$detail=$display;
        return ['nation'=>$nation,'province'=>$province,'city'=>$city,'district'=>$district,'display'=>mb_substr($display,0,80),'detail'=>mb_substr($detail,0,120)];
    }

    private function safe(mixed $value):string
    {
        $value=trim((string)$value);
        if($value===''||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>64||preg_match('/[\p{C}<>]/u',$value))return '';
        return $value;
    }

    /** @return array<string,mixed>|null */
    private function request(string $url):?array
    {
        $body=null;
        if(function_exists('curl_init')){
            $handle=curl_init($url);
            if($handle!==false){
                curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
                $response=curl_exec($handle);$code=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);curl_close($handle);
                if(is_string($response)&&$code>=200&&$code<300)$body=$response;
            }
        }
        if($body===null&&ini_get('allow_url_fopen')){
            $context=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
            $response=@file_get_contents($url,false,$context);
            if(is_string($response)&&$response!=='')$body=$response;
        }
        if(!is_string($body)||$body==='')return null;
        $decoded=json_decode($body,true);
        return is_array($decoded)?$decoded:null;
    }
}

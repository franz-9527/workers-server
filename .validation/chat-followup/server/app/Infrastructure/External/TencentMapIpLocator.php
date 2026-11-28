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
        if($this->key===''){$this->diagnostic('key_missing');return null;}
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return null;
        $payload=$this->request('https://apis.map.qq.com/ws/location/v1/ip?ip='.rawurlencode($ip).'&key='.rawurlencode($this->key));
        if(!$payload){$this->diagnostic('transport_or_invalid_response');return null;}
        return $this->project($payload);
    }

    private function project(array $payload):?array
    {
        $status=$payload['status']??null;
        if(!is_int($status)&&!(is_string($status)&&preg_match('/^-?\d{1,6}$/D',$status))){$this->diagnostic('transport_or_invalid_response');return null;}
        if((int)$status!==0){if(!in_array((int)$status,[-28,-429],true))$this->diagnostic('provider_status_'.(int)$status);return null;}
        $result=is_array($payload['result']??null)?$payload['result']:[];
        $ad=is_array($result['ad_info']??null)?$result['ad_info']:[];
        $nation=$this->safe($ad['nation']??$ad['nation_code']??'');
        $province=$this->safe($ad['province']??'');
        $city=$this->safe($ad['city']??'');
        $district=$this->safe($ad['district']??'');
        // Display the actual administrative hierarchy, never guess a missing parent.
        if($city==='')$district='';
        if($province===''&&$city===''){$this->diagnostic('empty_region');return null;}
        $domestic=in_array($nation,['中国','中华人民共和国','CN','CHN','156'],true);
        $parts=$domestic?[$province,$city,$district]:[$nation,$province,$city,$district];
        $parts=array_values(array_unique(array_filter($parts,static fn(string $v):bool=>$v!=='')));
        $display=mb_substr(implode(' ',$parts),0,80);
        return ['nation'=>$nation,'province'=>$province,'city'=>$city,'district'=>$district,'display'=>$display,'detail'=>$display];
    }

    private function diagnostic(string $code):void
    {
        // Never log the raw IP, query URL, key or provider response.
        try{\write_log('chat-ip-region','['.gmdate('c').'] '.$code."\n");}catch(\Throwable $e){}
    }

    private function safe(mixed $value):string
    {
        if(!is_string($value)&&!is_int($value))return '';
        $value=trim((string)$value);
        if(in_array(strtolower($value),['','0','未知','未知地区','未知省份','未知城市','未知区县','unknown','null','n/a','-','--'],true)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>64||preg_match('/[\p{C}<>]/u',$value))return '';
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
                $response=curl_exec($handle);$errno=curl_errno($handle);$code=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);curl_close($handle);
                if($errno===CURLE_OPERATION_TIMEDOUT){$this->diagnostic('timeout');return ['status'=>-28];}
                if($code===429){$this->diagnostic('http_rate_limit');return ['status'=>-429];}
                if(is_string($response)&&$code>=200&&$code<300)$body=$response;
            }
        }
        // Do not double the latency by retrying a failed curl request through streams.
        if(!function_exists('curl_init')&&ini_get('allow_url_fopen')){
            $context=stream_context_create(['http'=>['timeout'=>3,'ignore_errors'=>false,'follow_location'=>0,'max_redirects'=>0,'header'=>"Accept: application/json\r\n"]]);
            $response=@file_get_contents($url,false,$context);
            if(is_string($response)&&$response!=='')$body=$response;
        }
        if(!is_string($body)||$body==='')return null;
        $decoded=json_decode($body,true);
        return is_array($decoded)?$decoded:null;
    }
}
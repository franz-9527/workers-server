<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use Closure;
use RuntimeException;

final class ChatWechatSIPluginProbe
{
    public const APP_ID='wx069ba97219f66d99';
    private ?Closure $transport;

    public function __construct(?Closure $transport=null){$this->transport=$transport;}

    public function run(string $token):array
    {
        if(preg_match('/^[A-Za-z0-9_-]{10,2048}$/D',$token)!==1)return $this->failed('CHAT_WECHATSI_PLUGIN_TOKEN_INVALID');
        try{$raw=$this->transport?($this->transport)($token):$this->request($token);}
        catch(\Throwable $e){return $this->failed('CHAT_WECHATSI_PLUGIN_REQUEST_FAILED');}
        $http=(int)($raw['status']??0);$body=(string)($raw['body']??'');
        $decoded=json_decode($body,true,32);
        if($http!==200||!is_array($decoded))return $this->failed('CHAT_WECHATSI_PLUGIN_RESPONSE_INVALID',$http);
        $err=(string)($decoded['errcode']??'');
        if($err!==''&&$err!=='0')return $this->failed('CHAT_WECHATSI_PLUGIN_WECHAT_ERROR',$http,$err);
        $state='not_added';
        foreach(is_array($decoded['plugin_list']??null)?$decoded['plugin_list']:[] as $plugin){
            if(!is_array($plugin)||(string)($plugin['appid']??'')!==self::APP_ID)continue;
            $state=match((int)($plugin['status']??0)){1=>'pending',2=>'approved',3=>'rejected',4=>'timeout',default=>'unknown'};
            break;
        }
        return ['schema_version'=>1,'status'=>'completed','tested_at'=>gmdate('c'),'plugin_appid'=>self::APP_ID,'plugin_state'=>$state,'approved'=>$state==='approved'];
    }

    private function failed(string $code,int $http=0,string $errcode=''):array
    {
        $out=['schema_version'=>1,'status'=>'failed','tested_at'=>gmdate('c'),'plugin_appid'=>self::APP_ID,'plugin_state'=>'unknown','approved'=>false,'code'=>$code];
        if($http>=0&&$http<=599)$out['http_status']=$http;
        if($errcode===''||preg_match('/^-?[0-9]{1,12}$/D',$errcode)===1)$out['errcode']=$errcode;
        return $out;
    }

    private function request(string $token):array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('curl unavailable');
        $response='';$curl=curl_init('https://api.weixin.qq.com/wxa/plugin?access_token='.rawurlencode($token));
        if($curl===false)throw new RuntimeException('curl unavailable');
        curl_setopt_array($curl,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{"action":"list"}',CURLOPT_RETURNTRANSFER=>false,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json; charset=utf-8','Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{
                if(strlen($response)+strlen($chunk)>65536)return 0;$response.=$chunk;return strlen($chunk);
            },
        ]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($ok===false||$status<1)throw new RuntimeException('request failed');
        return ['status'=>$status,'body'=>$response];
    }
}

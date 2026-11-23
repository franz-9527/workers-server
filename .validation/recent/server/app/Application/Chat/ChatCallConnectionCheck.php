<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

/** Explicit diagnostic: read-only connection probe or isolated empty-channel stop-rule test; never creates media or grants quota. */
final class ChatCallConnectionCheck
{
    private const HOST='api.sd-rtn.com';

    public function __construct(private ChatCallCredentials $credentials,private ?\Closure $transport=null){}
    public function run(bool $testControl=false):array
    {
        $report=['checked_at'=>gmdate('Y-m-d H:i:s').' UTC','app_id'=>'','endpoint'=>self::HOST,'connected'=>false,'control_checked'=>false,'checks'=>[]];
        $add=static function(string $label,string $state,string $message)use(&$report):void{
            $report['checks'][]=['label'=>$label,'state'=>$state,'message'=>$message];
        };
        try{$saved=$this->credentials->runtime();}
        catch(\Throwable $e){$add('配置读取','failed','已保存的凭据无法读取，请重新保存通道密钥。');return $report;}
        $app=(string)($saved['app_id']??'');$report['app_id']=$app;
        $validApp=preg_match('/^[a-f0-9]{32}$/D',$app)===1;
        $validCertificate=preg_match('/^[a-f0-9]{32}$/D',(string)($saved['certificate']??''))===1;
        $id=(string)($saved['customer_id']??'');$secret=(string)($saved['customer_secret']??'');
        $validRest=$id!==''&&$secret!==''&&strlen($id)<=256&&strlen($secret)<=256&&!preg_match('/[\x00-\x20\x7f]/',$id.$secret);
        $add('应用 AppID',$validApp?'passed':'failed',$validApp?'格式正确。':'请先保存有效的声网 AppID。');
        $add('应用证书',$validCertificate?'passed':'failed',$validCertificate?'格式正确，是否与应用匹配仍需真实通话验证。':'应用证书缺失或格式不正确。');
        $privileges=($saved['publish_privileges_verified']??false)===true;
        $add('发布权限确认',$privileges?'passed':'pending',$privileges?'已保存管理员确认。':'尚未确认，请在声网后台开启并验证发布权限鉴权。');
        if(!$validApp||!$validRest){
            $add('官方接口连接','failed','请先保存 AppID、REST 客户 ID 和 REST 客户密钥，再点击检测。');
        }else{
            $path='/dev/v1/channel/user/'.$app.'/bx_probe_'.bin2hex(random_bytes(12));
            try{
                $response=$this->transport?($this->transport)($path,$id,$secret):$this->request($path,$id,$secret);
                $status=(int)($response['status']??0);$body=$response['body']??'';$detail=self::responseDetail($response);
                if($status===401||$status===403)$message='认证或应用访问权限未通过，请核对 REST 客户 ID、密钥及所属账号。';
                elseif($status===429)$message='供应商限流，请稍后重试。';
                elseif($status!==200)$message=$status>0?'官方接口返回 HTTP '.$status.'。':'未能连接官方接口，请检查服务器网络和 cURL 扩展。';
                elseif(!is_string($body)||strlen($body)>16384)$message='官方响应无效或超出大小限制。';
                else{
                    $data=json_decode($body,true);
                    if(is_array($data)&&($data['success']??null)===true&&is_bool($data['data']['channel_exist']??null)){
                        $report['connected']=true;$message='官方频道查询接口认证及连接通过。';
                    }else $message='官方响应未确认成功，请核对应用和 REST 凭据。';
                }
                $add('官方接口连接',$report['connected']?'passed':'failed',$message.$detail);
            }catch(\Throwable $e){$add('官方接口连接','failed','连接检测异常，请稍后重试并检查服务器网络。');}
        }
        if($testControl&&$report['connected']){
            $channel='bx_probe_'.bin2hex(random_bytes(12));
            try{
                // Current RTC channel-management API uses the "time" field.
                $rule=['appid'=>$app,'cname'=>$channel,'time'=>10,'privileges'=>['join_channel']];
                $response=$this->invoke('/dev/v1/kicking-rule',$id,$secret,'POST',$rule);
                $data=is_string($response['body']??null)&&strlen($response['body'])<=16384?json_decode($response['body'],true):null;
                if((int)($response['status']??0)!==200||!is_array($data)||($data['status']??null)!=='success'){
                    $add('停止接口自检（空频道）','failed','供应商未确认停止规则成功，请核对 REST 权限或请求参数。'.self::responseDetail($response));
                }else{
                    $response=$this->invoke('/dev/v1/channel/user/'.$app.'/'.$channel,$id,$secret,'GET',null);
                    $data=is_string($response['body']??null)&&strlen($response['body'])<=16384?json_decode($response['body'],true):null;
                    $report['control_checked']=(int)($response['status']??0)===200&&is_array($data)
                        &&($data['success']??null)===true&&($data['data']['channel_exist']??null)===false;
                    $add('停止接口自检（空频道）',$report['control_checked']?'passed':'failed',
                        ($report['control_checked']?'隔离空频道的停止规则与频道查询均通过。':'停止规则已受理，但频道查询尚未确认。').self::responseDetail($response));
                }
            }catch(\Throwable $e){$add('停止接口自检（空频道）','failed','停止接口检测异常或超时，请稍后重试。');}
        }
        $add('真机通话停止','pending','空频道接口检测不验证真实媒体。完成一次双端真机通话后，若媒体初始化失败，后台会记录通道、失败阶段和供应商错误码；挂断仍按服务端停止确认规则处理。');
        return $report;
    }
    private function invoke(string $path,string $id,string $secret,string $method,?array $body):array
    {
        return $this->transport?($this->transport)($path,$id,$secret,$method,$body):$this->request($path,$id,$secret,$method,$body);
    }
    private static function responseDetail(array $response):string
    {
        $parts=[];
        $status=(int)($response['status']??0);if($status>0)$parts[]='HTTP '.$status;
        if(isset($response['elapsed_ms'])&&is_int($response['elapsed_ms']))$parts[]=(string)$response['elapsed_ms'].'ms';
        foreach(['request_id'=>'RequestId','resource_id'=>'ResourceId'] as $key=>$label){
            $value=trim((string)($response[$key]??''));if($value!==''&&strlen($value)<=96)$parts[]=$label.' '.$value;
        }
        $errno=(int)($response['curl_errno']??0);if($errno>0)$parts[]='cURL '.$errno;
        $body=$response['body']??'';
        if(is_string($body)&&$body!==''&&strlen($body)<=16384){
            $json=json_decode($body,true);
            if(is_array($json)){
                foreach(['message','reason'] as $key){
                    $value=trim((string)($json[$key]??''));if($value!==''&&mb_strlen($value)<=120)$parts[]=$key.'='.$value;
                }
            }
        }
        return $parts?'（'.implode(' · ',array_values(array_unique($parts))).'）':'';
    }
    private function request(string $path,string $id,string $secret,string $method='GET',?array $payload=null):array
    {
        if(!function_exists('curl_init'))return ['status'=>0,'body'=>'','host'=>self::HOST,'elapsed_ms'=>0,'curl_errno'=>-1];
        $curl=curl_init('https://'.self::HOST.$path);$body='';$requestId='';$resourceId='';$started=microtime(true);
        curl_setopt_array($curl,[
            CURLOPT_HTTPGET=>true,CURLOPT_USERPWD=>$id.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$requestId,&$resourceId):int{
                $trim=trim($line);
                if(stripos($trim,'X-Request-ID:')===0)$requestId=trim(substr($trim,13));
                elseif(stripos($trim,'X-Resource-ID:')===0)$resourceId=trim(substr($trim,14));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>16384)return 0;$body.=$chunk;return strlen($chunk);
            },
        ]);
        if($method==='POST'){curl_setopt($curl,CURLOPT_POST,true);curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));curl_setopt($curl,CURLOPT_HTTPHEADER,['Accept: application/json','Content-Type: application/json']);}
        try{
            $ok=curl_exec($curl);$errno=curl_errno($curl);
            return ['status'=>$ok===false?0:(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$ok===false?'':$body,'host'=>self::HOST,
                'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),'curl_errno'=>$errno,'request_id'=>$requestId,'resource_id'=>$resourceId];
        }finally{curl_close($curl);}
    }
}

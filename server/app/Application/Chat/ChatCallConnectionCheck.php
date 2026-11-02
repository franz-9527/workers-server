<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

/** Explicit administrator diagnostic; never creates a call, changes a pool or verifies a real stop. */
final class ChatCallConnectionCheck
{
    public function __construct(private ChatCallCredentials $credentials,private ?\Closure $transport=null){}
    public function run():array
    {
        $report=['checked_at'=>gmdate('Y-m-d H:i:s').' UTC','app_id'=>'','connected'=>false,'checks'=>[]];
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
                $status=(int)($response['status']??0);$body=$response['body']??'';
                if($status===401||$status===403)$message='认证或应用访问权限未通过，请核对 REST 客户 ID、密钥及所属账号。';
                elseif($status===429)$message='供应商限流，请稍后重试。';
                elseif($status!==200)$message=$status>0?'官方接口返回 HTTP '.$status.'，请稍后重试。':'未能连接官方接口，请检查服务器网络和 cURL 扩展。';
                elseif(!is_string($body)||strlen($body)>16384)$message='官方响应无效或超出大小限制。';
                else{
                    $data=json_decode($body,true);
                    if(is_array($data)&&($data['success']??null)===true&&is_bool($data['data']['channel_exist']??null)){
                        $report['connected']=true;$message='官方频道查询接口认证及连接通过。';
                    }else $message='官方响应未确认成功，请核对应用和 REST 凭据。';
                }
                $add('官方接口连接',$report['connected']?'passed':'failed',$message);
            }catch(\Throwable $e){$add('官方接口连接','failed','连接失败或超时，请稍后重试并检查服务器网络。');}
        }
        $add('实际通话停用','pending','本次只检查配置和连接；实际通话停止能力尚未由本次检测验证。');
        return $report;
    }
    private function request(string $path,string $id,string $secret):array
    {
        if(!function_exists('curl_init'))return ['status'=>0,'body'=>''];
        $curl=curl_init('https://api.sd-rtn.com'.$path);$body='';
        curl_setopt_array($curl,[
            CURLOPT_HTTPGET=>true,CURLOPT_USERPWD=>$id.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{
                if(strlen($body)+strlen($chunk)>16384)return 0;$body.=$chunk;return strlen($chunk);
            },
        ]);
        try{$ok=curl_exec($curl);return ['status'=>$ok===false?0:(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$ok===false?'':$body];}
        finally{curl_close($curl);}
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use RuntimeException;

/**
 * 2026-08-25：按订单与分享类型各缓存一张小程序码；重复生成直接覆盖，最多两张，避免长期堆积图片。
 */
final class MiniProgramCodeService
{
    public function generate(int $orderId, string $shareType, string $path): array
    {
        if (!in_array($shareType, ['ordinary','worker_invite'], true) && preg_match('/^chat_[a-f0-9]{32}$/D',$shareType)!==1) throw new RuntimeException('分享类型不正确。');
        if ($path === '' || strlen($path) > 128) throw new RuntimeException('小程序分享路径为空或超过微信接口限制。');
        $token=$this->accessToken();
        $payload=json_encode(['path'=>$path,'width'=>430,'auto_color'=>false,'line_color'=>['r'=>0,'g'=>185,'b'=>203],'is_hyaline'=>false,'env_version'=>(string)\setting('wechat.miniapp_env_version','release')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($payload))throw new RuntimeException('小程序码参数编码失败。');
        $endpoint='getwxacode';
        if(str_starts_with($shareType,'chat_')){
            $parts=explode('?',$path,2);parse_str($parts[1]??'',$query);
            if($parts[0]!=='subpackages/chat/group/index'||array_keys($query)!==['invite']||!is_string($query['invite'])||preg_match('/^[a-f0-9]{32}$/D',$query['invite'])!==1)throw new RuntimeException('协作群分享路径不正确');
            $env=['formal'=>'release','release'=>'release','trial'=>'trial','developer'=>'develop','develop'=>'develop'][(string)\setting('wechat.miniapp_env_version','release')]??'release';
            $payload=json_encode(['page'=>$parts[0],'scene'=>$query['invite'],'width'=>430,'check_path'=>true,'env_version'=>$env],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            $endpoint='getwxacodeunlimit';
        }
        $body=$this->request('https://api.weixin.qq.com/wxa/'.$endpoint.'?access_token='.rawurlencode($token),$payload);
        if(strncmp($body,"\x89PNG",4)!==0&&strncmp($body,"\xFF\xD8",2)!==0){$error=json_decode($body,true);throw new RuntimeException('微信生成小程序码失败：'.(is_array($error)?(($error['errmsg']??'未知错误').'（'.($error['errcode']??'').'）'):'响应不是有效图片'));}
        $directory=\base_path('storage/private/share-codes');
        if(!is_dir($directory)&&!@mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('小程序码缓存目录不可写。');
        $file=$directory.DIRECTORY_SEPARATOR.'order-'.$orderId.'-'.$shareType.'.png';
        if(@file_put_contents($file,$body,LOCK_EX)===false)throw new RuntimeException('小程序码保存失败。');
        return ['file'=>$file,'data_uri'=>'data:image/png;base64,'.base64_encode($body)];
    }

    private function accessToken(): string
    {
        return \WorkerAdmin\Infrastructure\External\WeChatAccessTokenProvider::fromSettings()->get();
    }

    private function request(string $url, ?string $payload): string
    {
        if(!function_exists('curl_init'))throw new RuntimeException('服务器未启用 cURL，无法调用微信接口。');
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($payload!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
        $body=curl_exec($curl);$error=curl_error($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if(!is_string($body)||$body===''||$status<200||$status>=300)throw new RuntimeException('微信接口请求失败：'.($error!==''?$error:'HTTP '.$status));
        return $body;
    }
}

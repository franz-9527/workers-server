<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use RuntimeException;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * V2.2.4：微信小程序订阅消息统一由 PHP 后端发送。
 * 业务事务只写 message_outbox，本服务由计划任务消费，避免外部微信请求阻断订单事务。
 */
final class WeChatSubscribeGateway implements \WorkerAdmin\Contracts\SubscribeMessageGateway
{
    private $accessToken = '';
    private $accessTokenExpiresAt = 0;

    /** 获取当前小程序账号可使用的订阅消息类目。结果缓存 6 小时，接口受限时允许使用旧缓存。 */
    public function categoryList(): array
    {
        $cacheFile=$this->cachePath('wechat-subscribe-categories-'.$this->cacheIdentity().'.json');
        $cached=$this->readJsonCache($cacheFile,21600);
        if($cached!==null)return $cached;
        $stale=$this->readJsonCache($cacheFile,0);
        try{
            $response=$this->requestJson('GET','https://api.weixin.qq.com/wxaapi/newtmpl/getcategory?access_token='.rawurlencode($this->accessToken()),null);
            $this->assertWechatSuccess($response,'读取微信订阅类目失败');
        }catch(RuntimeException $exception){
            if($stale!==null)return $stale;
            throw $exception;
        }
        $rows=is_array($response['data']??null)?$response['data']:[];
        $result=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $id=(int)($row['id']??0);$name=trim((string)($row['name']??''));
            if($id>0)$result[]=['id'=>$id,'name'=>$name!==''?$name:('类目'.$id)];
        }
        $this->writeJsonCache($cacheFile,$result);
        return $result;
    }

    /** 获取公共模板标题列表。分页结果缓存 24 小时，避免重复搜索反复消耗微信接口额度。 */
    public function publicTemplateTitles(array $categoryIds,int $start=0,int $limit=30): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$categoryIds),static function($id){return $id>0;})));
        if(!$ids)throw new RuntimeException('当前小程序没有可用的订阅消息类目。');
        sort($ids,SORT_NUMERIC);
        $limit=max(1,min(30,$limit));$start=max(0,$start);
        $cacheKey=hash('sha256',implode(',',$ids).'|'.$start.'|'.$limit);
        $cacheFile=$this->cachePath('wechat-subscribe-public-titles-'.$this->cacheIdentity().'-'.$cacheKey.'.json');
        $cached=$this->readJsonCache($cacheFile,86400);
        if($cached!==null){$cached['cache_hit']=true;return $cached;}
        $stale=$this->readJsonCache($cacheFile,0);
        try{
            $response=$this->requestJson('GET','https://api.weixin.qq.com/wxaapi/newtmpl/getpubtemplatetitles?'.http_build_query([
                'access_token'=>$this->accessToken(),'ids'=>implode(',',$ids),'start'=>$start,'limit'=>$limit,
            ]),null);
            $this->assertWechatSuccess($response,'读取微信公共订阅模板失败');
        }catch(RuntimeException $exception){
            if($stale!==null){$stale['cache_hit']=true;$stale['stale_cache']=true;return $stale;}
            throw $exception;
        }
        $rows=is_array($response['data']??null)?$response['data']:[];
        $resultRows=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $tid=(string)($row['tid']??'');if($tid==='')continue;
            $resultRows[]=[
                'tid'=>$tid,
                'title'=>mb_substr(trim((string)($row['title']??'')),0,80),
                'type'=>(string)($row['type']??''),
                'category_id'=>(int)($row['categoryId']??$row['category_id']??0),
            ];
        }
        $result=['count'=>max(0,(int)($response['count']??count($resultRows))),'data'=>$resultRows];
        $this->writeJsonCache($cacheFile,$result);
        $result['cache_hit']=false;
        return $result;
    }

    /** 获取某个公共模板可选关键词。结果缓存 24 小时。 */
    public function publicTemplateKeywords(string $tid): array
    {
        $tid=trim($tid);if($tid==='')throw new RuntimeException('缺少微信公共模板编号。');
        $cacheFile=$this->cachePath('wechat-subscribe-public-keywords-'.$this->cacheIdentity().'-'.hash('sha256',$tid).'.json');
        $cached=$this->readJsonCache($cacheFile,86400);
        if($cached!==null)return $cached;
        $stale=$this->readJsonCache($cacheFile,0);
        try{
            $response=$this->requestJson('GET','https://api.weixin.qq.com/wxaapi/newtmpl/getpubtemplatekeywords?'.http_build_query([
                'access_token'=>$this->accessToken(),'tid'=>$tid,
            ]),null);
            $this->assertWechatSuccess($response,'读取微信模板关键词失败');
        }catch(RuntimeException $exception){
            if($stale!==null)return $stale;
            throw $exception;
        }
        $rows=is_array($response['data']??null)?$response['data']:[];
        $result=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $kid=(int)($row['kid']??0);if($kid<1)continue;
            $result[]=[
                'kid'=>$kid,
                'name'=>mb_substr(trim((string)($row['name']??'')),0,80),
                'example'=>mb_substr(trim((string)($row['example']??'')),0,120),
                'rule'=>mb_substr(trim((string)($row['rule']??'')),0,120),
            ];
        }
        $this->writeJsonCache($cacheFile,$result);
        return $result;
    }

    /** 将公共模板的 2～5 个关键词添加到“我的模板”，返回最终 priTmplId。 */
    public function addTemplate(string $tid,array $kidList,string $sceneDesc=''): string
    {
        TestingEnvironmentPolicy::assertWechatAccountWriteAllowed();
        $tid=trim($tid);
        $kids=array_values(array_unique(array_filter(array_map('intval',$kidList),static function($id){return $id>0;})));
        if($tid==='')throw new RuntimeException('缺少微信公共模板编号。');
        if(count($kids)<2||count($kids)>5)throw new RuntimeException('微信公共模板必须选择 2～5 个关键词。');
        $body=['tid'=>$tid,'kidList'=>$kids];
        $sceneDesc=mb_substr(trim($sceneDesc),0,15);
        if($sceneDesc!=='')$body['sceneDesc']=$sceneDesc;
        $response=$this->requestJson('POST','https://api.weixin.qq.com/wxaapi/newtmpl/addtemplate?access_token='.rawurlencode($this->accessToken()), $body);
        $this->assertWechatSuccess($response,'添加微信订阅模板失败');
        $templateId=trim((string)($response['priTmplId']??''));
        if($templateId==='')throw new RuntimeException('微信已返回成功，但没有返回 priTmplId。');
        return $templateId;
    }

    /**
     * 读取微信公众平台“我的订阅模板”。后台配置页用它同步真实标题、类型和字段名，
     * 避免手填标题/类型或 field_map 指向不存在的关键词。
     */
    public function templateList(): array
    {
        $response = $this->requestJson(
            'GET',
            'https://api.weixin.qq.com/wxaapi/newtmpl/gettemplate?access_token=' . rawurlencode($this->accessToken()),
            null
        );
        $errorCode = (int) ($response['errcode'] ?? 0);
        if ($errorCode !== 0) {
            $message = trim((string) ($response['errmsg'] ?? '未知错误'));
            throw new RuntimeException('读取微信订阅模板失败（' . $errorCode . '）：' . mb_substr($message, 0, 180));
        }
        $rows = is_array($response['data'] ?? null) ? $response['data'] : [];
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $templateId = trim((string) ($row['priTmplId'] ?? $row['template_id'] ?? ''));
            if ($templateId === '') continue;
            $content = (string) ($row['content'] ?? '');
            $fields = [];$fieldLabels=[];
            if ($content !== '' && preg_match_all('/([^\r\n：:]{1,40})[：:]?\s*\{\{\s*([A-Za-z_]+\d+)\.DATA\s*\}\}/u', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $field=trim((string)($match[2]??''));$label=trim((string)($match[1]??''));
                    $label=trim((string)(preg_replace('/^[\s\-—·]+|[\s\-—·]+$/u','',$label)??$label));
                    if($field!==''&&!in_array($field,$fields,true))$fields[]=$field;
                    if($field!==''&&$label!=='')$fieldLabels[$field]=mb_substr($label,0,40);
                }
            } elseif ($content !== '' && preg_match_all('/\{\{\s*([A-Za-z_]+\d+)\.DATA\s*\}\}/', $content, $matches)) {
                foreach ((array) ($matches[1] ?? []) as $field) {
                    $field = trim((string) $field);
                    if ($field !== '' && !in_array($field, $fields, true)) $fields[]=$field;
                }
            }
            $result[$templateId] = [
                'template_id' => $templateId,
                'title' => mb_substr(trim((string) ($row['title'] ?? '')), 0, 80),
                'type' => (string) ($row['type'] ?? ''),
                'fields' => $fields,
                'field_labels'=>$fieldLabels,
                'content' => mb_substr($content, 0, 1000),
            ];
        }
        return $result;
    }

    public function send(array $payload): array
    {
        $openid = trim((string) ($payload['openid'] ?? ''));
        $templateId = trim((string) ($payload['template_id'] ?? ''));
        $page = trim((string) ($payload['page'] ?? ''));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if ($openid === '' || $templateId === '' || !$data) {
            throw new RuntimeException('微信订阅消息缺少接收人、模板或模板数据。');
        }
        TestingEnvironmentPolicy::assertWechatRecipientAllowed($openid);
        $env = trim((string) \setting('wechat.miniapp_env_version', 'formal')) ?: 'formal';
        $env = ['release'=>'formal','develop'=>'developer'][$env] ?? $env;
        if (!in_array($env, ['formal','trial','developer'], true)) $env = 'formal';
        $body = [
            'touser' => $openid,
            'template_id' => $templateId,
            'page' => $page,
            'miniprogram_state' => $env,
            'lang' => 'zh_CN',
            'data' => $data,
        ];
        $response = $this->requestJson(
            'POST',
            'https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=' . rawurlencode($this->accessToken()),
            $body
        );
        $errorCode = (int) ($response['errcode'] ?? 0);
        if ($errorCode !== 0) {
            $message = trim((string) ($response['errmsg'] ?? '未知错误'));
            throw new RuntimeException('微信订阅消息发送失败（' . $errorCode . '）：' . mb_substr($message, 0, 180));
        }
        return $response;
    }

    private function assertWechatSuccess(array $response,string $prefix): void
    {
        $errorCode=(int)($response['errcode']??0);
        if($errorCode===0)return;
        $message=trim((string)($response['errmsg']??'未知错误'));
        if($errorCode===45009)throw new RuntimeException($prefix.'（45009）：微信接口今日调用额度已用尽，已停止继续请求；已有缓存仍可使用。');
        throw new RuntimeException($prefix.'（'.$errorCode.'）：'.mb_substr($message,0,180));
    }

    private function accessToken(): string
    {
        if($this->accessToken!==''&&$this->accessTokenExpiresAt>time()+300)return $this->accessToken;
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        $secret=trim((string)\setting('wechat.miniapp_secret',''));
        if($appId===''||$secret==='')throw new RuntimeException('请先配置小程序 AppID 和 AppSecret。');

        // 与 WorkerCloudGatewayService 共用同一个私有 access_token 缓存，避免每个搜索分段重新取 token。
        $cacheFile=$this->cachePath('wechat-access-token-'.hash('sha256',$appId).'.json');
        $cached=$this->readJsonCache($cacheFile,0);
        if(is_array($cached)&&!empty($cached['token'])&&(int)($cached['expires_at']??0)>time()+300){
            $this->accessToken=(string)$cached['token'];
            $this->accessTokenExpiresAt=(int)$cached['expires_at'];
            return $this->accessToken;
        }

        $response=$this->requestJson('GET','https://api.weixin.qq.com/cgi-bin/token?'.http_build_query([
            'grant_type'=>'client_credential','appid'=>$appId,'secret'=>$secret,
        ]),null);
        $token=trim((string)($response['access_token']??''));
        if($token==='')throw new RuntimeException('微信接口未返回 access_token：'.mb_substr((string)($response['errmsg']??'未知错误'),0,180));
        $expiresAt=time()+max(600,(int)($response['expires_in']??7200));
        $this->writeJsonCache($cacheFile,['token'=>$token,'expires_at'=>$expiresAt]);
        $this->accessToken=$token;$this->accessTokenExpiresAt=$expiresAt;
        return $token;
    }

    private function cacheIdentity(): string
    {
        $appId=trim((string)\setting('wechat.miniapp_appid',''));
        if($appId==='')throw new RuntimeException('请先配置小程序 AppID。');
        return hash('sha256',$appId);
    }

    private function cachePath(string $file): string
    {
        $directory=\base_path('storage/private/cache');
        if(!is_dir($directory))@mkdir($directory,0775,true);
        return $directory.DIRECTORY_SEPARATOR.$file;
    }

    private function readJsonCache(string $file,int $maxAge): ?array
    {
        if(!is_file($file))return null;
        if($maxAge>0){
            $mtime=@filemtime($file);
            if(!$mtime||time()-(int)$mtime>$maxAge)return null;
        }
        $decoded=json_decode((string)@file_get_contents($file),true);
        return is_array($decoded)?$decoded:null;
    }

    private function writeJsonCache(string $file,array $data): void
    {
        $encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($encoded))@file_put_contents($file,$encoded,LOCK_EX);
    }

    private function requestJson(string $method, string $url, ?array $body): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('服务器缺少 PHP cURL 扩展。');
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('服务器无法初始化微信 HTTPS 请求。');
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && !is_string($payload)) throw new RuntimeException('微信订阅消息参数编码失败。');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
        ]);
        if ($payload !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new RuntimeException('微信接口连接失败' . ($error !== '' ? '：' . $error : '，HTTP ' . $status));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) throw new RuntimeException('微信接口返回了无法解析的数据。');
        return $decoded;
    }
}

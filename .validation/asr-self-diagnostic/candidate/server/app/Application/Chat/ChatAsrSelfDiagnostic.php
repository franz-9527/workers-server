<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

final class ChatAsrSelfDiagnostic
{
    /** Read only the sanitized WechatSI plugin probe state written by the deploy check. */
    public function readPluginState(string $path):array
    {
        try{
            clearstatcache(true,$path);
            if(@is_link($path)||!@is_file($path)||($size=@filesize($path))===false||$size<2||$size>4096)return[];
            $raw=@file_get_contents($path,false,null,0,4097);
            $data=is_string($raw)?json_decode($raw,true,16):null;
            if(!is_array($data)||($data['schema_version']??null)!==1||(string)($data['plugin_appid']??'')!==ChatWechatSIPluginProbe::APP_ID)return[];
            $state=(string)($data['plugin_state']??'unknown');
            if(!in_array($state,['approved','pending','rejected','timeout','not_added','unknown'],true))$state='unknown';
            $out=['status'=>(string)($data['status']??'failed'),'tested_at'=>mb_substr((string)($data['tested_at']??''),0,64),'plugin_appid'=>ChatWechatSIPluginProbe::APP_ID,'plugin_state'=>$state,'approved'=>($data['approved']??false)===true];
            if(isset($data['code'])&&preg_match('/^[A-Z0-9_]{1,80}$/D',(string)$data['code'])===1)$out['code']=(string)$data['code'];
            if(isset($data['errcode'])&&preg_match('/^-?[0-9]{1,12}$/D',(string)$data['errcode'])===1)$out['errcode']=(string)$data['errcode'];
            return$out;
        }catch(\Throwable $e){return[];}
    }

    /**
     * Pure evidence classification. It never invokes WeChat, the provider, Qiniu or the database.
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function build(array $context):array
    {
        $appId=trim((string)($context['app_id']??''));
        $secretConfigured=($context['app_secret_configured']??false)===true;
        $runtime=is_array($context['runtime']??null)?$context['runtime']:[];
        $go=is_array($context['go_status']??null)?$context['go_status']:[];
        $asr=is_array($context['asr']??null)?$context['asr']:[];
        $plugin=is_array($context['plugin']??null)?$context['plugin']:[];
        $siteHost=strtolower(trim((string)($context['site_host']??'')));
        $probe=is_array($asr['openapi_probe']??null)?$asr['openapi_probe']:[];
        $variant=[];
        foreach(array_reverse(is_array($probe['variants']??null)?$probe['variants']:[]) as $row){
            if(is_array($row)){ $variant=$row; break; }
        }

        $checks=[];
        $add=static function(string $key,string $label,string $status,string $detail)use(&$checks):void{
            $allowed=['pass','warning','fail','unknown'];
            if(!in_array($status,$allowed,true))$status='unknown';
            $checks[]=['key'=>$key,'label'=>$label,'status'=>$status,'detail'=>$detail];
        };

        $validAppId=preg_match('/^wx[A-Za-z0-9_-]{6,62}$/D',$appId)===1;
        $add('miniapp_identity','小程序身份',$validAppId&&$secretConfigured?'pass':'fail',
            $validAppId?('AppID '.$appId.' · AppSecret '.($secretConfigured?'已配置':'未配置')):'当前小程序 AppID 未配置或格式不正确');

        $pluginState=(string)($plugin['plugin_state']??'unknown');
        if(($plugin['approved']??false)===true){
            $add('wechat_si','WechatSI 插件','pass','插件 '.ChatWechatSIPluginProbe::APP_ID.' 已获批；这只证明前端插件授权，不等于 SentenceASR 服务市场商品已绑定');
        }elseif($pluginState==='pending'){
            $add('wechat_si','WechatSI 插件','warning','插件申请仍在审核中');
        }elseif($pluginState==='rejected'||$pluginState==='timeout'||$pluginState==='not_added'){
            $add('wechat_si','WechatSI 插件','fail','插件状态：'.$pluginState.'；前端插件识别不可作为稳定兜底');
        }else{
            $add('wechat_si','WechatSI 插件','unknown','尚无可用的插件授权诊断结果');
        }

        $contractOk=(string)($runtime['asr_profile']??'')==='sentence_asr'
            &&(string)($runtime['asr_api_name']??'')===ChatAsrOpenApiProbe::API
            &&(string)($runtime['asr_data_encoding']??'')==='object';
        $add('contract','SentenceASR 契约',$contractOk?'pass':'fail',
            $contractOk?'固定为 service '.ChatAsrOpenApiProbe::SERVICE.' / API '.ChatAsrOpenApiProbe::API.' / data object / URL 编码，不再自动切换协议':'当前运行配置未固定为 SentenceASR + object，请先修正本地配置再判断供应商');

        $versionsMatch=($go['versions_match']??false)===true&&trim((string)($go['running_sha']??''))!=='';
        $add('go_release','Go 运行版本',$versionsMatch?'pass':'warning',
            $versionsMatch?'deployed / running / current 已确认一致':'Go 运行版本尚未确认完全一致，诊断可能对应旧二进制');

        $sourceMode=(string)($probe['source_mode']??'');
        $sourceHost=strtolower((string)($probe['source_host']??''));
        $sourceOk=($probe['status']??'')==='completed'&&$sourceMode==='site_https_mirror'&&$sourceHost!==''&&($siteHost===''||hash_equals($siteHost,$sourceHost));
        $add('source','音频取件源',$sourceOk?'pass':'warning',
            $sourceOk?('已改用本站 HTTPS：'.$sourceHost.'，同一 MP3 已在提交前完成实际 GET 校验'):'尚未形成“本站 HTTPS 镜像 + 实际 GET”完整证据，暂不能排除取件链路');

        $http=(int)($variant['http_status']??0);
        $err=(string)($variant['errcode']??'');
        $requestId=($variant['request_id_present']??false)===true;
        $providerSuccess=($variant['success']??false)===true||($http===200&&$err==='0'&&$requestId);
        $gatewayReached=$http===200;
        $add('wechat_gateway','微信服务市场入口',$providerSuccess||$gatewayReached?'pass':($http>0?'warning':'unknown'),
            $providerSuccess?'微信服务市场和供应商均返回成功':($gatewayReached?'微信 Open API 已返回 HTTP 200，错误发生在服务市场/供应商业务层':($http>0?'微信 Open API 返回 HTTP '.$http:'尚无真实 Open API 响应证据')));

        $add('provider_request_id','供应商 RequestId',$providerSuccess||$requestId?'pass':($gatewayReached&&in_array($err,['9301002','9301003'],true)?'fail':'unknown'),
            $providerSuccess||$requestId?'供应商已经进入可追踪的业务处理阶段':($gatewayReached&&in_array($err,['9301002','9301003'],true)?'9301002/9301003 且没有供应商 RequestId，故障发生在供应商正常业务响应之前':'暂无足够证据判断供应商是否进入业务处理阶段'));

        $deliveryOk=(string)($asr['intent_state']??'')==='accepted'&&(string)($asr['job_phase']??'')==='published';
        $add('voice_delivery','语音发送链路',$deliveryOk?'pass':'warning',
            $deliveryOk?'语音已 accepted / published；后台 ASR 故障不会阻塞聊天消息':'最新语音尚未同时满足 accepted / published，请先区分消息发送故障与 ASR 故障');

        $status='insufficient_evidence';$tone='warning';$label='证据不足';$summary='当前证据还不足以把故障稳定归因到某一层。';$actions=[];
        if(!$validAppId||!$secretConfigured||!$contractOk){
            $status='local_configuration';$tone='danger';$label='本地配置未就绪';
            $summary='当前仍有本地身份或 SentenceASR 契约问题，应先修复这些前置项。';
            $actions=['补齐当前小程序 AppID / AppSecret，并确认与实际测试小程序一致','将 ASR 预设固定为 SentenceASR + data object 后重新应用 Go 配置'];
        }elseif($providerSuccess){
            $status='healthy';$tone='success';$label='ASR 链路正常';
            $summary='最近一次受控探针已经拿到供应商成功响应和 RequestId。';
            $actions=['继续观察真实语音样本，不需要再切换 URL 或 data 形态'];
        }elseif($sourceOk&&$contractOk&&$gatewayReached&&in_array($err,['9301002','9301003'],true)&&!$requestId){
            $status='provider_binding_high';$tone='danger';$label='高疑：服务绑定 / 供应商后端';
            $summary='本站 HTTPS 音频源、正式 object 契约和微信 Open API 均已走通，但供应商没有返回 RequestId；七牛 URL 已不再是主要嫌疑。';
            $actions=[
                '在微信服务市场订单/授权页面核对当前 AppID '.$appId.' 是否绑定服务 '.ChatAsrOpenApiProbe::SERVICE.'，且 API '.ChatAsrOpenApiProbe::API.' 对该 AppID 生效',
                '核对服务订单、授权期限、套餐/调用资格和供应商后台状态；当前公开 Open API 无法直接读取这些绑定事实，后台只做证据推断',
                '向微信/服务商提交最新 intent '.((string)($probe['intent_id']??$asr['asr_intent_id']??'—')).'、errcode '.$err.'、source_host '.$sourceHost.'、无供应商 RequestId 这组证据',
            ];
        }elseif($err==='9301010'){
            $status='quota';$tone='danger';$label='服务配额不足';$summary='微信服务市场明确返回 9301010。';$actions=['核对该 AppID 对应服务订单的剩余额度、套餐有效期和计费状态'];
        }elseif($err==='9301011'){
            $status='rate_limit';$tone='warning';$label='服务限频';$summary='微信服务市场明确返回 9301011。';$actions=['停止并发/立即重试，保持当前后台单次调用和退避策略'];
        }elseif($err==='9301012'){
            $status='provider_timeout';$tone='warning';$label='供应商处理超时';$summary='微信服务市场明确返回 9301012。';$actions=['携带当前音频源和调用时间向供应商核对拉取/识别处理耗时'];
        }elseif(!$sourceOk){
            $status='source_unconfirmed';$tone='warning';$label='取件链路待确认';$summary='尚未形成本站 HTTPS 镜像的完整成功预检证据。';$actions=['先完成本站 HTTPS 音频镜像实际 GET 校验，再判断服务绑定'];
        }else{
            $actions=['保留当前单次 object 请求，不做失败后协议切换或自动重复收费探测','结合最新 errcode、供应商 RequestId 和服务市场订单状态继续收敛'];
        }

        $bindingStatus=$status==='healthy'?'pass':($status==='provider_binding_high'?'fail':'unknown');
        $bindingDetail=$status==='healthy'?'最新真实探针已成功，可确认当前调用路径有效':
            ($status==='provider_binding_high'?'当前微信公开接口不能直接枚举服务市场订单绑定；现有排除证据已把该层提升为最高嫌疑':'没有足够证据直接判断服务市场绑定');
        $add('service_binding','服务绑定 / 商品后端',$bindingStatus,$bindingDetail);

        return[
            'status'=>$status,'tone'=>$tone,'label'=>$label,'summary'=>$summary,
            'checks'=>$checks,'actions'=>$actions,
            'evidence'=>[
                'intent_id'=>(string)($probe['intent_id']??$asr['asr_intent_id']??''),
                'tested_at'=>(string)($probe['tested_at']??''),
                'source_host'=>$sourceHost,
                'source_mode'=>$sourceMode,
                'http_status'=>$http,
                'errcode'=>$err,
                'request_id_present'=>$requestId,
            ],
        ];
    }
}

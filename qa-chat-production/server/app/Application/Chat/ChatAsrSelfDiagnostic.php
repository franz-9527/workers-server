<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

final class ChatAsrSelfDiagnostic
{
    public function readPluginState(string $path):array
    {
        try{
            clearstatcache(true,$path);
            if(@is_link($path)||!@is_file($path)||($size=@filesize($path))===false||$size<2||$size>4096)return[];
            $raw=@file_get_contents($path,false,null,0,4097);$data=is_string($raw)?json_decode($raw,true,16):null;
            if(!is_array($data)||($data['schema_version']??null)!==1||(string)($data['plugin_appid']??'')!==ChatWechatSIPluginProbe::APP_ID)return[];
            $state=(string)($data['plugin_state']??'unknown');if(!in_array($state,['approved','pending','rejected','timeout','not_added','unknown'],true))$state='unknown';
            return ['status'=>(string)($data['status']??'failed'),'tested_at'=>mb_substr((string)($data['tested_at']??''),0,64),'plugin_appid'=>ChatWechatSIPluginProbe::APP_ID,'plugin_state'=>$state,'approved'=>($data['approved']??false)===true];
        }catch(\Throwable $e){return[];}
    }

    public function build(array $context):array
    {
        $runtime=is_array($context['runtime']??null)?$context['runtime']:[];
        $go=is_array($context['go_status']??null)?$context['go_status']:[];
        $asr=is_array($context['asr']??null)?$context['asr']:[];
        $plugin=is_array($context['plugin']??null)?$context['plugin']:[];
        $checks=[];$add=static function(string $key,string $label,string $status,string $detail)use(&$checks):void{$checks[]=['key'=>$key,'label'=>$label,'status'=>$status,'detail'=>$detail];};

        $profile=(string)($runtime['asr_profile']??'');
        $credentialReady=!empty($runtime['tencent_asr_secret_id_configured'])&&!empty($runtime['tencent_asr_secret_key_configured']);
        $engine=(string)($runtime['tencent_asr_engine']??'');
        $engineReady=in_array($engine,['16k_zh_dialect','16k_zh','16k_zh-PY','16k_yue'],true);
        $contractReady=$profile==='tencent_sentence'&&(string)($runtime['asr_api_name']??'')==='SentenceRecognition'&&(string)($runtime['asr_data_encoding']??'')==='json'&&$engineReady;
        $add('tencent_credentials','腾讯云凭据',$credentialReady?'pass':'fail',$credentialReady?'SecretId / SecretKey 已加密保存，运行时仅注入 Go 环境':'SecretId / SecretKey 尚未完整配置');
        $add('contract','一句话识别契约',$contractReady?'pass':'fail',$contractReady?('SentenceRecognition · '.$engine.' · HTTPS URL · TC3-HMAC-SHA256'):'当前运行配置不是腾讯云一句话识别直连契约');
        $versions=!empty($go['versions_match'])&&!empty($go['running_sha']);$add('go_release','Go 运行版本',$versions?'pass':'warning',$versions?'deployed / running / current 已确认一致':'Go 运行版本尚未确认完全一致');

        $pluginState=(string)($plugin['plugin_state']??'unknown');
        $add('wechat_si','WechatSI 备用',!empty($plugin['approved'])?'pass':($pluginState==='pending'?'warning':'unknown'),!empty($plugin['approved'])?'旧 WechatSI 插件已获批，仅在客户端作为备用转写，不承担主服务端识别':('备用插件状态：'.$pluginState));

        $service=(string)($asr['service']??'');$state=(string)($asr['request_state']??'');$requestId=(string)($asr['provider_request_id']??'');
        $providerCode=(string)($asr['provider_code']??'');$direct=$service==='tencent_cloud_asr';
        if($direct){
            $add('latest_call','最近腾讯云调用',$state==='completed'?'pass':($state==='failed'?'fail':'warning'),
                'state '.$state.($providerCode!==''?' · '.$providerCode:'').($requestId!==''?' · RequestId 已返回':''));
        }else{
            $add('latest_call','最近腾讯云调用','unknown','尚无腾讯云直连调用结果；历史微信服务市场诊断不再作为主方案健康依据');
        }

        $status='ready_waiting';$tone='info';$label='等待腾讯云首个结果';$summary='主方案已切换为腾讯云一句话识别；完成一次真实语音后会自动显示 RequestId 和供应商结果。';$actions=[];
        if($profile==='disabled'){$status='disabled';$tone='warning';$label='服务端识别已停用';$summary='语音仍可发送，客户端 WechatSI 只作辅助转写。';}
        elseif(!$credentialReady||!$contractReady){$status='local_configuration';$tone='danger';$label='腾讯云配置未就绪';$summary='请先配置 SecretId、SecretKey 和有效识别引擎，然后应用 Go 配置。';$actions=['配置腾讯云 ASR SecretId / SecretKey','默认建议使用 16k_zh_dialect 多方言引擎','保存后应用 Go 配置并确认运行版本一致'];}
        elseif($direct&&$state==='completed'&&$requestId!==''){$status='healthy';$tone='success';$label='腾讯云识别正常';$summary='最近一次 SentenceRecognition 已成功并返回 RequestId。';}
        elseif($direct&&$state==='failed'){$status='provider_error';$tone='danger';$label='腾讯云调用失败';$summary='请求已经进入腾讯云直连链路，请按错误码和 RequestId 排查凭据、额度、参数或服务状态。';$actions=['先核对后台凭据和 ASR 服务是否开通','如有 RequestId，提交腾讯云工单时一并提供','不要自动重复同一条可能计费的识别请求'];}

        return ['status'=>$status,'tone'=>$tone,'label'=>$label,'summary'=>$summary,'checks'=>$checks,'actions'=>$actions,'evidence'=>[
            'intent_id'=>(string)($asr['asr_intent_id']??''),'tested_at'=>(string)($asr['asr_created_at']??''),'source_host'=>(string)($asr['url_host']??''),
            'source_mode'=>'https_url','http_status'=>'','errcode'=>$providerCode,'request_id_present'=>$requestId!=='',
        ]];
    }
}

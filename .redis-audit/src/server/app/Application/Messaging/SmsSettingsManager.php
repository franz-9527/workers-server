<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\AdminConfigurationAudit;
use WorkerAdmin\Contracts\AdminConfigurationRepository;
use WorkerAdmin\Contracts\SubscriptionMetadata;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;
use WorkerAdmin\Domain\Messaging\SmsTemplateCatalog;

final class SmsSettingsManager
{
    use ConfigurationInput;
    private AdminConfigurationRepository $repo;
    private SubscriptionMetadata $catalog;
    private SettingsProvider $settings;
    private AdminConfigurationAudit $audit;

    public function __construct(AdminConfigurationRepository $repo, SubscriptionMetadata $catalog, SettingsProvider $settings, AdminConfigurationAudit $audit)
    {
        $this->repo = $repo;
        $this->catalog = $catalog;
        $this->settings = $settings;
        $this->audit = $audit;
    }

    public function smsSettingsBootstrap(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $scenes=SmsTemplateCatalog::all();
        $current=$this->settings->get('sms.template_codes',[]);if(is_array($current)){foreach($current as $key=>$value){if(!isset($scenes[$key]))continue;if(is_string($value))$scenes[$key]['template_code']=$value;elseif(is_array($value))$scenes[$key]['template_code']=(string)(($value['template_code']??'')?:($value['template_id']??'')?:($value['code']??''));}}
        $this->repo->set('sms.template_codes',json_encode($scenes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId);$result->audit('settings.sms.bootstrap','system_settings',null,'一键添加阿里云短信场景');$result->notice('success','短信模板已补齐，已有模板 CODE 保持不变。');return $result->navigate('settings.messages',['channel'=>'sms'],'#settings-group-sms');

    }

    public function smsSettingsRecommend(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $catalog=MessageEventCatalog::all();
        $saved=$this->settings->get('sms.event_switches',[]);if(!is_array($saved))$saved=[];
        foreach($catalog as $eventKey=>$meta){
            if(empty($meta['sms_visible']))continue;
            $row=$saved[$eventKey]??[];
            if(!is_array($row))$row=[];
            $row['enabled']=!empty($meta['sms_recommended']);
            $row['priority']=(string)($meta['sms_priority']??'normal');
            $saved[$eventKey]=$row;
        }
        $this->repo->set('sms.event_switches',json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId);
        $result->audit('settings.sms.recommend','system_settings',null,'管理员确认应用精简短信建议开关');
        $result->notice('success','已应用精简方案：仅保留需要及时操作的推荐短信。');
        return $result->navigate('settings.messages',['channel'=>'sms'],'#settings-group-sms');

    }

    public function smsSettingsReliable(array $input,int $adminId=0):array
    {
        $result=new AdminConfigurationResult($this->audit);
        $catalog=MessageEventCatalog::all();
        $subscriptionScenes=$this->catalog->definitions();
        $saved=$this->settings->get('sms.event_switches',[]);if(!is_array($saved))$saved=[];
        foreach($catalog as $eventKey=>$meta){
            if(empty($meta['sms_visible']))continue;
            $row=$saved[$eventKey]??[];
            if(!is_array($row))$row=[];
            $sceneKey=(string)($meta['subscription_scene']??'');
            $sceneImportant=$sceneKey!==''&&!empty($subscriptionScenes[$sceneKey]['important']);
            $importantFallback=!empty($meta['sms_subscription_fallback'])&&$sceneImportant;
            $row['enabled']=!empty($meta['sms_recommended'])||$importantFallback;
            $row['priority']=(string)($meta['sms_priority']??'normal');
            $saved[$eventKey]=$row;
        }
        $this->repo->set('sms.event_switches',json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId);
        $result->audit('settings.sms.reliable','system_settings',null,'管理员确认应用高可靠消息触达方案');
        $result->notice('success','已应用高可靠方案：推荐短信保持开启，重要微信订阅不可用或最终失败时自动短信兜底。');
        return $result->navigate('settings.messages',['channel'=>'sms'],'#settings-group-sms');

    }
}

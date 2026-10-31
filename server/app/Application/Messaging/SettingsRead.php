<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\AdminConfigurationRepository;

final class SettingsRead
{
    private AdminConfigurationRepository $repo;
    public function __construct(AdminConfigurationRepository $repo) { $this->repo=$repo; }
    public function settings(array $input=[]):array
    {

        $groups = ['general','site','order','quote','commission','measurement','worker','wallet','automation','payment','wechat','finance','hall','feature'];
        $settings=$this->repo->pageRows($groups);
        $autoPreapproveCategories=$this->repo->categories();
        // 2026-08-30：由 PHP random_bytes 生成候选密钥，页面只负责填入；用户保存前可先复制到云函数环境变量。
        return ['pageTitle'=>'基础设置','activeMenu'=>'settings','settings'=>$settings,'settingsScope'=>'basic','workerSecretCandidate'=>bin2hex(random_bytes(32)),'autoPreapproveCategories'=>$autoPreapproveCategories];
    }

    public function settingsMessages(array $input=[]):array
    {

        // 2.4.1 为代码热修，不增加 SQL 迁移；首次进入仅补一个配置行，不改变表结构和版本号。
        $this->repo->ensureInternalDefaults();
        $messagePage=isset($input['channel'])&&!is_array($input['channel'])?trim((string)$input['channel']):'internal';
        if(!in_array($messagePage,['internal','subscribe','sms','wecom'],true))$messagePage='internal';
        $activeNotificationScene=isset($input['scene'])&&!is_array($input['scene'])?trim((string)$input['scene']):'';
        $groups=['notification','sms','wecom'];
        $settings=$this->repo->pageRows($groups,true);
        return ['pageTitle'=>'消息配置','activeMenu'=>'settings.messages','settings'=>$settings,'settingsScope'=>'messages','activeMessagePage'=>$messagePage,'activeNotificationScene'=>$activeNotificationScene];
    }

    public function announcementSettings(array $input=[]):array
    {

        $keys=[];
        foreach (['home','store','order_submit','order_hall','merchant_auth','quote_orders'] as $scene) {
            foreach (['enabled','text','link'] as $field) $keys[]='announcement.'.$scene.'.'.$field;
        }
        $rows=$this->repo->pageRows(['announcement']);
        $byKey=[]; foreach($rows as $row)$byKey[(string)$row['setting_key']]=$row;
        $settings=[]; foreach($keys as $key)if(isset($byKey[$key]))$settings[]=$byKey[$key];
        return ['pageTitle'=>'公告设置','activeMenu'=>'announcement','settings'=>$settings,'settingsScope'=>'announcement'];
    }

    public function settingsDeveloper(array $input=[]):array
    {

        $groups = ['storage','media','security','system','server','development'];
        $settings=$this->repo->pageRows($groups);
        // 2026-08-24：中文字段名通过数据字典展示，不改变稳定的英文物理字段。
        $fieldDictionary=$this->repo->fieldDictionary();
        return ['pageTitle'=>'开发设置','activeMenu'=>'settings.developer','settings'=>$settings,'settingsScope'=>'developer','fieldDictionary'=>$fieldDictionary];
    }
}

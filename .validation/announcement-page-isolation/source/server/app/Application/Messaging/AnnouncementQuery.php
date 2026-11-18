<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\SettingsProvider;

final class AnnouncementQuery
{
    private SettingsProvider $settings;
    public function __construct(SettingsProvider $settings) { $this->settings=$settings; }
    public function current(string $scene):array
    {
        $pageScenes=['home','store','order_submit','order_hall','merchant_auth','quote_orders'];
        $specialScene=$scene==='platform_agreement';
        if(!in_array($scene,$pageScenes,true)&&!$specialScene)return ['enabled'=>false,'text'=>'','link'=>''];
        $prefix='announcement.'.$scene;
        // 平台协议是首页公告条里的特殊入口，不属于页面运营公告。
        // 数据库尚未补齐新键时也使用内置预设，避免升级后入口先消失。
        $defaultEnabled=$specialScene;
        $defaultText=$specialScene?'平台协议与规则':'';
        $defaultLink=$specialScene?'/subpackages/support/agreements/index':'';
        $enabled=$this->settings->bool($prefix.'.enabled',$defaultEnabled);
        $text=$enabled?trim((string)$this->settings->get($prefix.'.text',$defaultText)):'';
        $link=$enabled?trim((string)$this->settings->get($prefix.'.link',$defaultLink)):'';
        return ['enabled'=>$enabled && $text!=='','text'=>$text,'link'=>$link,'environment'=>\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active()?'testing':'production'];
    }
}
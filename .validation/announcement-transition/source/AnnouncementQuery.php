<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\SettingsProvider;

final class AnnouncementQuery
{
    private SettingsProvider $settings;
    public function __construct(SettingsProvider $settings) { $this->settings=$settings; }

    /**
     * 全站运营公告只有一份后台配置。各前端页面只是不同展示位置，
     * 不得再按页面复制 announcement.<scene>.*，否则内容会重新分叉。
     */
    public function current():array
    {
        // 热更新兼容：2.2.9 曾删除全局键。新版后台首次打开会把旧首页公告迁入全局三项；
        // 在那之前仅当 announcement.enabled 根本不存在时临时读取旧首页值，避免部署瞬间公告消失。
        // 一旦全局开关存在（包括明确关闭），绝不再回退到任何页面级配置。
        $globalEnabled=$this->settings->get('announcement.enabled',null);
        $prefix=$globalEnabled===null?'announcement.home':'announcement';
        $enabled=$globalEnabled===null
            ? $this->settings->bool($prefix.'.enabled',false)
            : $this->settings->bool('announcement.enabled',false);
        $text=$enabled?trim((string)$this->settings->get($prefix.'.text','')):'';
        $link=$enabled?trim((string)$this->settings->get($prefix.'.link','')):'';
        return [
            'enabled'=>$enabled && $text!=='',
            'text'=>$text,
            'link'=>$link,
            'environment'=>\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active()?'testing':'production',
        ];
    }
}
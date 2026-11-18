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
        $enabled=$this->settings->bool('announcement.enabled',false);
        $text=$enabled?trim((string)$this->settings->get('announcement.text','')):'';
        $link=$enabled?trim((string)$this->settings->get('announcement.link','')):'';
        return [
            'enabled'=>$enabled && $text!=='',
            'text'=>$text,
            'link'=>$link,
            'environment'=>\WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy::active()?'testing':'production',
        ];
    }
}

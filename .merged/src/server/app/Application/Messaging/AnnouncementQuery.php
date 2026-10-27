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
        if(!in_array($scene,['order_submit','order_hall','merchant_auth','quote_orders'],true))return ['enabled'=>false,'text'=>'','link'=>''];
        $prefix='announcement.'.$scene;$enabled=$this->settings->bool($prefix.'.enabled',false);
        $text=$enabled?trim((string)$this->settings->get($prefix.'.text','')):'';$link=$enabled?trim((string)$this->settings->get($prefix.'.link','')):'';
        return ['enabled'=>$enabled && $text!=='','text'=>$text,'link'=>$link];
    }
}

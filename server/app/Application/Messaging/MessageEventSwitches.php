<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Messaging\MessageEventCatalog;

final class MessageEventSwitches
{
    private SettingsProvider $settings;
    public function __construct(SettingsProvider $settings) { $this->settings=$settings; }
    public function forChannel(string $channel):array
    {
        $key=$channel==='wecom'?'wecom.event_switches':($channel==='inapp'?'notification.internal_event_switches':'sms.event_switches');
        $configured=$this->settings->get($key,[]);return MessageEventCatalog::switches($channel,is_array($configured)?$configured:[]);
    }
}

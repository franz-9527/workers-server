<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SubscribeMessageGateway;

/**
 * 外部消息渠道统一应用入口。
 *
 * 业务用例只表达“发短信 / 发微信订阅 / 提醒管理员”，不依赖阿里云、微信或企微实现。
 */
final class ChannelDispatcher
{
    private SmsGateway $sms;
    private SubscribeMessageGateway $subscribe;
    private AdminAlertGateway $adminAlert;

    public function __construct(
        SmsGateway $sms,
        SubscribeMessageGateway $subscribe,
        AdminAlertGateway $adminAlert
    ) {
        $this->sms = $sms;
        $this->subscribe = $subscribe;
        $this->adminAlert = $adminAlert;
    }

    public function sms(
        string $mobile,
        string $templateCode,
        array $parameters,
        string $outId = '',
        bool $allowDisabledForTest = false
    ): array {
        return $this->sms->send($mobile, $templateCode, $parameters, $outId, $allowDisabledForTest);
    }

    public function smsWithOutcome(
        string $mobile,
        string $templateCode,
        array $parameters,
        string $outId = '',
        bool $allowDisabledForTest = false
    ): array {
        return $this->sms->sendWithOutcome($mobile, $templateCode, $parameters, $outId, $allowDisabledForTest);
    }

    public function subscribe(array $payload): array
    {
        return $this->subscribe->send($payload);
    }

    public function adminAlert(string $content, ?string $actionPath = null): array
    {
        return $this->adminAlert->send($content, $actionPath);
    }
}

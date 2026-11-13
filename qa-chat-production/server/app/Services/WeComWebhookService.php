<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Infrastructure\Messaging\WeComAdminNotifier;

/** 兼容旧调用点；实际企业微信发送与小程序跳转已迁移到 Infrastructure。 */
final class WeComWebhookService
{
    public function send(string $content, ?string $actionPath = null): array
    {
        return (new WeComAdminNotifier())->send($content, $actionPath);
    }
}

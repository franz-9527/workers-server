<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Infrastructure\Messaging\WeComAdminNotifier;

final class WeComAdminAlertGateway implements AdminAlertGateway
{
    private WeComAdminNotifier $service;

    public function __construct(?WeComAdminNotifier $service = null)
    {
        $this->service = $service ?: new WeComAdminNotifier();
    }

    public function send(string $content, ?string $actionPath = null): array
    {
        return $this->service->send($content, $actionPath);
    }
}

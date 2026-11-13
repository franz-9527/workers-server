<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\RequestContentSecurityGate;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\WeChatContentSecurityGateService;

/** 路由级 UGC 检测防腐适配器。 */
final class LegacyRequestContentSecurityGate implements RequestContentSecurityGate
{
    private WeChatContentSecurityGateService $service;

    public function __construct(Database $db)
    {
        $this->service = new WeChatContentSecurityGateService($db);
    }

    public function inspect(string $route): void
    {
        $this->service->inspect($route);
    }
}

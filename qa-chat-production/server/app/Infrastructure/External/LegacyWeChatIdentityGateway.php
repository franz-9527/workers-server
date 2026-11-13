<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\External;

use WorkerAdmin\Contracts\WeChatIdentityGateway;
use WorkerAdmin\Services\WeChatCloudSyncService;

final class LegacyWeChatIdentityGateway implements WeChatIdentityGateway
{
    private WeChatCloudSyncService $service;

    public function __construct(?WeChatCloudSyncService $service = null)
    {
        $this->service = $service ?: new WeChatCloudSyncService();
    }

    public function resolveSession(string $code): array { return $this->service->resolveSession($code); }
    public function fetchUserBundle(string $openid): array { return $this->service->fetchUserBundle($openid); }
    public function resolvePhone(string $code): string { return $this->service->resolvePhone($code); }
}

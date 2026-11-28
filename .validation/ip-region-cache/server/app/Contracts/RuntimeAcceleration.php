<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** Optional, environment-scoped acceleration, never an authority or durable queue. */
interface RuntimeAcceleration
{
    // Only public IP-derived administrative regions use the long-lived policy.
    public const IP_REGION_SCOPE='chat-ip-region-v2';
    public const IP_REGION_TTL=604800; // 7 days
    public const IP_REGION_EMPTY_TTL=3600; // 1 hour for no result or provider failure
    public function rememberPublic(string $scope,string $id,int $ttl,callable $loader):array;
    /** null means unavailable; caller must preserve its existing business policy. */
    public function allow(string $scope,string $id,int $limit,int $seconds):?bool;
    public function diagnostics():array;
}

<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** Optional, environment-scoped acceleration, never an authority or durable queue. */
interface RuntimeAcceleration
{
    public function rememberPublic(string $scope,string $id,int $ttl,callable $loader):array;
    /** null means unavailable; caller must preserve its existing business policy. */
    public function allow(string $scope,string $id,int $limit,int $seconds):?bool;
    public function diagnostics():array;
}

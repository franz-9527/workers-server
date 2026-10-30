<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Identity;

/**
 * 小程序管理员身份纯规则。
 *
 * 管理员资格绑定真实登录会话 OpenID；进入演示代理后不得继承生产管理员权限。
 */
final class AdminActorPolicy
{
    public function isRealMiniAdmin(array $actor, array $adminOpenIds): bool
    {
        $openid = trim((string) ($actor['payer_openid'] ?? ''));
        if ($openid === '') return false;
        return in_array($openid, $adminOpenIds, true);
    }

    public function isMiniAdmin(array $actor, array $adminOpenIds): bool
    {
        return empty($actor['is_demo_actor']) && $this->isRealMiniAdmin($actor, $adminOpenIds);
    }

    public function isWorker(array $actor): bool
    {
        return (int) ($actor['worker_id'] ?? 0) > 0;
    }

    public function isOrderOwner(array $actor, array $order): bool
    {
        return (int) ($actor['user_id'] ?? 0) > 0
            && (int) ($actor['user_id'] ?? 0) === (int) ($order['user_id'] ?? 0);
    }
}

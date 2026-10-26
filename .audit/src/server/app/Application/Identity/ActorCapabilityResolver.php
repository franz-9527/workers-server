<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Identity\AdminActorPolicy;

/**
 * 服务端身份能力解析入口。
 *
 * 页面/HTTP 模块只问“是否具备能力”，不再重复解释管理员 OpenID、演示身份等组合规则。
 */
final class ActorCapabilityResolver
{
    private SettingsProvider $settings;
    private AdminActorPolicy $policy;

    public function __construct(SettingsProvider $settings, ?AdminActorPolicy $policy = null)
    {
        $this->settings = $settings;
        $this->policy = $policy ?: new AdminActorPolicy();
    }

    /** @return string[] */
    public function adminOpenIds(): array
    {
        $openids = $this->settings->get('wechat.admin_openids', []);
        if (is_string($openids)) {
            $decoded = json_decode($openids, true);
            $openids = is_array($decoded)
                ? $decoded
                : preg_split('/[\s,，]+/', $openids, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($openids)) return [];
        $openids = array_map(static function ($value): string {
            return trim((string) $value);
        }, $openids);
        return array_values(array_unique(array_filter($openids, static function (string $value): bool {
            return $value !== '';
        })));
    }

    public function isRealMiniAdmin(array $actor): bool
    {
        return $this->policy->isRealMiniAdmin($actor, $this->adminOpenIds());
    }

    public function isMiniAdmin(array $actor): bool
    {
        return $this->policy->isMiniAdmin($actor, $this->adminOpenIds());
    }

    public function isWorker(array $actor): bool
    {
        return $this->policy->isWorker($actor);
    }

    public function isOrderOwner(array $actor, array $order): bool
    {
        return $this->policy->isOrderOwner($actor, $order);
    }
}

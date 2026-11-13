<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use WorkerAdmin\Foundation\OrderApplicationContainer;

/** 异常工作台读写模块共享的小程序管理员鉴权。 */
trait AdminWorkbenchModuleSupport
{
    /** @param array<string,mixed> $actor */
    private function requireWorkbenchAdmin(OrderApplicationContainer $container, array $actor): void
    {
        if (!empty($actor['is_demo_actor'])) {
            $this->orderFail('仅管理员可使用异常工作台', 'ADMIN_REQUIRED', 403);
        }

        $openids = $container->settings()->get('wechat.admin_openids', []);
        if (is_string($openids)) {
            $decoded = json_decode($openids, true);
            $openids = is_array($decoded)
                ? $decoded
                : preg_split('/[\s,，]+/', $openids, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($openids)) $openids = [];
        $openids = array_values(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $openids), static function (string $value): bool {
            return $value !== '';
        }));

        if (!in_array(trim((string)($actor['payer_openid'] ?? '')), $openids, true)) {
            $this->orderFail('仅管理员可使用异常工作台', 'ADMIN_REQUIRED', 403);
        }
    }
}

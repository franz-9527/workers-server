<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

/**
 * 平台承接单可关联账号范围。
 *
 * 真实账号必须具有有效微信身份；系统演示主档故意不创建 wechat_identities，
 * 因此按配置值或稳定演示 Public ID 命名空间作为显式例外。演示账号一旦被关联，
 * 创建出的平台订单必须同步进入 is_demo=1，禁止跨真实/演示数据域。
 */
final class PlatformOrderCustomerAccountScope
{
    public static function demoSql(string $alias = 'u'): string
    {
        $alias = self::alias($alias);
        return "(EXISTS(
                    SELECT 1 FROM system_settings demo_setting
                    WHERE demo_setting.setting_key IN ('demo.actor_user_public_id','demo.actor_merchant_public_id','demo.actor_worker_public_id')
                      AND BINARY demo_setting.setting_value=BINARY {$alias}.public_id
                )
                OR BINARY {$alias}.public_id LIKE BINARY '01K2DEMOV%USER%'
                OR BINARY {$alias}.public_id LIKE BINARY '01K2DEMOV%MERCHANT%'
                OR BINARY {$alias}.public_id LIKE BINARY '01K2DEMOV%WORKER%')";
    }

    public static function linkableSql(string $alias = 'u'): string
    {
        $alias = self::alias($alias);
        return "{$alias}.status='active' AND (
                    EXISTS(SELECT 1 FROM wechat_identities wi WHERE wi.user_id={$alias}.id AND wi.status='active')
                    OR " . self::demoSql($alias) . "
                )";
    }

    public static function roleSql(string $alias = 'u'): string
    {
        $alias = self::alias($alias);
        return "CASE
                    WHEN EXISTS(SELECT 1 FROM worker_profiles w_scope WHERE w_scope.user_id={$alias}.id) THEN 'worker'
                    WHEN EXISTS(SELECT 1 FROM merchant_profiles m_scope WHERE m_scope.user_id={$alias}.id) THEN 'merchant'
                    ELSE 'user'
                END";
    }

    private static function alias(string $alias): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) === 1 ? $alias : 'u';
    }
}

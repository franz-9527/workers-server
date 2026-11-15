<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;

/**
 * 平台承接单可关联账号范围。
 *
 * production：只允许真实、active 且具有有效微信身份的账号，并显式排除演示主档。
 * testing：在同一真实账号规则之外，额外允许系统演示主档；关联后订单进入 is_demo=1。
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
        $wechat = "EXISTS(SELECT 1 FROM wechat_identities wi WHERE wi.user_id={$alias}.id AND wi.status='active')";
        $demo = self::demoSql($alias);

        if (TestingEnvironmentPolicy::active()) {
            return "{$alias}.status='active' AND ({$wechat} OR {$demo})";
        }

        return "{$alias}.status='active' AND {$wechat} AND NOT {$demo}";
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

    public static function testingAllowsDemo(): bool
    {
        return TestingEnvironmentPolicy::active();
    }

    private static function alias(string $alias): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias) === 1 ? $alias : 'u';
    }
}

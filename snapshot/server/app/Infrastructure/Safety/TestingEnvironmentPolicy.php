<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Safety;

use RuntimeException;

/**
 * 测试环境外部副作用统一安全边界。
 * 是否处于 testing 只由 config/installed.php 的 app.env 决定；可调参数来自后台 system_settings。
 */
final class TestingEnvironmentPolicy
{
    public static function active(): bool
    {
        return strtolower(trim((string) \app_config('app.env', 'production'))) === 'testing';
    }

    public static function qiniuUploadPrefix(string $configuredPrefix): string
    {
        $configuredPrefix=trim($configuredPrefix,'/');
        if (!self::active()) return $configuredPrefix;
        $prefix = trim((string) \setting('testing.qiniu_path_prefix', 'test'), '/');
        if ($prefix === '' || preg_match('#^[A-Za-z0-9._-]{1,40}$#', $prefix) !== 1 || strpos($prefix, '..') !== false) {
            throw new RuntimeException('测试环境七牛根目录配置无效。');
        }
        if($configuredPrefix!==''&&strcasecmp($prefix,$configuredPrefix)===0){
            throw new RuntimeException('测试环境七牛根目录不能与正式根目录相同。');
        }
        return $prefix;
    }

    public static function assertQiniuDeleteAllowed(string $key): void
    {
        if (!self::active()) return;
        $prefix = self::qiniuUploadPrefix((string)\setting('qiniu.path_prefix','upload'));
        $normalized = ltrim(trim($key), '/');
        if ($normalized === '' || strpos($normalized, $prefix . '/') !== 0) {
            throw new RuntimeException('测试环境禁止删除非测试目录的七牛对象：仅允许 ' . $prefix . '/ 下的对象。');
        }
    }

    public static function paymentEnabled(bool $configuredEnabled): bool
    {
        return self::active() ? false : $configuredEnabled;
    }

    public static function assertPaymentAllowed(string $operation, string $outTradeNo = '', string $outRefundNo = ''): void
    {
        if (self::active()) throw new RuntimeException('测试环境禁止真实微信支付、退款、查单和支付回调入账。');
    }

    public static function assertSmsRecipientAllowed(string $mobile): void
    {
        if (!self::active()) return;
        if (!self::boolSetting('testing.sms_enabled', false)) {
            throw new RuntimeException('测试环境真实短信总开关未开启。');
        }
        $mobile = preg_replace('/\D+/', '', $mobile) ?? '';
        $allowed = self::stringList('testing.allowed_sms_mobiles');
        if ($mobile === '' || !in_array($mobile, $allowed, true)) {
            throw new RuntimeException('测试环境禁止向非白名单手机号发送真实短信。');
        }
    }

    public static function assertWechatRecipientAllowed(string $openid): void
    {
        if (!self::active()) return;
        if (!self::boolSetting('testing.subscribe_enabled', false)) {
            throw new RuntimeException('测试环境微信订阅消息总开关未开启。');
        }
        $openid = trim($openid);
        $allowed = self::stringList('testing.allowed_wechat_openids');
        if ($openid === '' || !in_array($openid, $allowed, true)) {
            throw new RuntimeException('测试环境禁止向非白名单 OpenID 发送真实微信订阅消息。');
        }
    }

    public static function adminAlertContent(string $content): string
    {
        if (!self::active()) return $content;
        if (!self::boolSetting('testing.allow_wecom', false)) {
            throw new RuntimeException('测试环境默认禁止企业微信机器人通知。');
        }
        return strpos($content, '【测试环境】') === 0 ? $content : "【测试环境】\n" . $content;
    }

    public static function assertWechatAccountWriteAllowed(): void
    {
        if (self::active() && !self::boolSetting('testing.allow_wechat_account_writes', false)) {
            throw new RuntimeException('测试环境默认禁止修改正式小程序的订阅模板配置。');
        }
    }

    public static function assertCloudActionAllowed(string $action): void
    {
        if (!self::active()) return;
        if (self::boolSetting('testing.allow_cloud_writes', false)) return;
        $readOnly = [
            'admin.health','admin.list','admin.detail','server.owner.get','server.nearbyMarkedWorkers',
            'server.phoneSearchIds','server.phoneReveal','server.membershipStatus','server.publicWorkerSearch',
        ];
        if (!in_array($action, $readOnly, true)) {
            throw new RuntimeException('测试环境禁止写入正式微信云开发环境：' . $action);
        }
    }

    private static function boolSetting(string $key, bool $default): bool
    {
        return filter_var(\setting($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private static function stringList(string $settingKey): array
    {
        $value = \setting($settingKey, '');
        if (is_string($value)) $value = preg_split('/[\s,;]+/', $value) ?: [];
        if (!is_array($value)) return [];
        $rows = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') $rows[] = $item;
        }
        return array_values(array_unique($rows));
    }
}

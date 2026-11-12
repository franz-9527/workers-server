<?php

declare(strict_types=1);

namespace WorkerAdmin\Core;

final class AdminSessionPolicy
{
    public const SETTING = 'security.admin_idle_timeout_minutes';
    public const DEFAULT_MINUTES = 720;
    public const MIN_MINUTES = 15;
    public const MAX_MINUTES = 10080;

    public static function idleSeconds(): int
    {
        $minutes = (int) \setting(self::SETTING, self::defaultMinutes());
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes)) * 60;
    }

    public static function defaultMinutes(): int
    {
        $seconds = (int) \app_config('security.session_lifetime', self::DEFAULT_MINUTES * 60);
        return max(self::MIN_MINUTES, min(self::MAX_MINUTES, (int) ceil($seconds / 60)));
    }

    public static function configureFileStorage(string $directory): void
    {
        if (session_module_name() !== 'files') return;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            \write_log('admin-error', '无法创建后台会话存储目录，使用 PHP 原会话目录。' . "\n");
            return;
        }
        if (!is_writable($directory)) {
            \write_log('admin-error', '后台会话存储目录不可写，使用 PHP 原会话目录。' . "\n");
            return;
        }
        session_save_path($directory);
        ini_set('session.gc_maxlifetime', (string) (self::MAX_MINUTES * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
    }
}

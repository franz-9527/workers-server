<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Identity;

/**
 * 中国大陆手机号值规则。纯函数，不依赖加密、数据库或 HTTP。
 */
final class MobileNumber
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (!is_string($digits)) {
            return '';
        }
        if (strpos($digits, '0086') === 0) {
            $digits = substr($digits, 4);
        } elseif (strpos($digits, '86') === 0 && strlen($digits) === 13) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^1\d{10}$/', $digits) === 1 ? $digits : '';
    }

    private function __construct()
    {
    }
}

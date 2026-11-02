<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Application\Identity\MobileNumberProtection;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;

/**
 * @deprecated 兼容旧调用。新代码使用 Domain\Identity\MobileNumber 或
 * Application\Identity\MobileNumberProtection。
 */
final class MobileNumberService
{
    private static function protection(): MobileNumberProtection
    {
        return new MobileNumberProtection(new GlobalPrivateValueCipher());
    }

    public static function normalize(string $value): string
    {
        return self::protection()->normalize($value);
    }

    public static function hmac(string $canonical): string
    {
        return self::protection()->hmac($canonical);
    }

    public static function encrypt(string $canonical): ?string
    {
        return self::protection()->encrypt($canonical);
    }

    public static function decrypt(?string $ciphertext): string
    {
        return self::protection()->decrypt($ciphertext);
    }

    public static function workerProfileMobile(array $worker): string
    {
        return self::protection()->workerProfileMobile($worker);
    }
}

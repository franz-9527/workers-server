<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Identity;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Domain\Identity\MobileNumber;

/**
 * 手机号规范化与隐私保护应用服务。
 */
final class MobileNumberProtection
{
    private const SMS_CONTEXT = 'sms-recipient-mobile';

    private PrivateValueCipher $cipher;

    public function __construct(PrivateValueCipher $cipher)
    {
        $this->cipher = $cipher;
    }

    public function normalize(string $value): string
    {
        return MobileNumber::normalize($value);
    }

    public function hmac(string $canonical): string
    {
        return $canonical === '' ? '' : $this->cipher->hmac($canonical, self::SMS_CONTEXT);
    }

    public function encrypt(string $canonical): ?string
    {
        return $canonical === '' ? null : $this->cipher->encrypt($canonical, self::SMS_CONTEXT);
    }

    public function decrypt(?string $cipherText): string
    {
        if (!$cipherText) {
            return '';
        }
        return MobileNumber::normalize($this->cipher->decrypt($cipherText, self::SMS_CONTEXT));
    }

    public function workerProfileMobile(array $worker): string
    {
        $cipherText = (string) ($worker['mobile_ciphertext'] ?? '');
        if ($cipherText === '') {
            return '';
        }

        $raw = $this->cipher->decrypt($cipherText, 'worker-mobile');
        if ($raw === '') {
            $raw = $this->cipher->decrypt($cipherText, 'user-mobile');
        }
        if ($raw === '') {
            $raw = $cipherText;
        }

        return MobileNumber::normalize($raw);
    }
}

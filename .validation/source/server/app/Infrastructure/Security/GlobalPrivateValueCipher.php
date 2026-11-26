<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Security;

use WorkerAdmin\Contracts\PrivateValueCipher;

final class GlobalPrivateValueCipher implements PrivateValueCipher
{
    public function hmac(string $plainText, string $context): string
    {
        return $plainText === '' ? '' : \private_value_hmac($plainText, $context);
    }

    public function encrypt(string $plainText, string $context): ?string
    {
        return $plainText === '' ? null : \encrypt_private_value($plainText, $context);
    }

    public function decrypt(string $cipherText, string $context): string
    {
        return $cipherText === '' ? '' : (string) \decrypt_private_value($cipherText, $context);
    }
}

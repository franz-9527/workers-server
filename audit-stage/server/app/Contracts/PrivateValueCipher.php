<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface PrivateValueCipher
{
    public function hmac(string $plainText, string $context): string;
    public function encrypt(string $plainText, string $context): ?string;
    public function decrypt(string $cipherText, string $context): string;
}
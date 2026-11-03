<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/**
 * 隐私字段加密/哈希端口。业务层不直接依赖全局加密函数。
 */
interface PrivateValueCipher
{
    public function hmac(string $plainText, string $context): string;

    public function encrypt(string $plainText, string $context): ?string;

    public function decrypt(string $cipherText, string $context): string;
}

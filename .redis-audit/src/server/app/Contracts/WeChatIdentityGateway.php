<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface WeChatIdentityGateway
{
    /** @return array<string,mixed> */
    public function resolveSession(string $code): array;
    /** @return array<string,mixed> */
    public function fetchUserBundle(string $openid): array;
    public function resolvePhone(string $code): string;
}

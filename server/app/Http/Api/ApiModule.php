<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api;

/**
 * API 模块边界。
 *
 * 新业务模块通过实现该接口接入，不再要求继续扩张 ApiController。
 */
interface ApiModule
{
    public function supports(string $route): bool;

    public function requiresContentSecurity(string $route): bool;

    public function dispatch(string $route): void;
}

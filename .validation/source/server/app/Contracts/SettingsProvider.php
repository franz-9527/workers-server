<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/**
 * 业务层读取系统配置的稳定端口。
 *
 * Domain/Application 不再直接依赖全局 setting()，便于后续切换数据库配置、缓存或测试替身。
 */
interface SettingsProvider
{
    /** @return mixed */
    public function get(string $key, $default = null);

    public function bool(string $key, bool $default = false): bool;

    public function int(string $key, int $default = 0): int;
}

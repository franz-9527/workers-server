<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Settings;

use WorkerAdmin\Contracts\SettingsProvider;

/**
 * 兼容现有 setting() 的基础设施适配器。
 */
final class GlobalSettingsProvider implements SettingsProvider
{
    public function get(string $key, $default = null)
    {
        return \setting($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $filtered === null ? $default : $filtered;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Domain\Form\ComponentTypeCatalog;

// 保留旧回归脚本/极简工具直接 require 本文件时的兼容性；正常请求由 bootstrap 自动加载。
if (!class_exists(ComponentTypeCatalog::class, false)) {
    require_once dirname(__DIR__) . '/Domain/Form/ComponentTypeCatalog.php';
}

/**
 * @deprecated 兼容旧后台表单设计器调用。新代码直接依赖 Domain\Form\ComponentTypeCatalog。
 */
final class FormComponentTypes
{
    public static function canonical(string $type, array $options = []): string
    {
        return ComponentTypeCatalog::canonical($type, $options);
    }

    public static function isSupported(string $type): bool
    {
        return ComponentTypeCatalog::isSupported($type);
    }
}

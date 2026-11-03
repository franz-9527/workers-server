<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Domain\Messaging\SmsTemplateCatalog as DomainSmsTemplateCatalog;

// 兼容部分独立工具直接 require 本文件而不经过 bootstrap 的场景。
if (!class_exists(DomainSmsTemplateCatalog::class, false)) {
    require_once dirname(__DIR__) . '/Domain/Messaging/SmsTemplateCatalog.php';
}

/**
 * @deprecated 短信模板契约已迁移至 Domain\Messaging\SmsTemplateCatalog。
 */
final class SmsTemplateCatalog
{
    public static function all(): array
    {
        return DomainSmsTemplateCatalog::all();
    }

    public static function testParameters(string $scene): array
    {
        return DomainSmsTemplateCatalog::testParameters($scene, date('m-d H:i'));
    }
}

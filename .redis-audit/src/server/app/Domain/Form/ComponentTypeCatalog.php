<?php

declare(strict_types=1);

namespace WorkerAdmin\Domain\Form;

/**
 * 下单表单组件类型的纯领域目录。
 */
final class ComponentTypeCatalog
{
    private const LEGACY = [
        'text_input' => 'text',
        'number_input' => 'number',
        'single' => 'radio',
        'multiple' => 'checkbox',
        'date' => 'date_picker',
        'image' => 'image_upload',
        'link' => 'url_input',
        'address' => 'wechat_address',
        'map' => 'wechat_address',
    ];

    private const STANDARD = [
        'text', 'textarea', 'number', 'radio', 'checkbox', 'switch',
        'date_picker', 'date_range', 'image_upload', 'url_input',
        'wechat_address', 'category_picker', 'phone',
    ];

    public static function canonical(string $type, array $options = []): string
    {
        if ($type === 'boolean') {
            return $options ? 'radio' : 'switch';
        }
        return self::LEGACY[$type] ?? $type;
    }

    public static function isSupported(string $type): bool
    {
        return in_array($type, self::STANDARD, true);
    }

    /** @return string[] */
    public static function standardTypes(): array
    {
        return self::STANDARD;
    }

    private function __construct()
    {
    }
}

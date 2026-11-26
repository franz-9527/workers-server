<?php

declare(strict_types=1);

namespace WorkerAdmin\Support;

/** Shared install/upgrade checks for the supported PHP runtime and media/auth capabilities. */
final class PhpRuntimeRequirements
{
    public const MINIMUM_VERSION = '8.3.36';

    /** @return array<int,array{name:string,passed:bool,message:string}> */
    public static function checks(): array
    {
        $checks = [[
            'name' => 'PHP 版本',
            'passed' => version_compare(PHP_VERSION, self::MINIMUM_VERSION, '>='),
            'message' => '当前 ' . PHP_VERSION . '，要求不低于 ' . self::MINIMUM_VERSION,
        ]];
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'curl', 'simplexml', 'gd', 'ctype', 'sodium', 'session', 'filter'] as $extension) {
            $enabled = extension_loaded($extension);
            $checks[] = [
                'name' => 'PHP 扩展 ' . $extension,
                'passed' => $enabled,
                'message' => $enabled ? '已启用' : '请在当前 PHP 的扩展中启用',
            ];
        }
        $gd = function_exists('gd_info') ? gd_info() : [];
        foreach (['JPEG' => ['imagecreatefromjpeg', 'imagejpeg'], 'PNG' => ['imagecreatefrompng', 'imagepng'], 'WebP' => ['imagecreatefromwebp', 'imagewebp']] as $format => $functions) {
            $supported = !empty($gd[$format . ' Support']) && function_exists($functions[0]) && function_exists($functions[1]);
            $checks[] = [
                'name' => 'GD ' . $format . ' 读写',
                'passed' => $supported,
                'message' => $supported ? '支持解码和编码' : '请为当前 PHP 的 GD 启用 ' . $format . ' 支持',
            ];
        }
        return $checks;
    }
}

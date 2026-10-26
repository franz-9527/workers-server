<?php

// 隔离测试的自动加载与配置；只负责本地测试依赖，不连接应用配置或外部渠道。
spl_autoload_register(static function (string $class): void {
    $prefix = 'WorkerAdmin\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = dirname(__DIR__, 2).'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) require_once $file;
});
if (!function_exists('setting')) {
    function setting($key, $default = null) { return $GLOBALS['order_test_settings'][$key] ?? $default; }
}
if (!function_exists('public_id')) {
    function public_id() { return bin2hex(random_bytes(13)); }
}
if (!function_exists('app_config')) {
    function app_config($key, $default = null) { return $default; }
}

<?php

// 2026-08-24：API v2 回调地址不携带查询参数，宝塔 Nginx 回退到根入口后再安全分发到 API。
$requestPath = parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$paymentCallbackRoutes = [
    '/api/wechatpay/notify' => 'wechatpay.notify',
    '/api/wechatpay/refund-notify' => 'wechatpay.refund_notify',
];
if (is_string($requestPath) && isset($paymentCallbackRoutes[$requestPath])) {
    $_GET['route'] = $paymentCallbackRoutes[$requestPath];
    require __DIR__ . '/api/index.php';
    exit;
}

// 2026-08-14：站点根入口根据安装状态引导一键安装或进入后台。
require __DIR__ . '/bootstrap.php';

if (!is_installed()) {
    redirect(app_url('install/'));
}

redirect(app_url('admin/'));

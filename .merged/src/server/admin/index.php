<?php

// 2026-08-14：后台使用物理 /admin 入口，宝塔无需额外伪静态即可访问。
require dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
header('Cache-Control: no-store');

if (!is_installed()) {
    redirect(app_url('install/'));
}

$route = isset($_GET['route']) ? (string) $_GET['route'] : 'dashboard';

// 2026-08-14：代码版本领先数据库时，未登录管理员先完成认证，已登录管理员直接进入唯一允许的升级入口。
if (upgrade_required() && $route !== 'login') {
    if (!auth()->check()) {
        \flash('warning', '检测到新版本，请先登录超级管理员账号后完成数据库升级。');
        redirect(admin_url('login'));
    }
    redirect(app_url('adminapi/upgrade/'));
}

// 2026-10-04：入口只保留安装/升级边界，具体后台模块由 AdminKernel 统一编排。
(new \WorkerAdmin\Http\Admin\AdminKernel(db(), auth(), csrf()))->dispatch($route);

<?php
// 2026-09-09：登录页与后台导航统一读取可修改的系统名称，不再固定使用安装配置名称。
$siteName = (string) setting('site.name', app_config('app.name'));
$siteMark = mb_substr($siteName !== '' ? $siteName : '壁', 0, 1);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>后台登录 - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('admin.css')) ?>">
</head>
<body class="admin-body admin-auth-body">
<main class="admin-auth">
    <section class="admin-auth__brand"><div class="admin-brand__mark"><?= e($siteMark) ?></div><h1><?= e($siteName) ?></h1><p>订单、师傅、报价、财务与运营配置统一管理</p></section>
    <section class="admin-auth__panel">
        <div class="admin-auth__lead"><h2>欢迎回来</h2><p>请输入管理员账号继续</p></div>
        <?php if (!empty($error)): ?><div class="admin-alert admin-alert--danger"><div class="admin-alert__body"><?= e($error) ?></div></div><?php endif; ?>
        <form method="post" action="<?= e(admin_url('login')) ?>" autocomplete="on">
            <?= csrf()->input() ?>
            <div class="admin-field admin-field--full"><label class="admin-label" for="username">管理员账号</label><input class="admin-control" id="username" name="username" autocomplete="username" required autofocus maxlength="50"></div>
            <div class="admin-field admin-field--full"><label class="admin-label" for="password">登录密码</label><input class="admin-control" id="password" name="password" type="password" autocomplete="current-password" required></div>
            <button class="admin-btn admin-btn--primary admin-auth__submit" type="submit">登录后台</button>
        </form>
        <div class="admin-auth__footer">登录失败达到限制会暂时锁定账号 · 请求编号 <?= e(request_id()) ?></div>
    </section>
</main>
</body>
</html>

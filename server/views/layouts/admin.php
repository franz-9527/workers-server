<?php
// 2026-08-14：后台主布局按角色过滤菜单，并统一输出提示、资源版本和账号安全入口。
$currentAdmin = auth()->user();
$messages = flash();
$activeMenu = isset($activeMenu) ? $activeMenu : '';
$navGroups = [
    '运营中心' => [
        ['dashboard', '工作台', 'dashboard.view', 'ri-dashboard-line'],
        ['orders', '订单管理', auth()->can('orders.manage') ? 'orders.manage' : 'orders.view', 'ri-file-list-3-line'],
        ['platform-orders', '平台承接单', 'orders.manage', 'ri-file-add-line'],
        ['workers', '师傅管理', 'workers.manage', 'ri-team-line'],
        ['worker-credentials', '师傅认证与收款', 'workers.manage', 'ri-id-card-line'],
        ['users', '用户管理', 'users.view', 'ri-user-3-line'],
        ['merchants', '商家管理', 'users.view', 'ri-store-2-line'],
    ],
    '内容配置' => [
        ['categories', '服务类目', 'catalog.manage', 'ri-layout-grid-line'],
        ['forms', '下单页配置', 'catalog.manage', 'ri-draft-line'],
        ['help', '帮助中心', 'help.manage', 'ri-question-answer-line'],
        ['agreements', '协议与规则', 'settings.manage', 'ri-file-shield-2-line'],
        ['reviews', '评价管理', 'reviews.manage', 'ri-star-smile-line'],
        ['announcement', '公告设置', 'settings.manage', 'ri-megaphone-line'],
        ['disputes', '争议处理', 'disputes.manage', 'ri-customer-service-2-line'],
    ],
    '财务中心' => [
        ['payments', '微信支付', 'payments.manage', 'ri-wechat-pay-line'],
        ['wallets', '用户余额', 'payments.manage', 'ri-wallet-3-line'],
        ['refunds', '退款管理', 'refunds.manage', 'ri-refund-2-line'],
        ['commissions', '平台服务费', 'commissions.manage', 'ri-percent-line'],
        ['tail-settlement', '尾款处理', 'commissions.manage', 'ri-exchange-cny-line'],
        ['withdrawals', '提现审核', 'withdrawals.manage', 'ri-bank-card-line'],
    ],
    '聊天管理' => [
        ['chat.calls', '音视频通话', 'chat.calls.view', 'ri-phone-line'],
        ['chat.storage', '聊天存储与保存期', 'settings.manage', 'ri-database-2-line'],
        ['chat.governance', '聊天抽检与治理', 'audit.view', 'ri-discuss-line'],
        ['chat.auditors', '小程序抽检授权', 'chat.audit.grants', 'ri-shield-user-line'],
        ['chat.image-review', '聊天图片预审', 'chat.images.review', 'ri-image-line'],
        ['chat.av-review','聊天音视频预审','chat.media.review','ri-video-line'],
    ],
    '系统' => [
        ['settings', '基础设置', 'settings.manage', 'ri-settings-3-line'],
        ['settings.messages', '消息配置', 'settings.manage', 'ri-message-2-line'],
        ['settings.developer', '开发设置', 'settings.manage', 'ri-code-box-line'],
        ['tasks', '计划任务', 'settings.manage', 'ri-timer-line'],
        ['message-outbox', '消息发送记录', 'settings.manage', 'ri-mail-send-line'],
        ['runtime-logs', '运行日志', 'settings.manage', 'ri-file-warning-line'],
        ['audit', '操作审计', 'audit.view', 'ri-history-line'],
    ],
];
if (($currentAdmin['role'] ?? '') === 'super_admin' && (string)app_config('app.env', '') === 'testing') {
    array_splice($navGroups['系统'], 3, 0, [['test-center', '测试中心', 'settings.manage', 'ri-flask-line']]);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e(isset($pageTitle) ? $pageTitle . ' - ' : '') ?><?= e(setting('site.name', app_config('app.name'))) ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('vendor/remixicon/remixicon.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('admin.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar" aria-label="后台导航">
        <a class="admin-brand" href="<?= e(admin_url('dashboard')) ?>">
            <span class="admin-brand__mark">壁</span>
            <span class="admin-brand__text"><span class="admin-brand__title"><?= e(setting('site.name', app_config('app.name'))) ?></span><span class="admin-brand__sub">ADMIN CONSOLE</span></span>
        </a>
        <nav class="admin-nav">
            <?php foreach ($navGroups as $group => $items): ?>
                <?php $visible = array_filter($items, function ($item) { return auth()->can($item[2]); }); ?>
                <?php if ($visible): ?>
                    <div class="admin-nav__label"><?= e($group) ?></div>
                    <?php foreach ($visible as $item): ?>
                        <a class="admin-nav__item <?= $activeMenu === $item[0] ? 'is-active' : '' ?>" href="<?= e(admin_url($item[0])) ?>">
                            <span class="admin-nav__icon" aria-hidden="true"><i class="<?= e($item[3]) ?>"></i></span><span><?= e($item[1]) ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($currentAdmin && $currentAdmin['role'] === 'super_admin'): ?>
                <div class="admin-nav__label">维护</div>
                <a class="admin-nav__item" href="<?= e(app_url('adminapi/upgrade/')) ?>"><span class="admin-nav__icon"><i class="ri-upload-cloud-2-line"></i></span><span>系统升级</span></a>
            <?php endif; ?>
        </nav>
        <div class="admin-sidebar__footer">
            <a class="admin-nav__item <?= $activeMenu === 'profile' ? 'is-active' : '' ?>" href="<?= e(admin_url('profile')) ?>"><span class="admin-nav__icon"><i class="ri-shield-user-line"></i></span><span>账号安全</span></a>
        </div>
    </aside>
    <button class="admin-sidebar-backdrop" type="button" data-sidebar-close aria-label="关闭导航"></button>

    <div class="admin-main">
        <header class="admin-topbar">
            <button class="admin-menu-button" type="button" data-sidebar-toggle aria-expanded="false" aria-label="打开导航"><i class="ri-menu-line"></i></button>
            <!-- 2026-09-14：顶栏版本回退值与当前代码包同步。 -->
            <div class="admin-topbar__title"><strong><?= e(isset($pageTitle) ? $pageTitle : '管理后台') ?></strong><span class="admin-text-muted">版本 <?= e(app_config('app.version', app_config('version', '2.4.0'))) ?></span></div>
            <div class="admin-topbar__actions">
                <div class="admin-api-status"><button class="admin-btn admin-btn--ghost" type="button" data-api-status-toggle data-api-status-url="<?= e(app_url('api/')) ?>" aria-expanded="false"><i class="ri-pulse-line"></i> 接口状态</button><div class="admin-api-status__panel" data-api-status-panel hidden><strong>正在检查接口…</strong><small>请稍候</small></div></div>
                <a class="admin-avatar" href="<?= e(admin_url('profile')) ?>" title="账号安全"><?= e(mb_substr($currentAdmin['real_name'] ?: $currentAdmin['username'], 0, 1)) ?></a>
                <form method="post" action="<?= e(admin_url('logout')) ?>"><?= csrf()->input() ?><button class="admin-btn admin-btn--ghost" type="submit" data-confirm="确定退出后台吗？" data-confirm-type="primary"><i class="ri-logout-box-r-line"></i> 退出</button></form>
            </div>
        </header>
        <main class="admin-content">
            <?php if ($messages): foreach ($messages as $type => $items): foreach ($items as $message): ?>
                <div hidden data-toast="<?= e($message) ?>" data-toast-type="<?= e($type) ?>"></div>
            <?php endforeach; endforeach; endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= e(asset_url('admin.js')) ?>" defer></script>
<?php if (in_array($activeMenu, ['settings', 'settings.developer'], true)): ?><script src="<?= e(asset_url('settings-pagination.js')) ?>" defer></script><?php endif; ?>
<?php if ($activeMenu === 'chat.governance'): ?><script src="<?= e(asset_url('chat-governance.js')) ?>" defer></script><?php endif; ?>
<?php if ($activeMenu === 'chat.av-review'): ?><script src="<?= e(asset_url('chat-av-review.js')) ?>" defer></script><?php endif; ?>
<?php if ($activeMenu === 'chat.image-review'): ?><script src="<?= e(asset_url('chat-image-review.js')) ?>" defer></script><?php endif; ?>
</body>
</html>
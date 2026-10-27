<?php
// 2026-08-23：账号安全页只展示当前会话管理员的非敏感资料，并使用本地图标控制密码显示。
$admin = isset($admin) && is_array($admin) ? $admin : [];
$roleLabels = [
    'super_admin' => '超级管理员',
    'operations' => '运营管理员',
    'finance' => '财务管理员',
    'viewer' => '只读账号',
];
$adminStatus = status_meta($admin['status'] ?? 'active');
$mustChangePassword = !empty($admin['must_change_password']);
$displayName = (string) ($admin['real_name'] ?? '');
if ($displayName === '') {
    $displayName = (string) ($admin['username'] ?? '管理员');
}
?>

<div class="admin-page-head">
    <div>
        <h1>账号安全</h1>
        <p>查看当前管理员账号信息并定期更新登录密码。</p>
    </div>
    <div class="admin-page-actions"><span class="admin-badge admin-badge--<?= e($adminStatus[1]) ?>"><?= e($adminStatus[0]) ?></span></div>
</div>

<?php if ($mustChangePassword): ?>
    <div class="admin-alert admin-alert--warning">
        <div class="admin-alert__body">
            <strong>首次登录必须修改密码</strong>
            修改演示或初始密码后，才能进入其他后台功能。
        </div>
    </div>
<?php endif; ?>

<div class="admin-grid admin-grid--2">
    <section class="admin-card">
        <header class="admin-card__header">
            <div class="admin-card__title"><h3>账号资料</h3><p>账号名称和权限角色由系统管理员维护</p></div>
        </header>
        <div class="admin-card__body">
            <div class="admin-media">
                <span class="admin-avatar" aria-hidden="true"><?= e(mb_substr($displayName, 0, 1)) ?></span>
                <span class="admin-media__body"><strong><?= e($displayName) ?></strong><small class="admin-table__secondary"><?= e($roleLabels[$admin['role'] ?? ''] ?? ($admin['role'] ?? '未分配角色')) ?></small></span>
            </div>
            <dl class="admin-kv admin-mt-3">
                <dt>登录账号</dt><dd class="admin-mono"><?= e($admin['username'] ?? '—') ?></dd>
                <dt>姓名</dt><dd><?= e($admin['real_name'] ?? '未填写') ?></dd>
                <dt>权限角色</dt><dd><?= e($roleLabels[$admin['role'] ?? ''] ?? ($admin['role'] ?? '—')) ?></dd>
                <dt>账号状态</dt><dd><span class="admin-badge admin-badge--<?= e($adminStatus[1]) ?>"><?= e($adminStatus[0]) ?></span></dd>
                <dt>最近登录</dt><dd><?= e(local_datetime($admin['last_login_at'] ?? null)) ?></dd>
                <dt>登录地址</dt><dd class="admin-mono"><?= e($admin['last_login_ip'] ?? '—') ?></dd>
                <dt>创建时间</dt><dd><?= e(local_datetime($admin['created_at'] ?? null)) ?></dd>
            </dl>
        </div>
    </section>

    <section class="admin-card">
        <header class="admin-card__header">
            <div class="admin-card__title"><h3>修改登录密码</h3><p>新密码至少 10 位，并同时包含字母和数字</p></div>
        </header>
        <form class="admin-card__body" method="post" action="<?= e(admin_url('profile.password')) ?>" data-loading>
            <?= csrf()->input() ?>
            <div class="admin-field">
                <label class="admin-label" for="current-password">当前密码 <span class="admin-required">*</span></label>
                <div class="admin-input-wrap">
                    <input class="admin-control" id="current-password" name="current_password" type="password" required autocomplete="current-password">
                    <button class="admin-input-action" type="button" data-password-toggle="current-password" aria-label="显示当前密码" aria-pressed="false"><i class="ri-eye-line"></i></button>
                </div>
            </div>
            <div class="admin-field">
                <label class="admin-label" for="new-password">新密码 <span class="admin-required">*</span></label>
                <div class="admin-input-wrap">
                    <input class="admin-control" id="new-password" name="password" type="password" minlength="10" pattern="(?=.*[A-Za-z])(?=.*\d).{10,}" required autocomplete="new-password" aria-describedby="password-help">
                    <button class="admin-input-action" type="button" data-password-toggle="new-password" aria-label="显示新密码" aria-pressed="false"><i class="ri-eye-line"></i></button>
                </div>
                <p class="admin-help" id="password-help">建议使用 12 位以上且与其他网站不同的密码，不要通过聊天工具传递。</p>
            </div>
            <div class="admin-field">
                <label class="admin-label" for="password-confirmation">再次输入新密码 <span class="admin-required">*</span></label>
                <div class="admin-input-wrap">
                    <input class="admin-control" id="password-confirmation" name="password_confirmation" type="password" minlength="10" required autocomplete="new-password">
                    <button class="admin-input-action" type="button" data-password-toggle="password-confirmation" aria-label="显示确认密码" aria-pressed="false"><i class="ri-eye-line"></i></button>
                </div>
            </div>
            <div class="admin-form-actions">
                <button class="admin-btn admin-btn--primary" type="submit" data-loading-text="正在修改…" data-confirm="修改后请使用新密码登录，确认继续？" data-confirm-type="primary">确认修改密码</button>
            </div>
        </form>
    </section>
</div>

<section class="admin-card admin-mt-3">
    <header class="admin-card__header"><div class="admin-card__title"><h3>安全建议</h3><p>降低后台账号被盗用的风险</p></div></header>
    <div class="admin-card__body admin-grid admin-grid--3">
        <div class="admin-alert"><div class="admin-alert__body"><strong>独立密码</strong>不要与宝塔面板、数据库或微信商户平台共用密码。</div></div>
        <div class="admin-alert admin-alert--success"><div class="admin-alert__body"><strong>核对域名</strong>仅通过正式 HTTPS 后台地址登录，避免在未知页面输入账号。</div></div>
        <div class="admin-alert admin-alert--warning"><div class="admin-alert__body"><strong>及时退出</strong>在公共或临时设备处理完订单后立即退出后台。</div></div>
    </div>
</section>

<?php

declare(strict_types=1);

// 2026-08-14：安装入口完全独立运行，避免未安装阶段复用后台会话和数据库配置。
$projectRoot = dirname(__DIR__);
date_default_timezone_set('Asia/Shanghai');
header_remove('X-Powered-By');
require_once __DIR__ . '/Installer.php';

// 2026-08-14：只允许本机可信反向代理提供 HTTPS 协议头，禁止公网客户端伪造请求头绕过加密安装要求。
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$fromTrustedLocalProxy = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$httpsFlag = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
$directHttps = $httpsFlag !== '' && $httpsFlag !== 'off' && $httpsFlag !== '0';
$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
$requestSecure = $directHttps || ($fromTrustedLocalProxy && $forwardedProto === 'https');

// 2026-08-14：安装阶段使用独立会话令牌，防止跨站提交数据库凭据。
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('wall_worker_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $requestSecure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}
if (!isset($_SESSION['installer_csrf']) || !is_string($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

// 2026-08-14：安装令牌阻止公网访客借安装器探测内网数据库或抢先创建管理员。
$environmentToken = getenv('WORKER_INSTALL_TOKEN');
$packagedToken = require __DIR__ . '/install-token.php';
$expectedInstallToken = is_string($environmentToken) && strlen($environmentToken) >= 32
    ? $environmentToken
    : (string) $packagedToken;

$installer = new WallWorkerInstaller($projectRoot);
$checks = $installer->environmentChecks();
$environmentReady = true;
foreach ($checks as $check) {
    if (!$check['passed']) {
        $environmentReady = false;
        break;
    }
}

// 2026-08-14：网页安装始终要求真实 HTTPS，本机来源也不能绕过数据库与管理员密码的传输保护。
$secureForInstall = $requestSecure;
$requestHost = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'worker.20m.cc'));
$defaultSiteUrl = ($requestSecure ? 'https://' : 'http://') . ($requestHost !== '' ? $requestHost : 'worker.20m.cc');
$values = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'worker',
    'db_username' => 'worker',
    'site_name' => '墙布师傅管理后台',
    'site_url' => $defaultSiteUrl,
    'admin_username' => 'admin',
    'admin_real_name' => '超级管理员',
];
$error = '';
$success = null;
// 2026-08-14：安装页面版本与上传的软件包 VERSION 保持一致。
$versionFile = $projectRoot . '/VERSION';
$installVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '2.2.2';
if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$/', $installVersion) !== 1) {
    // 2026-09-15：安装页异常回退版本与当前完整包保持一致。
    $installVersion = '2.2.2';
}

// 2026-08-14：同一入口同时支持普通表单和前端 fetch JSON 响应。
$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
$wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
    || (string) ($_POST['response_format'] ?? '') === 'json';

if ($isPost) {
    foreach (array_keys($values) as $field) {
        if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
            $values[$field] = trim((string) $_POST[$field]);
        }
    }

    try {
        $submittedToken = isset($_POST['_csrf']) && is_string($_POST['_csrf']) ? $_POST['_csrf'] : '';
        if ($submittedToken === '' || !hash_equals($_SESSION['installer_csrf'], $submittedToken)) {
            throw new RuntimeException('页面安全令牌已失效，请刷新后重试。');
        }
        $installToken = isset($_POST['install_token']) && is_string($_POST['install_token']) ? trim($_POST['install_token']) : '';
        if ($installToken === '' || !hash_equals($expectedInstallToken, $installToken)) {
            throw new RuntimeException('安装安全令牌不正确。请查看服务器 install/install-token.php，或使用宝塔环境变量中配置的令牌。');
        }
        if (!$secureForInstall) {
            throw new RuntimeException('公网安装必须使用 HTTPS，请先在宝塔为站点配置 SSL 证书。');
        }
        if (!$environmentReady) {
            throw new RuntimeException('服务器环境检查未通过，暂时不能安装。');
        }

        $success = $installer->install($_POST);
        unset($_SESSION['installer_csrf']);

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => '系统安装完成。', 'data' => $success], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        if ($wantsJson) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $error], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

// 2026-08-14：安装完成后只展示状态，不再渲染敏感配置表单。
$installed = $installer->isInstalled();
$hasArtifacts = $installer->hasInstallationArtifacts();
$adminUrl = rtrim($values['site_url'], '/') . '/admin';
if ($success !== null && isset($success['admin_url'])) {
    $adminUrl = (string) $success['admin_url'];
} elseif (is_file($projectRoot . '/config/installed.php')) {
    try {
        $installedConfig = require $projectRoot . '/config/installed.php';
        if (is_array($installedConfig)) {
            $configuredUrl = (string) ($installedConfig['app']['url'] ?? '');
            if ($configuredUrl !== '') {
                $adminUrl = rtrim($configuredUrl, '/') . '/admin';
            }
        }
    } catch (Throwable $exception) {
        // 2026-08-14：配置异常时保持默认链接，页面仍需显示安装锁状态。
    }
}

$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-{$nonce}'; script-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

// 2026-08-14：统一 HTML 转义，防止表单回显产生脚本注入。
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>墙布师傅 · 一键安装</title>
    <style nonce="<?= $escape($nonce) ?>">
        :root{color-scheme:light;--brand:#2f855a;--brand-dark:#226744;--ink:#17212b;--muted:#667085;--line:#e5e9ef;--bg:#f4f7f6;--card:#fff;--ok:#147d52;--bad:#c2413b;--warn:#9a6700;--shadow:0 18px 48px rgba(28,52,43,.10)}
        *{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 12% 0,#e4f5ec 0,transparent 32%),var(--bg);font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;color:var(--ink)}
        a{color:var(--brand);text-decoration:none}.shell{width:min(1080px,calc(100% - 32px));margin:38px auto 64px}.hero{display:flex;gap:24px;align-items:flex-end;justify-content:space-between;margin-bottom:22px}.brand{display:flex;gap:14px;align-items:center}.logo{width:48px;height:48px;border-radius:15px;display:grid;place-items:center;color:#fff;background:linear-gradient(145deg,#49a678,#26714e);font-size:23px;font-weight:800;box-shadow:0 10px 24px rgba(47,133,90,.22)}
        h1{font-size:28px;letter-spacing:-.5px;margin:0 0 4px}.sub{color:var(--muted);margin:0}.badge{display:inline-flex;align-items:center;gap:7px;border:1px solid #cfe6da;background:#edf9f3;color:var(--brand-dark);padding:7px 11px;border-radius:999px;font-weight:650}.dot{width:8px;height:8px;border-radius:50%;background:#32a46e;box-shadow:0 0 0 4px rgba(50,164,110,.12)}
        .layout{display:grid;grid-template-columns:320px minmax(0,1fr);gap:22px}.card{background:var(--card);border:1px solid rgba(220,227,224,.9);border-radius:18px;box-shadow:var(--shadow)}.checks{padding:22px;align-self:start;position:sticky;top:20px}.card-title{font-size:17px;font-weight:750;margin:0 0 5px}.card-note{margin:0 0 18px;color:var(--muted);font-size:13px}.check-list{display:grid;gap:10px}.check{display:grid;grid-template-columns:24px 1fr;gap:9px;padding:11px;border:1px solid var(--line);border-radius:12px;background:#fbfcfc}.check-icon{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:800}.check.ok .check-icon{background:#e7f7ef;color:var(--ok)}.check.bad .check-icon{background:#fff0ef;color:var(--bad)}.check-name{font-weight:700}.check-message{font-size:11px;color:var(--muted);word-break:break-all;margin-top:1px}
        .form-card{overflow:hidden}.stepbar{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--line);background:#fafcfb}.step{padding:16px;text-align:center;color:var(--muted);font-weight:650}.step strong{display:inline-grid;place-items:center;width:23px;height:23px;border-radius:50%;background:#edf1ef;margin-right:6px;color:#5c6b63}.step.active{color:var(--brand-dark);background:#f2faf6}.step.active strong{background:var(--brand);color:#fff}.form-body{padding:26px}.section{margin-bottom:27px}.section:last-of-type{margin-bottom:20px}.section-heading{font-size:16px;font-weight:750;margin:0 0 5px}.section-copy{font-size:12px;color:var(--muted);margin:0 0 15px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.field.full{grid-column:1/-1}.field label{display:block;margin-bottom:7px;font-weight:650;font-size:13px}.required{color:#d04a42;margin-left:3px}.input-wrap{position:relative}.field input{width:100%;height:45px;border:1px solid #d8dee5;border-radius:11px;background:#fff;padding:0 13px;color:var(--ink);font:inherit;outline:none;transition:.18s border,.18s box-shadow}.field input:focus{border-color:#55a87d;box-shadow:0 0 0 4px rgba(47,133,90,.10)}.hint{font-size:11px;color:var(--muted);margin-top:6px}.port-grid{display:grid;grid-template-columns:minmax(0,1fr) 105px;gap:10px}
        .notice{padding:13px 14px;border-radius:11px;margin-bottom:18px}.notice.error{background:#fff1f0;border:1px solid #ffd3d0;color:#982c28}.notice.success{background:#ebf9f2;border:1px solid #c9ebda;color:#176b49}.notice.warning{background:#fff8e7;border:1px solid #f0dfae;color:#805d12}.actions{display:flex;gap:12px;justify-content:flex-end;align-items:center;padding-top:18px;border-top:1px solid var(--line)}.button{border:0;border-radius:11px;min-height:45px;padding:0 21px;font:inherit;font-weight:750;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px}.button.primary{background:linear-gradient(145deg,#3d9869,#28754e);color:#fff;box-shadow:0 9px 20px rgba(47,133,90,.20)}.button.primary:hover{background:linear-gradient(145deg,#368a60,#226744)}.button:disabled{cursor:not-allowed;opacity:.55;box-shadow:none}.button.secondary{background:#eef5f1;color:var(--brand-dark)}.spinner{display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:spin .75s linear infinite}.loading .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
        .done{padding:42px 28px;text-align:center}.done-icon{width:74px;height:74px;border-radius:24px;margin:0 auto 18px;display:grid;place-items:center;background:#e8f8f0;color:var(--ok);font-size:36px;font-weight:800}.done-icon.warning-icon{background:#fff8e7;color:#9a6700}.done h2{font-size:24px;margin:0 0 8px}.done p{color:var(--muted);margin:0 auto 23px;max-width:560px}.foot{text-align:center;margin-top:18px;color:#89938d;font-size:12px}
        @media(max-width:850px){.hero{align-items:flex-start;flex-direction:column}.layout{grid-template-columns:1fr}.checks{position:static}.check-list{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.shell{width:min(100% - 20px,1080px);margin-top:20px}.grid,.check-list{grid-template-columns:1fr}.form-body{padding:20px}.step{font-size:12px;padding:13px 5px}.step strong{display:none}.port-grid{grid-template-columns:1fr 90px}.actions{flex-direction:column}.button{width:100%}}
    </style>
</head>
<body>
<main class="shell">
    <header class="hero">
        <div class="brand">
            <div class="logo">墙</div>
            <div><h1>墙布师傅后台安装</h1><p class="sub">连接现有 MySQL，自动建表并创建首位超级管理员</p></div>
        </div>
        <span class="badge"><span class="dot"></span>安装向导 <?= $escape($installVersion) ?></span>
    </header>

    <div class="layout">
        <aside class="card checks">
            <h2 class="card-title">服务器环境</h2>
            <p class="card-note">全部通过后才能开始安装。</p>
            <div class="check-list">
                <?php foreach ($checks as $check): ?>
                    <div class="check <?= $check['passed'] ? 'ok' : 'bad' ?>">
                        <span class="check-icon"><?= $check['passed'] ? '✓' : '!' ?></span>
                        <div><div class="check-name"><?= $escape($check['name']) ?></div><div class="check-message"><?= $escape($check['message']) ?></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="card form-card">
            <?php if ($installed || $success !== null): ?>
                <div class="done">
                    <div class="done-icon">✓</div>
                    <h2>系统已安装完成</h2>
                    <p>数据库、超级管理员和安装锁均已创建。安装入口现已锁定，可以进入后台继续配置。</p>
                    <a class="button primary" href="<?= $escape($adminUrl) ?>">进入管理后台</a>
                </div>
            <?php elseif ($hasArtifacts): ?>
                <div class="done">
                    <div class="done-icon warning-icon">!</div>
                    <h2>检测到不完整的安装凭据</h2>
                    <p>配置文件或安装锁已经存在。为避免覆盖线上数据，网页重装已被禁止，请先由服务器管理员备份并检查。</p>
                    <a class="button secondary" href="/admin">尝试进入后台</a>
                </div>
            <?php else: ?>
                <div class="stepbar"><div class="step active"><strong>1</strong>环境检查</div><div class="step active"><strong>2</strong>填写配置</div><div class="step"><strong>3</strong>完成部署</div></div>
                <form id="install-form" method="post" class="form-body" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= $escape($_SESSION['installer_csrf']) ?>">
                    <input type="hidden" name="response_format" value="html">

                    <div id="result-box">
                        <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
                        <?php if (!$environmentReady): ?><div class="notice warning">请先在宝塔面板修复左侧未通过的环境项目，然后刷新本页。</div><?php endif; ?>
                        <?php if (!$secureForInstall): ?><div class="notice error">当前页面未使用 HTTPS，安装提交已禁用。请先在宝塔配置 SSL 证书，避免数据库和管理员密码明文传输。</div><?php endif; ?>
                    </div>

                    <section class="section">
                        <h2 class="section-heading">安装授权</h2>
                        <p class="section-copy">令牌位于服务器的 install/install-token.php，也可以通过 WORKER_INSTALL_TOKEN 环境变量覆盖。</p>
                        <div class="grid"><div class="field full"><label>安装安全令牌<span class="required">*</span></label><input type="password" name="install_token" required minlength="32" maxlength="256" autocomplete="one-time-code"><div class="hint">令牌仅用于本次安装验证，不会写入数据库。</div></div></div>
                    </section>

                    <section class="section">
                        <h2 class="section-heading">数据库连接</h2>
                        <p class="section-copy">请先在宝塔创建数据库和账号；安装器不会删除已有数据。</p>
                        <div class="grid">
                            <div class="field full"><label>数据库主机<span class="required">*</span></label><div class="port-grid"><input name="db_host" required value="<?= $escape($values['db_host']) ?>" placeholder="127.0.0.1"><input name="db_port" required inputmode="numeric" value="<?= $escape($values['db_port']) ?>" aria-label="数据库端口"></div></div>
                            <div class="field"><label>数据库名<span class="required">*</span></label><input name="db_name" required value="<?= $escape($values['db_name']) ?>" autocomplete="off"></div>
                            <div class="field"><label>数据库账号<span class="required">*</span></label><input name="db_username" required value="<?= $escape($values['db_username']) ?>" autocomplete="off"></div>
                            <div class="field full"><label>数据库密码<span class="required">*</span></label><input type="password" name="db_password" required autocomplete="new-password"><div class="hint">密码仅写入服务器本地配置文件，不会在页面回显。</div></div>
                        </div>
                    </section>

                    <section class="section">
                        <h2 class="section-heading">站点信息</h2>
                        <p class="section-copy">正式环境建议使用已配置 HTTPS 的 worker.20m.cc。</p>
                        <div class="grid">
                            <div class="field"><label>站点名称<span class="required">*</span></label><input name="site_name" required value="<?= $escape($values['site_name']) ?>"></div>
                            <div class="field"><label>站点 URL<span class="required">*</span></label><input type="url" name="site_url" required value="<?= $escape($values['site_url']) ?>" placeholder="https://worker.20m.cc"></div>
                        </div>
                    </section>

                    <section class="section">
                        <h2 class="section-heading">超级管理员</h2>
                        <p class="section-copy">安装完成后请妥善保管账号，并只向可信管理员授权。</p>
                        <div class="grid">
                            <div class="field"><label>登录账号<span class="required">*</span></label><input name="admin_username" required value="<?= $escape($values['admin_username']) ?>" autocomplete="username"><div class="hint">字母开头，4 至 32 位。</div></div>
                            <div class="field"><label>管理员姓名<span class="required">*</span></label><input name="admin_real_name" required value="<?= $escape($values['admin_real_name']) ?>"></div>
                            <div class="field"><label>登录密码<span class="required">*</span></label><input type="password" name="admin_password" required minlength="10" maxlength="72" autocomplete="new-password"></div>
                            <div class="field"><label>确认密码<span class="required">*</span></label><input type="password" name="admin_password_confirmation" required minlength="10" maxlength="72" autocomplete="new-password"></div>
                        </div>
                    </section>

                    <div class="actions"><span class="hint">点击后将执行 database.sql 并锁定安装入口</span><button id="install-button" class="button primary" type="submit" <?= ($environmentReady && $secureForInstall) ? '' : 'disabled' ?>><span class="spinner"></span><span class="button-label">一键安装</span></button></div>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <footer class="foot">墙布师傅管理系统 · 安装完成后请保留 install.lock，且不要公开 config 目录</footer>
</main>

<?php if (!$installed && !$hasArtifacts && $success === null): ?>
<script nonce="<?= $escape($nonce) ?>">
(() => {
    const form = document.getElementById('install-form');
    const button = document.getElementById('install-button');
    const resultBox = document.getElementById('result-box');
    if (!form || !button || !window.fetch) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity() || button.disabled) return;
        button.disabled = true;
        button.classList.add('loading');
        button.querySelector('.button-label').textContent = '正在安装，请勿关闭';
        resultBox.innerHTML = '';
        const body = new FormData(form);
        body.set('response_format', 'json');
        try {
            const response = await fetch(window.location.href, {method: 'POST', body, headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const payload = await response.json();
            if (!response.ok || !payload.success) throw new Error(payload.message || '安装失败');
            const box = document.createElement('div');
            box.className = 'notice success';
            box.textContent = payload.message || '系统安装完成，即将进入管理后台。';
            resultBox.appendChild(box);
            window.setTimeout(() => { window.location.href = payload.data.admin_url; }, 900);
        } catch (error) {
            const box = document.createElement('div');
            box.className = 'notice error';
            box.setAttribute('role', 'alert');
            box.textContent = error instanceof Error ? error.message : '安装失败，请重试。';
            resultBox.appendChild(box);
            button.disabled = false;
            button.classList.remove('loading');
            button.querySelector('.button-label').textContent = '重新安装';
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>

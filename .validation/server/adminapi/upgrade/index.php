<?php

declare(strict_types=1);

use WorkerAdmin\Services\UpgradeService;

// 2026-08-14：升级入口使用主后台会话、数据库和安全帮助函数。
$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/bootstrap.php';
require_once $projectRoot . '/app/Services/UpgradeService.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');

// 2026-08-14：API 调用返回 JSON 状态码，浏览器访问则引导到登录页。
$wantsJson = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
    || (string) ($_POST['response_format'] ?? '') === 'json';

if (!is_installed()) {
    if ($wantsJson) {
        json_response(['success' => false, 'message' => '系统尚未完成安装。'], 409);
    }
    redirect(app_url('install/'));
}

if (!auth()->check()) {
    if ($wantsJson) {
        json_response(['success' => false, 'message' => '登录状态已失效，请重新登录。'], 401);
    }
    redirect(admin_url('login'));
}

$admin = auth()->user();
if (!is_array($admin) || (string) ($admin['role'] ?? '') !== 'super_admin') {
    if ($wantsJson) {
        json_response(['success' => false, 'message' => '只有超级管理员可以执行系统升级。'], 403);
    }
    http_response_code(403);
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>无权访问</title><body style="font:16px/1.7 system-ui;padding:10vw;background:#f4f7f6;color:#24332b"><h1>无权访问</h1><p>只有超级管理员可以进入升级中心。</p><a href="' . e(admin_url('dashboard')) . '">返回后台</a></body></html>';
    exit;
}

$service = new UpgradeService(db()->pdo(), $projectRoot, (int) $admin['id']);

// 2026-08-14：去掉服务器绝对路径后再输出 JSON，避免泄露部署目录。
$publicPreflight = static function (array $preflight): array {
    if (isset($preflight['status']['pending']) && is_array($preflight['status']['pending'])) {
        foreach ($preflight['status']['pending'] as &$migration) {
            if (is_array($migration)) {
                unset($migration['path']);
            }
        }
        unset($migration);
    }
    return $preflight;
};

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    try {
        csrf()->verifyRequest();
        $action = (string) ($_POST['action'] ?? 'preflight');

        if ($action === 'preflight') {
            $preflight = $service->preflight();
            json_response([
                'success' => true,
                'message' => $preflight['ok'] ? '升级预检已通过。' : '升级预检存在未通过项目。',
                'data' => $publicPreflight($preflight),
            ]);
        }

        if ($action === 'apply') {
            // 2026-08-14：升级密码二次验证按会话限速，连续失败五次后锁定十五分钟。
            $reauthState = isset($_SESSION['_upgrade_reauth']) && is_array($_SESSION['_upgrade_reauth'])
                ? $_SESSION['_upgrade_reauth']
                : ['count' => 0, 'locked_until' => 0];
            if ((int) ($reauthState['locked_until'] ?? 0) > time()) {
                throw new RuntimeException('升级密码验证失败次数过多，请十五分钟后再试。');
            }
            $currentPassword = isset($_POST['admin_password']) && is_string($_POST['admin_password']) ? $_POST['admin_password'] : '';
            $passwordStatement = db()->pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = :id AND status = :status LIMIT 1');
            $passwordStatement->execute([':id' => (int) $admin['id'], ':status' => 'active']);
            $passwordHash = $passwordStatement->fetchColumn();
            if (!is_string($passwordHash) || $currentPassword === '' || !password_verify($currentPassword, $passwordHash)) {
                $reauthState['count'] = (int) ($reauthState['count'] ?? 0) + 1;
                if ($reauthState['count'] >= 5) {
                    $reauthState = ['count' => 0, 'locked_until' => time() + 900];
                }
                $_SESSION['_upgrade_reauth'] = $reauthState;
                throw new RuntimeException('当前管理员密码验证失败。');
            }
            unset($_SESSION['_upgrade_reauth']);
            if ((string) ($_POST['backup_confirmed'] ?? '') !== '1') {
                throw new RuntimeException('请先确认已完成数据库备份。');
            }
            $before = $service->status();
            $backupReference = trim((string) ($_POST['backup_reference'] ?? ''));
            if ($backupReference === '' || (function_exists('mb_strlen') ? mb_strlen($backupReference, 'UTF-8') : strlen($backupReference)) < 4) {
                throw new RuntimeException('请填写可追踪的宝塔数据库与代码备份标识。');
            }
            $result = $service->applyPending($backupReference);
            audit(
                'system.upgrade',
                'system_upgrade',
                null,
                $result['message'],
                ['version' => $before['current_version']],
                ['version' => $result['to_version'], 'applied' => $result['applied']]
            );
            json_response(['success' => true, 'message' => $result['message'], 'data' => $result]);
        }

        json_response(['success' => false, 'message' => '不支持的升级动作。'], 404);
    } catch (Throwable $exception) {
        write_log('upgrade', sprintf("[%s] admin_id=%d request_id=%s %s\n", date('c'), (int) $admin['id'], request_id(), $exception->getMessage()));
        json_response(['success' => false, 'message' => $exception->getMessage(), 'request_id' => request_id()], 422);
    }
}

// 2026-08-14：页面首屏直接展示预检结果，JavaScript 仅用于无刷新复检和执行。
$preflight = $service->preflight();
try {
    $history = $service->recentHistory(8);
} catch (Throwable $exception) {
    $history = [];
}
$status = $preflight['status'];
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'nonce-{$nonce}'; script-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
// 2026-08-14：数据库统一保存 UTC，升级记录按后台配置时区展示。
$displayTime = static function ($value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        $time = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
        return $time->setTimezone(new DateTimeZone((string) app_config('app.timezone', 'Asia/Shanghai')))->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        return (string) $value;
    }
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>系统升级 · <?= $escape(app_config('app.name', '墙布师傅管理后台')) ?></title>
    <style nonce="<?= $escape($nonce) ?>">
        :root{--brand:#2f855a;--brand-dark:#236a47;--ink:#17221c;--muted:#68756e;--line:#e3e9e6;--bg:#f3f6f4;--card:#fff;--ok:#197451;--bad:#bd3d38;--warn:#986500;--shadow:0 18px 44px rgba(32,61,47,.09)}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 90% -10%,#dbf1e5 0,transparent 30%),var(--bg);color:var(--ink);font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}a{color:inherit;text-decoration:none}.shell{width:min(1120px,calc(100% - 34px));margin:32px auto 60px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:22px}.eyebrow{font-size:12px;color:var(--brand);font-weight:800;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px}.topbar h1{margin:0;font-size:29px;letter-spacing:-.5px}.top-actions{display:flex;gap:9px}.button{border:0;border-radius:11px;min-height:42px;padding:0 17px;display:inline-flex;align-items:center;justify-content:center;gap:7px;font:inherit;font-weight:750;cursor:pointer}.button.secondary{background:#fff;border:1px solid var(--line);color:#34453c}.button.primary{color:#fff;background:linear-gradient(145deg,#3c9868,#28764f);box-shadow:0 9px 20px rgba(47,133,90,.21)}.button.danger{color:#fff;background:linear-gradient(145deg,#d5534c,#b83732);box-shadow:0 9px 20px rgba(184,55,50,.16)}.button:disabled{opacity:.48;cursor:not-allowed;box-shadow:none}.spinner{display:none;width:15px;height:15px;border:2px solid rgba(255,255,255,.42);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}.loading .spinner{display:inline-block}@keyframes spin{to{transform:rotate(360deg)}}
        .version-card{padding:26px;border-radius:20px;background:linear-gradient(125deg,#183a2b,#276247);color:#fff;box-shadow:0 22px 48px rgba(25,65,45,.17);display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:24px}.version-block:last-child{text-align:right}.version-label{color:#c7ddcf;font-size:12px;margin-bottom:5px}.version-number{font-size:29px;font-weight:800;letter-spacing:.5px}.arrow{width:48px;height:48px;border-radius:16px;background:rgba(255,255,255,.12);display:grid;place-items:center;font-size:23px}.version-summary{grid-column:1/-1;padding-top:16px;border-top:1px solid rgba(255,255,255,.15);display:flex;justify-content:space-between;gap:16px;color:#d6e7dc}.pill{border-radius:999px;padding:5px 10px;background:rgba(255,255,255,.12);font-weight:700;color:#fff}
        .grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:20px;margin-top:20px}.card{background:var(--card);border:1px solid rgba(220,228,223,.9);border-radius:18px;box-shadow:var(--shadow);padding:22px}.card h2{font-size:17px;margin:0 0 5px}.card-note{margin:0 0 17px;color:var(--muted);font-size:12px}.checks{display:grid;gap:9px}.check{display:grid;grid-template-columns:28px 1fr auto;align-items:center;gap:9px;padding:11px 12px;border:1px solid var(--line);border-radius:12px;background:#fbfcfb}.check-icon{width:25px;height:25px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:850}.check.ok .check-icon{color:var(--ok);background:#e7f7ef}.check.bad .check-icon{color:var(--bad);background:#fff0ef}.check-name{font-weight:700}.check-message{font-size:11px;color:var(--muted);text-align:right;max-width:240px;overflow-wrap:anywhere}.migration-list{display:grid;gap:10px}.migration{border:1px solid var(--line);border-radius:12px;padding:13px;display:flex;justify-content:space-between;gap:12px;align-items:center}.migration-name{font-weight:750}.migration-file{color:var(--muted);font-size:11px;margin-top:2px}.tag{font-size:11px;font-weight:800;padding:5px 8px;border-radius:999px;background:#edf7f1;color:var(--brand-dark);white-space:nowrap}.empty{border:1px dashed #cfdad4;border-radius:13px;padding:24px;text-align:center;color:var(--muted);background:#fbfdfc}.upgrade-form{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}.field{margin-bottom:13px}.field label{display:block;font-weight:700;margin-bottom:7px}.field input[type=text],.field input[type=password]{width:100%;height:42px;border:1px solid #d7dfda;border-radius:10px;padding:0 12px;font:inherit;outline:none}.field input[type=text]:focus,.field input[type=password]:focus{border-color:#5ca981;box-shadow:0 0 0 4px rgba(47,133,90,.09)}.confirm{display:flex;gap:9px;align-items:flex-start;padding:12px;background:#fff8e9;border:1px solid #efdfb5;border-radius:11px;color:#745313;margin-bottom:15px}.confirm input{margin-top:3px}.form-actions{display:flex;gap:9px;justify-content:flex-end}.result{display:none;margin-bottom:16px;padding:12px 14px;border-radius:11px}.result.show{display:block}.result.ok{background:#eaf8f1;border:1px solid #c8ead9;color:#176b49}.result.bad{background:#fff0ef;border:1px solid #f2cfcc;color:#962d29}
        .history{margin-top:20px;padding:0;overflow:hidden}.history-head{padding:20px 22px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center}.history-head h2{margin:0;font-size:17px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:720px}th,td{text-align:left;padding:13px 18px;border-bottom:1px solid #edf0ee;vertical-align:top}th{font-size:11px;color:var(--muted);background:#fafcfb;text-transform:uppercase;letter-spacing:.4px}tbody tr:last-child td{border-bottom:0}.state{font-size:11px;font-weight:800;border-radius:999px;padding:5px 8px;display:inline-block}.state.success{background:#e7f7ef;color:var(--ok)}.state.failed{background:#fff0ef;color:var(--bad)}.state.running{background:#fff6df;color:var(--warn)}.muted{color:var(--muted);font-size:12px}.foot{text-align:center;color:#89938d;font-size:12px;margin-top:18px}
        @media(max-width:820px){.grid{grid-template-columns:1fr}.topbar{align-items:flex-start}.check{grid-template-columns:28px 1fr}.check-message{grid-column:2;text-align:left;max-width:none}.version-card{gap:14px;padding:21px}}@media(max-width:560px){.shell{width:calc(100% - 20px);margin-top:20px}.topbar{display:block}.top-actions{margin-top:13px}.top-actions .button{flex:1}.version-number{font-size:22px}.arrow{width:36px;height:36px;border-radius:12px}.version-summary{display:block}.version-summary .pill{display:inline-block;margin-top:8px}.card{padding:18px}.form-actions{flex-direction:column}.form-actions .button{width:100%}}
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <div><div class="eyebrow">System maintenance</div><h1>系统升级中心</h1></div>
        <div class="top-actions"><a class="button secondary" href="<?= $escape(admin_url('dashboard')) ?>">返回后台</a><button id="preflight-button" class="button secondary" type="button">重新预检</button></div>
    </header>

    <section class="version-card">
        <div class="version-block"><div class="version-label">当前数据库版本</div><div id="current-version" class="version-number"><?= $escape($status['current_version']) ?></div></div>
        <div class="arrow">→</div>
        <div class="version-block"><div class="version-label">安装包目标版本</div><div id="target-version" class="version-number"><?= $escape($status['target_version']) ?></div></div>
        <div class="version-summary"><span>配置版本：<b id="configured-version"><?= $escape($status['configured_version']) ?></b>　代码版本：<b><?= $escape($status['code_version']) ?></b></span><span id="pending-pill" class="pill"><?= (int) $status['pending_count'] ?> 个待升级版本<?= $status['configuration_sync_required'] ? ' · 配置待同步' : '' ?></span></div>
    </section>

    <div class="grid">
        <section class="card">
            <h2>升级预检</h2><p class="card-note">数据库、目录权限和脚本校验全部通过后才允许执行。</p>
            <div id="check-list" class="checks">
                <?php foreach ($preflight['checks'] as $check): ?>
                    <div class="check <?= $check['passed'] ? 'ok' : 'bad' ?>"><span class="check-icon"><?= $check['passed'] ? '✓' : '!' ?></span><span class="check-name"><?= $escape($check['name']) ?></span><span class="check-message"><?= $escape($check['message']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card">
            <h2>待执行版本</h2><p class="card-note">脚本按版本顺序执行，每个文件都记录 SHA-256 校验值。</p>
            <div id="migration-list" class="migration-list">
                <?php if (empty($status['pending'])): ?><div class="empty"><?= $status['configuration_sync_required'] ? '数据库已升级，但配置版本尚未同步；验证备份和密码后可一键修复。' : '当前已是最新版本，无需执行升级。' ?></div><?php endif; ?>
                <?php foreach ($status['pending'] as $migration): ?>
                    <div class="migration"><div><div class="migration-name"><?= $escape($migration['name']) ?></div><div class="migration-file"><?= $escape($migration['file']) ?><?php if(!empty($migration['resume_statement'])): ?> · 从原第 <?= (int)$migration['resume_statement'] ?> 条继续，已建表步骤不重跑<?php endif; ?></div></div><span class="tag">v<?= $escape($migration['version']) ?></span></div>
                <?php endforeach; ?>
            </div>

            <form id="upgrade-form" class="upgrade-form" method="post">
                <div id="result" class="result" role="status"></div>
                <input type="hidden" name="_token" value="<?= $escape(csrf()->token()) ?>">
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="response_format" value="json">
                <div class="field"><label for="backup-reference">数据库与代码备份标识</label><input id="backup-reference" type="text" name="backup_reference" minlength="4" maxlength="255" required placeholder="例如：宝塔备份 2026-08-14 22:00"></div>
                <div class="field"><label for="admin-password">当前管理员密码</label><input id="admin-password" type="password" name="admin_password" minlength="10" maxlength="72" required autocomplete="current-password" placeholder="执行前再次验证身份"></div>
                <label class="confirm"><input type="checkbox" name="backup_confirmed" value="1" required><span>我已在宝塔完成数据库、当前代码和私有文件备份，并了解 MySQL DDL 失败时无法整体回滚。</span></label>
                <div class="form-actions"><button id="apply-button" class="button primary" type="submit" <?= (!$preflight['ok'] || ((int) $status['pending_count'] === 0 && !$status['configuration_sync_required'])) ? 'disabled' : '' ?>><span class="spinner"></span><span class="button-label"><?= $status['configuration_sync_required'] && (int) $status['pending_count'] === 0 ? '同步配置版本' : '一键升级' ?></span></button></div>
            </form>
        </section>
    </div>

    <section class="card history">
        <div class="history-head"><h2>最近升级记录</h2><span class="muted">执行人：<?= $escape($admin['real_name'] ?? $admin['username']) ?></span></div>
        <div class="table-wrap"><table><thead><tr><th>版本</th><th>状态</th><th>升级包</th><th>开始时间</th><th>完成时间</th><th>备份标识</th></tr></thead><tbody>
            <?php if (empty($history)): ?><tr><td colspan="6" class="muted">暂无升级记录</td></tr><?php endif; ?>
            <?php foreach ($history as $record): ?>
                <tr><td><b><?= $escape($record['from_version']) ?> → <?= $escape($record['to_version']) ?></b></td><td><span class="state <?= $escape($record['status']) ?>"><?= $escape($record['status']) ?></span></td><td><?= $escape($record['package_name']) ?><?php if (!empty($record['error_message'])): ?><div class="muted"><?= $escape($record['error_message']) ?></div><?php endif; ?></td><td><?= $escape($displayTime($record['started_at'])) ?></td><td><?= $escape($displayTime($record['finished_at'])) ?></td><td><?= $escape($record['backup_reference'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <footer class="foot">升级期间请勿关闭页面或同时执行其他数据库维护操作 · 所有结果均写入升级历史和审计日志</footer>
</main>

<script nonce="<?= $escape($nonce) ?>">
(() => {
    const endpoint = window.location.href;
    const token = <?= json_encode(csrf()->token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const result = document.getElementById('result');
    const applyButton = document.getElementById('apply-button');
    const preflightButton = document.getElementById('preflight-button');
    const form = document.getElementById('upgrade-form');
    const showResult = (message, ok) => { result.textContent = message; result.className = `result show ${ok ? 'ok' : 'bad'}`; };
    const post = async (data) => {
        const response = await fetch(endpoint, {method:'POST',body:data,headers:{'Accept':'application/json','X-CSRF-Token':token},credentials:'same-origin'});
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || '请求失败');
        return payload;
    };
    preflightButton.addEventListener('click', async () => {
        preflightButton.disabled = true;
        try {
            const data = new FormData(); data.set('action','preflight'); data.set('response_format','json'); data.set('_token',token);
            const payload = await post(data); showResult(payload.message, payload.data.ok);
            window.setTimeout(() => window.location.reload(), 600);
        } catch (error) { showResult(error instanceof Error ? error.message : '预检失败', false); preflightButton.disabled = false; }
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity() || applyButton.disabled) return;
        applyButton.disabled = true; applyButton.classList.add('loading'); applyButton.querySelector('.button-label').textContent = '正在升级，请勿关闭';
        try {
            const payload = await post(new FormData(form)); showResult(payload.message, true);
            window.setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            showResult(error instanceof Error ? error.message : '升级失败，请查看日志', false);
            form.querySelector('[name="admin_password"]').value = '';
            applyButton.disabled = false; applyButton.classList.remove('loading'); applyButton.querySelector('.button-label').textContent = '重新升级';
        }
    });
})();
</script>
</body>
</html>

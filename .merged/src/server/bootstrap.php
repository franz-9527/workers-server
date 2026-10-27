<?php

// 2026-08-14：统一初始化配置、自动加载、会话和异常日志，未安装时也能安全加载。
if (!defined('WORKER_BASE_PATH')) {
    define('WORKER_BASE_PATH', __DIR__);
}

// 2026-08-14：动态入口统一移除 PHP 版本响应头，减少不必要的运行环境暴露。
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

require_once WORKER_BASE_PATH . '/app/Support/functions.php';

spl_autoload_register(function ($class) {
    $prefix = 'WorkerAdmin\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = WORKER_BASE_PATH . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$defaults = require WORKER_BASE_PATH . '/config/defaults.php';
$installedFile = WORKER_BASE_PATH . '/config/installed.php';
$installed = is_file($installedFile) ? require $installedFile : [];
if (!is_array($installed)) {
    $installed = [];
}

$GLOBALS['worker_admin_config'] = array_replace_recursive($defaults, $installed);
$versionFile = WORKER_BASE_PATH . '/VERSION';
$codeVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
$configuredVersion = (string) ($installed['app']['version'] ?? $installed['version'] ?? '');
$hasInstalledConfig = !empty($installed['installed']);
$databaseVersion = '';
$databaseVersionKnown = false;
if ($hasInstalledConfig && $codeVersion !== '') {
    try {
        $database = db();
        if ($database->tableExists('schema_migrations')) {
            $databaseVersionKnown = true;
            $databaseVersion = trim((string) $database->fetchValue("SELECT version FROM schema_migrations WHERE status='success' ORDER BY id DESC LIMIT 1"));
        } else {
            // 已安装配置存在但升级审计表缺失，不能让新代码继续访问订单业务。
            $databaseVersionKnown = true;
            $databaseVersion = '0.0.0';
        }
    } catch (Throwable $exception) {
        // 数据库连接失败交由实际 API/健康检查返回，不在启动阶段伪造一个版本结论。
    }
}
$GLOBALS['worker_admin_database_version'] = $databaseVersion;
// 2026-09-14：同时核对代码、安装配置和最后一次成功迁移，避免配置版本已写新但数据库结构仍旧时进入订单控制器。
$GLOBALS['worker_admin_upgrade_required'] = $hasInstalledConfig
    && ($codeVersion === '' || $configuredVersion === '' || !hash_equals($configuredVersion, $codeVersion)
        || ($databaseVersionKnown && ($databaseVersion === '' || version_compare($databaseVersion, $codeVersion, '<'))));
if ($codeVersion !== '') {
    $GLOBALS['worker_admin_config']['app']['version'] = $codeVersion;
}

date_default_timezone_set((string) app_config('app.timezone', 'Asia/Shanghai'));

// 2026-10-12：日志维护最多每小时执行一次；达到 8 MB 的活跃日志在请求结束后滚动归档，旧归档自动收敛。
register_shutdown_function(static function (): void {
    try {
        \WorkerAdmin\Infrastructure\Persistence\RuntimeLogMaintenance::maintain(base_path('storage/logs'));
    } catch (Throwable $exception) {
        // 日志维护绝不能影响业务响应，也不能递归写日志。
    }
});

// 2026-08-14：集中输出维护响应，初次检查和取得请求屏障后的二次检查使用同一安全口径。
$rejectMaintenanceRequest = function ($upgradeRequired) use ($codeVersion) {
    $scriptName = str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '');
    http_response_code(503);
    header('Retry-After: 120');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (strpos($scriptName, '/api/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'code' => 'SYSTEM_MAINTENANCE',
            'message' => $upgradeRequired
                ? ('系统代码已更新，等待管理员完成数据库升级' . ($codeVersion !== '' ? '至 V' . $codeVersion : '') . '。')
                : '系统正在升级维护，请稍后重试。',
            'request_id' => request_id(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>系统维护中</title><style>body{margin:0;font:15px/1.7 system-ui;background:#f4f7f9;color:#263238;padding:10vh 24px}'
            . '.box{max-width:620px;margin:auto;background:#fff;padding:34px;border-radius:18px;box-shadow:0 16px 50px #15303b18}'
            . 'h1{font-size:24px;margin:0 0 12px;color:#15343c}b{color:#00a7b8}</style><div class="box">'
            . '<h1>' . ($upgradeRequired ? '系统等待管理员升级' : '系统正在升级维护') . '</h1><p>为保证订单数据一致，当前暂时停止业务访问。请稍后刷新页面。</p>'
            . '<p>请求编号：<b>' . e(request_id()) . '</b></p></div></html>';
    }
    exit;
};

// 2026-08-14：在线升级或检测到代码/配置版本漂移时只放行升级入口；待升级阶段额外放行后台登录。
$maintenanceFile = base_path('storage/maintenance.lock');
$maintenanceLocked = is_file($maintenanceFile);
$upgradeRequired = !empty($GLOBALS['worker_admin_upgrade_required']);
$scriptName = PHP_SAPI === 'cli' ? '' : str_replace('\\', '/', isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '');
$isUpgradeRequest = PHP_SAPI !== 'cli' && strpos($scriptName, '/adminapi/upgrade/') !== false;
$isAdminEntry = PHP_SAPI !== 'cli' && !$maintenanceLocked && $upgradeRequired
    && (strpos($scriptName, '/admin/') !== false || substr($scriptName, -10) === '/admin.php');
if (PHP_SAPI !== 'cli' && ($maintenanceLocked || $upgradeRequired)) {
    if (!$isUpgradeRequest && !$isAdminEntry) {
        $rejectMaintenanceRequest($upgradeRequired);
    }
}

// 2026-08-14：普通已安装 Web 请求全程持有共享文件锁；升级独占该锁后才执行迁移，已通过启动检查的在途请求也不会与 DDL 并发。
if (PHP_SAPI !== 'cli' && $hasInstalledConfig && !$isUpgradeRequest) {
    $barrierFile = base_path('storage/upgrade-request.barrier');
    $barrierHandle = @fopen($barrierFile, 'c+');
    if ($barrierHandle === false || !@flock($barrierHandle, LOCK_SH | LOCK_NB)) {
        if (is_resource($barrierHandle)) {
            @fclose($barrierHandle);
        }
        $rejectMaintenanceRequest(false);
    }
    $GLOBALS['worker_admin_request_barrier'] = $barrierHandle;
    register_shutdown_function(function () use ($barrierHandle) {
        if (is_resource($barrierHandle)) {
            @flock($barrierHandle, LOCK_UN);
            @fclose($barrierHandle);
        }
    });

    clearstatcache(true, $maintenanceFile);
    clearstatcache(true, $installedFile);
    clearstatcache(true, $versionFile);
    $latestInstalled = is_file($installedFile) ? require $installedFile : [];
    $latestConfiguredVersion = is_array($latestInstalled)
        ? (string) ($latestInstalled['app']['version'] ?? $latestInstalled['version'] ?? '')
        : '';
    $latestCodeVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
    $latestUpgradeRequired = $latestCodeVersion === '' || $latestConfiguredVersion === '' || !hash_equals($latestConfiguredVersion, $latestCodeVersion);
    $latestMaintenanceLocked = is_file($maintenanceFile);
    $latestAdminEntry = !$latestMaintenanceLocked && $latestUpgradeRequired
        && (strpos($scriptName, '/admin/') !== false || substr($scriptName, -10) === '/admin.php');
    // 2026-08-14：若请求启动后安装版本发生变化，即使升级已完成也终止本次旧上下文，客户端刷新后再进入新版本。
    $versionChangedDuringBootstrap = $configuredVersion !== ''
        && $latestConfiguredVersion !== ''
        && !hash_equals($configuredVersion, $latestConfiguredVersion);
    $codeChangedDuringBootstrap = $codeVersion !== ''
        && $latestCodeVersion !== ''
        && !hash_equals($codeVersion, $latestCodeVersion);
    if ($latestMaintenanceLocked || $versionChangedDuringBootstrap || $codeChangedDuringBootstrap || ($latestUpgradeRequired && !$latestAdminEntry)) {
        $rejectMaintenanceRequest($latestUpgradeRequired);
    }
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_name((string) app_config('security.session_name', 'worker_admin_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $lastActivity = isset($_SESSION['_last_activity']) ? (int) $_SESSION['_last_activity'] : 0;
    $lifetime = (int) app_config('security.session_lifetime', 7200);
    if ($lastActivity > 0 && time() - $lastActivity > $lifetime) {
        if (!empty($_SESSION['chat_admin_session_id'])) auth()->logout();
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_last_activity'] = time();
}

set_exception_handler(function ($exception) {
    $requestId = request_id();
    $message = sprintf(
        "[%s] request_id=%s %s in %s:%d\n%s\n",
        date('c'),
        $requestId,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    write_log('app', $message);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message);
        exit(1);
    }

    http_response_code(500);
    if (app_config('app.debug', false)) {
        echo '<pre>' . e($message) . '</pre>';
    } else {
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>系统异常</title>'
            . '<style>body{font:15px/1.7 system-ui;background:#f4f7f9;color:#263238;padding:8vw}'
            . '.box{max-width:620px;margin:auto;background:#fff;padding:32px;border-radius:16px;box-shadow:0 16px 50px #15303b18}'
            . 'b{color:#00a7b8}</style><div class="box"><h2>系统暂时无法处理请求</h2>'
            . '<p>请稍后重试；如问题持续，请将请求编号提供给管理员。</p><b>' . e($requestId) . '</b></div></html>';
    }
});

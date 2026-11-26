<?php

// 2026-08-14：统一初始化配置、自动加载、会话和异常日志，未安装时也能安全加载。
if (!defined('WORKER_BASE_PATH')) {
    define('WORKER_BASE_PATH', __DIR__);
}

// 2026-08-14：动态入口统一移除 PHP 版本响应头，减少不必要的运行环境暴露。
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

// Keep this entry PHP 7.4 parseable so an outdated FPM/CLI gets a controlled error
// before loading PHP 8.x application classes. The installer uses the same requirement.
require_once WORKER_BASE_PATH . '/app/Support/PhpRuntimeRequirements.php';
$rejectRuntimeRequest = static function ($code, $message) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $code . ': ' . $message . PHP_EOL);
        exit(1);
    }
    http_response_code(503);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/api/') !== false || strpos($script, '/adminapi/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false, 'code'=>$code, 'message'=>$message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
};
if (version_compare(PHP_VERSION, \WorkerAdmin\Support\PhpRuntimeRequirements::MINIMUM_VERSION, '<')) {
    $rejectRuntimeRequest('PHP_RUNTIME_UNSUPPORTED', '服务器 PHP 版本不满足系统运行要求，请管理员核对网站 PHP-FPM 和计划任务 CLI。');
}
if (PHP_SAPI !== 'cli') {
    foreach (['session_status','session_start','session_name','session_set_cookie_params','session_regenerate_id','session_module_name','session_save_path'] as $requiredSessionFunction) {
        if (!function_exists($requiredSessionFunction)) {
            $rejectRuntimeRequest('PHP_SESSION_UNAVAILABLE', '服务器会话功能不可用，请管理员检查 PHP 会话扩展及配置。');
        }
    }
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

// Register before loading installed configuration, database-backed settings or sessions.
set_exception_handler(static function (Throwable $exception): void {
    $requestId = request_id();
    $publicMessage = '服务器未能完成请求，请联系管理员并提供请求编号。';
    try {
        $route = is_string($_GET['route'] ?? null) ? $_GET['route'] : '';
        $record = \WorkerAdmin\Support\RuntimeExceptionReporter::report($exception,'app',$route);
        $publicMessage = \WorkerAdmin\Support\RuntimeExceptionReporter::publicMessage($record);
    } catch (Throwable $reportError) {
        $record = ['request_id'=>$requestId,'code'=>'EXCEPTION_REPORT_FAILED','category'=>'unexpected'];
        try { @error_log('workers exception report failed request_id='.$requestId); } catch (Throwable $ignored) {}
    }

    if (PHP_SAPI === 'cli') {
        // Keep this fallback independent of the reporter class itself.
        fwrite(STDERR, (json_encode($record,JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE) ?: 'EXCEPTION_REPORT_FAILED').PHP_EOL);
        exit(1);
    }

    // Do not append a second response to a stream or an already-sent payment callback.
    if (headers_sent()) return;
    $status = http_response_code();
    if (!is_int($status) || $status < 400 || $status > 599) $status = 500;
    $script = str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
    if (strpos($script,'/api/')!==false || strpos($script,'/adminapi/')!==false) {
        if(strpos($script,'/api/')!==false&&in_array($_GET['route']??null,['wechatpay.notify','wechatpay.refund_notify'],true))wechatpay_failure_response($status);
        json_response(['ok'=>false,'code'=>'API_ERROR','message'=>$publicMessage,'request_id'=>$requestId],$status);
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>系统异常</title>'
        . '<style>body{font:15px/1.7 system-ui;background:#f4f7f9;color:#263238;padding:8vw}'
        . '.box{max-width:620px;margin:auto;background:#fff;padding:32px;border-radius:16px;box-shadow:0 16px 50px #15303b18}'
        . 'b{color:#00a7b8}</style><div class="box"><h2>系统暂时无法处理请求</h2>'
        . '<p>'.e($publicMessage).'</p><p>请求编号：<b>'.e($requestId).'</b></p></div></html>';
});

$defaults = require WORKER_BASE_PATH . '/config/defaults.php';
$installedFile = WORKER_BASE_PATH . '/config/installed.php';
$installed = is_file($installedFile) ? require $installedFile : [];
if (!is_array($installed)) {
    $installed = [];
}

$GLOBALS['worker_admin_config'] = array_replace_recursive($defaults, $installed);

// The first production Go deployment records only recovery paths and the epoch here.
if (($installed['app']['env'] ?? '') === 'production' && empty($installed['chat']['recovery_gate_file'])
    && empty($installed['chat']['recovery_epoch']) && empty($installed['chat']['recovery_directory'])) {
    $chatRecovery = WORKER_BASE_PATH . '/config/chat-production-recovery.json';
    if (!is_link($chatRecovery) && is_file($chatRecovery)) {
        $raw = file_get_contents($chatRecovery, false, null, 0, 128);
        $epoch = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $epoch) === 1) {
            $directory = '/www/backup-control/bixuan-chat/production';
            $GLOBALS['worker_admin_config']['chat']['recovery_directory'] = $directory;
            $GLOBALS['worker_admin_config']['chat']['recovery_gate_file'] = $directory . '/gate.json';
            $GLOBALS['worker_admin_config']['chat']['recovery_epoch'] = $epoch;
        } else {
            // A malformed existing recovery marker is not an unconfigured site.
            $GLOBALS['worker_admin_config']['chat']['recovery_directory'] = '/www/backup-control/bixuan-chat/production';
        }
    } elseif (is_link($chatRecovery)) {
        $GLOBALS['worker_admin_config']['chat']['recovery_directory'] = '/www/backup-control/bixuan-chat/production';
    }
}
$versionFile = WORKER_BASE_PATH . '/VERSION';
$codeVersion = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
$configuredVersion = (string) ($installed['app']['version'] ?? $installed['version'] ?? '');
$hasInstalledConfig = !empty($installed['installed']);

// Chat business gates are runtime release controls, not permanent installed.php edits.
// A row is authoritative only when it exists; absent rows preserve explicit installed
// configuration for backward compatibility. Database failure keeps the existing
// fail-closed defaults and never opens chat by accident.
if ($hasInstalledConfig) {
    try {
        $runtimeGateDb = db();
        if ($runtimeGateDb->tableExists('system_settings')) {
            $runtimeGateRows = $runtimeGateDb->fetchAll(
                "SELECT setting_key,setting_value FROM system_settings
                 WHERE setting_key IN ('chat.enabled','chat.order_policy_enabled','chat.collaboration_enabled')"
            );
            foreach ($runtimeGateRows as $runtimeGateRow) {
                $runtimeGateKey = (string) ($runtimeGateRow['setting_key'] ?? '');
                $runtimeGateValue = filter_var((string) ($runtimeGateRow['setting_value'] ?? ''), FILTER_VALIDATE_BOOLEAN);
                if ($runtimeGateKey === 'chat.enabled' && ($installed['app']['env'] ?? '') === 'production' && $runtimeGateValue) {
                    // The private Go token export follows the chat switch, not recovery tooling.
                    $GLOBALS['worker_admin_config']['chat']['safety_credential_export_enabled'] = true;
                } elseif ($runtimeGateKey === 'chat.order_policy_enabled') {
                    $GLOBALS['worker_admin_config']['chat']['order_policy_enabled'] = $runtimeGateValue;
                } elseif ($runtimeGateKey === 'chat.collaboration_enabled') {
                    $GLOBALS['worker_admin_config']['chat']['collaboration_enabled'] = $runtimeGateValue;
                }
            }
        }
    } catch (Throwable $runtimeGateError) {
        // Keep static/default fail-closed values when the database is unavailable.
    }
}
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

// 2026-10-12：日志维护最多每 10 分钟执行一次；达到 8 MB 的活跃日志在请求结束后滚动归档，旧归档自动收敛。
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
    if (!function_exists('flock')) $rejectRuntimeRequest('PHP_LOCK_UNAVAILABLE', '服务器文件锁功能不可用，请管理员检查 PHP 配置。');
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
    if ($hasInstalledConfig) {
        \WorkerAdmin\Core\AdminSessionPolicy::configureFileStorage(base_path('storage/private/admin-sessions'));
    }
    // A broken session store must not permit an authenticated action or emit warnings into JSON.
    if (!@session_start() || session_status() !== PHP_SESSION_ACTIVE) {
        $rejectRuntimeRequest('PHP_SESSION_UNAVAILABLE', '服务器会话存储暂不可用，请管理员检查目录权限及 PHP 配置。');
    }

    $lastActivity = isset($_SESSION['_last_activity']) ? (int) $_SESSION['_last_activity'] : 0;
    $lifetime = $hasInstalledConfig ? \WorkerAdmin\Core\AdminSessionPolicy::idleSeconds() : 7200;
    if ($lastActivity > 0 && time() - $lastActivity > $lifetime) {
        if (!empty($_SESSION['chat_admin_session_id'])) auth()->logout();
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_last_activity'] = time();
}


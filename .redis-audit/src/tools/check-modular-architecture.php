<?php

declare(strict_types=1);

/**
 * V2.4.1 模块边界静态守卫。
 *
 * 零 Composer 依赖下同时守住：
 * 1. 新分层依赖只能向内；
 * 2. Legacy 巨型类只允许缩小，不允许继续增长；
 * 3. 禁止通过新增 Controller / Service / 巨型 Http Module 绕回旧架构。
 */
$root = dirname(__DIR__) . '/server/app';
$errors = [];

$filesIn = static function (string $directory): array {
    if (!is_dir($directory)) return [];
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') $result[] = $file->getPathname();
    }
    sort($result);
    return $result;
};

$checkForbidden = static function (array $files, array $patterns, string $layer, int $maxLines = 600) use (&$errors): void {
    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        foreach ($patterns as $pattern => $reason) {
            if (strpos($content, $pattern) !== false) {
                $errors[] = sprintf('%s 反向依赖：%s 命中 `%s`（%s）', $layer, $file, $pattern, $reason);
            }
        }
        $lineCount = substr_count($content, "\n") + 1;
        if ($lineCount > $maxLines) {
            $errors[] = sprintf('%s 文件过大：%s 共 %d 行；上限 %d 行，应继续拆分职责', $layer, $file, $lineCount, $maxLines);
        }
    }
};

$domainFiles = $filesIn($root . '/Domain');
$contractFiles = $filesIn($root . '/Contracts');
$applicationFiles = $filesIn($root . '/Application');
$infrastructureFiles = $filesIn($root . '/Infrastructure');
$foundationFiles = $filesIn($root . '/Foundation');
$httpFiles = $filesIn($root . '/Http');
$controllerFiles = $filesIn($root . '/Controllers');
$serviceFiles = $filesIn($root . '/Services');

$checkForbidden($domainFiles, [
    '$this->db' => 'Domain 不得引用数据库成员',
    'WorkerAdmin\\Core\\' => 'Domain 不得依赖基础设施核心',
    'WorkerAdmin\\Services\\' => 'Domain 不得依赖旧 Service',
    'WorkerAdmin\\Infrastructure\\' => 'Domain 不得依赖 Infrastructure',
    'WorkerAdmin\\Controllers\\' => 'Domain 不得依赖 Controller',
    'WorkerAdmin\\Http\\' => 'Domain 不得依赖 HTTP',
    '\\setting(' => 'Domain 不得读取全局配置',
    '\\db(' => 'Domain 不得直接访问数据库',
    '$_GET' => 'Domain 不得读取 HTTP 输入',
    '$_POST' => 'Domain 不得读取 HTTP 输入',
    '$_SERVER' => 'Domain 不得读取 HTTP 环境',
    'json_response(' => 'Domain 不得输出 HTTP 响应',
], 'Domain');

$checkForbidden($contractFiles, [
    'WorkerAdmin\\Infrastructure\\' => 'Contracts 不得依赖实现层',
    'WorkerAdmin\\Controllers\\' => 'Contracts 不得依赖 Controller',
    'WorkerAdmin\\Services\\' => 'Contracts 不得依赖兼容 Service',
    'WorkerAdmin\\Http\\' => 'Contracts 不得依赖 HTTP 层',
    'header(' => 'Contracts 不得承担 HTTP 输出职责',
    'json_response(' => 'Contracts 不得承担 HTTP 输出职责',
], 'Contracts');

$checkForbidden($applicationFiles, [
    'WorkerAdmin\\Infrastructure\\' => 'Application 应通过 Contracts 使用基础设施',
    'WorkerAdmin\\Controllers\\' => 'Application 不得依赖 Controller',
    'WorkerAdmin\\Services\\' => 'Application 不得依赖旧 Service',
    'WorkerAdmin\\Http\\' => 'Application 不得依赖 HTTP 层',
    '\\setting(' => 'Application 应通过 SettingsProvider 读取配置',
    '\\db(' => 'Application 应通过 Repository 端口访问数据',
    '$_GET' => 'Application 不得读取 HTTP 输入',
    '$_POST' => 'Application 不得读取 HTTP 输入',
    '$_SERVER' => 'Application 不得读取 HTTP 环境',
    'json_response(' => 'Application 不得输出 HTTP 响应',
], 'Application');

$checkForbidden($foundationFiles, [
    'SELECT ' => 'Foundation 不应包含 SQL',
    'INSERT ' => 'Foundation 不应包含 SQL',
    'UPDATE ' => 'Foundation 不应包含 SQL',
    'DELETE ' => 'Foundation 不应包含 SQL',
    '$_GET' => 'Foundation 不得处理 HTTP',
    '$_POST' => 'Foundation 不得处理 HTTP',
    'json_response(' => 'Foundation 不得输出响应',
], 'Foundation');

$checkForbidden($infrastructureFiles, [
    'WorkerAdmin\\Controllers\\' => 'Infrastructure 不得依赖 Controller',
    'WorkerAdmin\\Http\\' => 'Infrastructure 不得依赖 HTTP 路由层',
    'json_response(' => 'Infrastructure 不得直接输出业务响应',
], 'Infrastructure');

$controllerAllowList = [
    str_replace('\\', '/', $root . '/Http/Api/Modules/HelpCenterApiModule.php'),
    str_replace('\\', '/', $root . '/Http/Admin/Modules/HelpCenterAdminModule.php'),
];
$httpInfrastructureAllowList = [
    str_replace('\\', '/', $root . '/Http/Api/ApiKernel.php'),
];
foreach ($httpFiles as $file) {
    $content = (string) file_get_contents($file);
    $normalized = str_replace('\\', '/', $file);
    $lineCount = substr_count($content, "\n") + 1;
    if ($lineCount > 400) {
        $errors[] = sprintf('Http 模块过大：%s 共 %d 行；Http 只做协议适配，业务应下沉 Application', $file, $lineCount);
    }
    if (strpos($content, 'WorkerAdmin\\Controllers\\') !== false && !in_array($normalized, $controllerAllowList, true)) {
        $errors[] = 'Http 新模块不得直接依赖旧 Controller：' . $file;
    }
    if (strpos($content, 'WorkerAdmin\\Services\\') !== false) {
        $errors[] = 'Http 不得直接依赖旧 Service：' . $file;
    }
    if (strpos($content, 'WorkerAdmin\\Infrastructure\\') !== false && !in_array($normalized, $httpInfrastructureAllowList, true)) {
        $errors[] = 'Http 应通过 Application/Contracts 使用基础设施：' . $file;
    }
    foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $sqlToken) {
        if (strpos($content, $sqlToken) !== false) {
            $errors[] = 'Http 不得包含 SQL：' . $file . ' 命中 `' . $sqlToken . '`';
        }
    }
}

if (is_file($root . '/Controllers/ApiController.php')) {
    $errors[] = 'ApiController.php 已于 V2.5.0 完全退休，禁止恢复；新增 API 必须进入 Http Module + Application。';
}
if (is_file($root . '/Controllers/AdminController.php')) {
    $errors[] = 'AdminController.php 已于 V2.5.0 完全退休，禁止恢复；新增后台业务必须进入 Admin Module + Application。';
}

$legacyBudgets = [
    'Controllers/HelpCenterAdminController.php' => 28141,
    'Controllers/HelpCenterApiController.php' => 12283,
    'Services/AdminWorkbenchTaskBuilder.php' => 2500,
    'Services/AliyunSmsService.php' => 1229,
    'Services/FormComponentTypes.php' => 816,
    'Services/ImageOptimizerService.php' => 9657,
    'Services/MessageEventCatalog.php' => 792,
    'Services/MiniProgramCodeService.php' => 4144,
    'Services/MobileNumberService.php' => 1188,
    'Services/NearbyWorkerCandidateService.php' => 26086,
    'Services/OrderContactRevealReviewService.php' => 1393,
    'Services/OrderNotificationService.php' => 6165,
    'Services/PaidSmsDispatchService.php' => 1051,
    'Services/QiniuStorageService.php' => 12176,
    'Services/SchedulerService.php' => 120034,
    'Services/SmsTemplateCatalog.php' => 2204,
    // 2026-10-11：升级预检修复已确认保留；以修复后的实际大小重新冻结，后续仍禁止继续增长。
    'Services/UpgradeService.php' => 54176,
    'Services/UserWalletService.php' => 4543,
    'Services/WeChatCloudSyncService.php' => 15860,
    'Services/WeChatContentSecurityGateService.php' => 10458,
    'Services/WeChatContentSecurityService.php' => 11872,
    'Services/WeChatPayService.php' => 20831,
    'Services/WeChatSubscribeMessageService.php' => 1168,
    'Services/WeComWebhookService.php' => 3920,
    'Services/WorkerCloudGatewayService.php' => 10771,
    'Services/WorkerQuoteEligibilityService.php' => 2174,
];

foreach ($legacyBudgets as $relative => $maxBytes) {
    $file = $root . '/' . $relative;
    if (!is_file($file)) continue;
    $bytes = (int) filesize($file);
    if ($bytes > $maxBytes) {
        $errors[] = sprintf('Legacy 类禁止增长：%s 当前 %d bytes，冻结上限 %d bytes', $relative, $bytes, $maxBytes);
    }
}
foreach ($controllerFiles as $file) {
    $relative = 'Controllers/' . basename($file);
    if (!isset($legacyBudgets[$relative])) $errors[] = '禁止新增 Controller：' . $relative . '；请新建 Http Module + Application 用例';
}
foreach ($serviceFiles as $file) {
    $relative = 'Services/' . basename($file);
    if (!isset($legacyBudgets[$relative])) $errors[] = '禁止新增旧式 Service：' . $relative . '；请按 Domain/Application/Contracts/Infrastructure 放置';
}
if ($errors) {
    fwrite(STDERR, "模块边界检查失败：\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

// 各专项测试有独立的全局函数/夹具，分别启动 PHP 进程运行。
$runRegressions = require __DIR__ . '/support/php-regression-runner.php';
$regressions = [
    'test-controller-retirement.php',
    'test-api-controller-final-retirement.php',
    'test-developer-settings-migration.php',
    'test-basic-settings-migration.php',
    'test-payment-creation-migration.php',
    'test-payment-status-migration.php',
    'test-wallet-recharge-creation-migration.php',
    'test-withdrawal-creation-migration.php',
    'test-withdrawal-cancellation-migration.php',
    'test-commission-payment-creation-migration.php',
    'test-money-module-migration.php',
    'test-media-module-migration.php',
    'test-identity-worker-module-migration.php',
    'test-order-fulfillment-auxiliary-migration.php',
    'test-mini-admin-worker-store-migration.php',
    '../server/tests/v250_admin_workbench_read_split_regression.php',
];
$runRegressions(array_map(static function (string $file): string {
    return __DIR__ . '/' . $file;
}, $regressions));

echo sprintf(
    "模块边界检查通过：Domain %d，Application %d，Contracts %d，Infrastructure %d，Foundation %d，Http %d 个 PHP 文件；ApiController/AdminController 已退休，其余兼容 Controllers/Services 已冻结且禁止新增。\n",
    count($domainFiles),count($applicationFiles),count($contractFiles),count($infrastructureFiles),count($foundationFiles),count($httpFiles)
);

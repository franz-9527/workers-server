<?php

declare(strict_types=1);

$base = __DIR__;
function nbAssert(bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "[FAIL] {$label}\n"); exit(1); }
    fwrite(STDOUT, "[PASS] {$label}\n");
}

$projection = file_get_contents($base.'/WorkbenchTaskProjection.php') ?: '';
$taskSource = file_get_contents($base.'/PlatformOrderWorkbenchTaskSource.php') ?: '';
$api = file_get_contents($base.'/AdminWorkbenchActionApiModule.php') ?: '';

foreach (glob($base.'/*.php') ?: [] as $file) {
    $out = []; $code = 0;
    exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
    nbAssert($code === 0, basename($file).' PHP syntax');
}

nbAssert(strpos($projection, "platform_income_verification") !== false, '标准平台收入核账任务仍有明确投影');
nbAssert(strpos($taskSource, "'platform_income_verification'") !== false, '标准到账核实由真实应收状态派生');
nbAssert(strpos($taskSource, "'platform_income_confirm'") !== false, '标准核账只开放专用确认动作');
nbAssert(strpos($taskSource, "'native_path'") !== false && strpos($taskSource, '/pages/adminWorkbench/platformIncome?id=') !== false, '待办跳转原生核账页且只携带公开订单号');
nbAssert(strpos($taskSource, 'platformIncome?order_id=') === false, '原生页不信任 URL 内部订单 ID');
nbAssert(strpos($taskSource, '&amount=') === false, '原生页不信任 URL 金额');
nbAssert(strpos($api, "'admin.platform-income.confirm'") !== false, '小程序管理员专用到账确认 API 已开放');
nbAssert(strpos($api, 'platformOrderFinance()->markIncomePaid($body, 0)') !== false, 'mini users.id 不写入 Web admin_users.id 字段');
nbAssert(strpos($api, '[小程序管理员 users.id=') !== false, 'mini 管理员真实身份写入审计说明');
nbAssert(strpos($api, 'markIncomePaid($body, $miniUserId)') === false, '禁止跨 ID 命名空间写财务操作人');

fwrite(STDOUT, "next audit batch PHP smoke OK\n");

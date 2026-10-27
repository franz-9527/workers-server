<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) === false) $failures[] = $message;
};
$assertNotContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (strpos($haystack, $needle) !== false) $failures[] = $message;
};

$cli = (string)file_get_contents($root . '/server/bin/scheduler.php');
$assertContains('ApplicationContainer', $cli, 'CLI scheduler must use ApplicationContainer');
$assertContains('->scheduler($limit)', $cli, 'CLI scheduler must obtain SchedulerRunner from ApplicationContainer');
$assertNotContains('$runner = new SchedulerRunner(', $cli, 'CLI scheduler must not bypass container wiring with direct SchedulerRunner construction');

$container = (string)file_get_contents($root . '/server/app/Foundation/ApplicationContainer.php');
$assertContains('$this->walletRechargeLifecycle()', $container, 'ApplicationContainer scheduler must inject WalletRechargeLifecycle');

$runner = (string)file_get_contents($root . '/server/app/Application/Scheduling/SchedulerRunner.php');
$assertContains("['wallet_reconcile','all']", $runner, 'wallet recharge cleanup must run for wallet_reconcile and all');
$assertContains('closeExpired(50)', $runner, 'wallet recharge cleanup batch must remain enabled');

$repository = (string)file_get_contents($root . '/server/app/Infrastructure/Persistence/DatabaseWalletRechargeRepository.php');
$assertContains("p.business_type='wallet_recharge'", $repository, 'cleanup query must be limited to wallet recharge payments');
$assertContains('p.expires_at<=UTC_TIMESTAMP()', $repository, 'cleanup query must include normal expires_at');
$assertContains('p.expires_at IS NULL', $repository, 'cleanup query must include legacy rows without expires_at');
$assertContains('DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR)', $repository, 'legacy rows must wait for conservative two-hour stale threshold');

$adminKernel = (string)file_get_contents($root . '/server/app/Http/Admin/AdminKernel.php');
$assertContains('new SchedulerAdminModule(', $adminKernel, 'admin scheduler routes must be routed through SchedulerAdminModule before LegacyAdminModule');
$module = (string)file_get_contents($root . '/server/app/Http/Admin/Modules/SchedulerAdminModule.php');
$assertContains("['tasks','tasks.run','tasks.failure.retry']", $module, 'SchedulerAdminModule must own task list, tasks.run and failure retry');
$assertContains('->runner(', $module, 'manual scheduler run must obtain SchedulerRunner through scheduler admin composition');
$assertContains('wallet_recharge_cleanup', $module, 'manual scheduler result must surface wallet recharge cleanup stats');
$assertNotContains('new SchedulerService', $module, 'admin scheduler module must not bypass Application scheduler wiring');

$composition = (string)file_get_contents($root . '/server/app/Foundation/SchedulerAdminComposition.php');
$assertContains('ApplicationContainer($this->db)', $composition, 'scheduler admin composition must reuse ApplicationContainer');
$assertContains('->scheduler($limit)', $composition, 'scheduler admin composition must obtain the same SchedulerRunner as CLI');

if ($failures) {
    fwrite(STDERR, "余额充值计划任务接线回归失败：\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "余额充值计划任务接线回归通过。\n";

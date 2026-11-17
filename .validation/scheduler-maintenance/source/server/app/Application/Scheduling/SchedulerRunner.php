<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Scheduling;

use WorkerAdmin\Application\Money\WalletRechargeLifecycle;
use WorkerAdmin\Contracts\SchedulerGateway;
use WorkerAdmin\Domain\Scheduling\SchedulerTaskCatalog;

/** 计划任务应用入口；CLI/后台只依赖本类。 */
final class SchedulerRunner
{
    private SchedulerGateway $gateway;
    private ?WalletRechargeLifecycle $walletRechargeLifecycle;

    public function __construct(SchedulerGateway $gateway, ?WalletRechargeLifecycle $walletRechargeLifecycle = null)
    {
        $this->gateway = $gateway;
        $this->walletRechargeLifecycle = $walletRechargeLifecycle;
    }

    public function run(string $task, bool $force = false): array
    {
        $normalized = SchedulerTaskCatalog::normalize($task);
        $afterRun = null;
        if ($this->walletRechargeLifecycle && in_array($normalized, ['wallet_reconcile','all'], true)) {
            $lifecycle = $this->walletRechargeLifecycle;
            $afterRun = static function (array $result) use ($lifecycle): array {
                if (in_array((string)($result['status'] ?? ''), ['skipped_maintenance','skipped_locked','skipped_upgrade_required'], true)) {
                    return [];
                }
                return ['wallet_recharge_cleanup'=>$lifecycle->closeExpired(50)];
            };
        }
        return $this->gateway->run($normalized, $force, $afterRun);
    }

    /** @return string[] */
    public function supportedTasks(): array
    {
        return SchedulerTaskCatalog::supported();
    }
}

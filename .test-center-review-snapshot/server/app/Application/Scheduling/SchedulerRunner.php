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
        $normalized=SchedulerTaskCatalog::normalize($task);
        $result=$this->gateway->run($normalized,$force);
        if($this->walletRechargeLifecycle && in_array($normalized,['wallet_reconcile','all'],true)){
            $result['wallet_recharge_cleanup']=$this->walletRechargeLifecycle->closeExpired(50);
        }
        return $result;
    }

    /** @return string[] */
    public function supportedTasks(): array
    {
        return SchedulerTaskCatalog::supported();
    }
}

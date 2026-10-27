<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderQuoteReadRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Domain\Order\QuotePolicy;

/**
 * 师傅报价资格应用服务。
 *
 * 负责协调领域规则、系统配置和持久化查询；不包含 SQL，也不依赖 HTTP Controller。
 */
final class WorkerQuoteEligibility
{
    private OrderQuoteReadRepository $repository;
    private SettingsProvider $settings;
    private QuotePolicy $policy;

    public function __construct(
        OrderQuoteReadRepository $repository,
        SettingsProvider $settings,
        ?QuotePolicy $policy = null
    ) {
        $this->repository = $repository;
        $this->settings = $settings;
        $this->policy = $policy ?: new QuotePolicy();
    }

    public function orderCanCollectQuotes(array $order, bool $requirePublished = true): bool
    {
        return $this->policy->orderCanCollectQuotes(
            $order,
            $requirePublished,
            $this->settings->bool('development.pause_quotes', false),
            time()
        );
    }

    public function restrictedMeasurementWorkerId(int $orderId): ?int
    {
        return $this->repository->restrictedMeasurementWorkerId($orderId);
    }

    public function isRestrictedMeasurementConstruction(int $orderId): bool
    {
        return $this->restrictedMeasurementWorkerId($orderId) !== null;
    }

    public function quoteSlotsRemaining(int $orderId): int
    {
        $limit = max(1, min(20, $this->settings->int('order.max_worker_quotes', 3)));
        return max(0, $limit - $this->repository->activeWorkerQuoteCount($orderId));
    }

    public function workerCanQuote(
        array $worker,
        array $order,
        bool $requirePublished = true,
        bool $allowExistingActiveQuote = false,
        bool $allowRestrictedBoundWorker = false
    ): bool {
        if (!$this->orderCanCollectQuotes($order, $requirePublished)) {
            return false;
        }
        if (!$this->policy->workerPassesBaseRules($worker, $order)) {
            return false;
        }

        $workerId = (int) ($worker['id'] ?? 0);
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId < 1) {
            return false;
        }

        $restrictedWorkerId = $this->repository->restrictedMeasurementWorkerId($orderId);
        if ($restrictedWorkerId !== null
            && (!$allowRestrictedBoundWorker || $restrictedWorkerId < 1 || $restrictedWorkerId !== $workerId)) {
            return false;
        }

        if ($this->repository->hasOverdueCommission($workerId)) {
            return false;
        }

        $existing = $this->repository->findExistingActiveOrSelectedQuote($orderId, $workerId);
        if ($existing) {
            return $allowExistingActiveQuote && (string) ($existing['status'] ?? '') === 'active';
        }

        return $this->quoteSlotsRemaining($orderId) > 0;
    }

    public function hasOverdueCommission(int $workerId): bool
    {
        return $this->repository->hasOverdueCommission($workerId);
    }

    public function pendingPlatformInviteCanQuote(array $worker, array $order, bool $claimedInvite): bool
    {
        return $this->policy->pendingPlatformInviteCanQuote(
            $worker, $order, $claimedInvite, $this->settings->bool('development.pause_quotes', false), time()
        );
    }
}

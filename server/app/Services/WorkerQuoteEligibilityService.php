<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Application\Order\WorkerQuoteEligibility;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderQuoteReadRepository;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/**
 * @deprecated 兼容旧 Controller 的门面。新代码应依赖 Application\Order\WorkerQuoteEligibility。
 *
 * 保留原类名和公开方法，避免一次性修改巨型 Controller；内部实现已迁入
 * Domain -> Application -> Infrastructure 分层。
 */
final class WorkerQuoteEligibilityService
{
    private WorkerQuoteEligibility $eligibility;

    public function __construct(Database $db)
    {
        $settings = new GlobalSettingsProvider();
        $repository = new DatabaseOrderQuoteReadRepository($db);
        $this->eligibility = new WorkerQuoteEligibility($repository, $settings);
    }

    public function orderCanCollectQuotes(array $order, bool $requirePublished = true): bool
    {
        return $this->eligibility->orderCanCollectQuotes($order, $requirePublished);
    }

    public function restrictedMeasurementWorkerId(int $orderId): ?int
    {
        return $this->eligibility->restrictedMeasurementWorkerId($orderId);
    }

    public function isRestrictedMeasurementConstruction(int $orderId): bool
    {
        return $this->eligibility->isRestrictedMeasurementConstruction($orderId);
    }

    public function quoteSlotsRemaining(int $orderId): int
    {
        return $this->eligibility->quoteSlotsRemaining($orderId);
    }

    public function workerCanQuote(
        array $worker,
        array $order,
        bool $requirePublished = true,
        bool $allowExistingActiveQuote = false,
        bool $allowRestrictedBoundWorker = false
    ): bool {
        return $this->eligibility->workerCanQuote(
            $worker,
            $order,
            $requirePublished,
            $allowExistingActiveQuote,
            $allowRestrictedBoundWorker
        );
    }

    public function hasOverdueCommission(int $workerId): bool
    {
        return $this->eligibility->hasOverdueCommission($workerId);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/**
 * 报价资格用到的只读持久化端口。
 *
 * Application 只依赖该接口，不依赖 SQL/PDO/表结构。
 */
interface OrderQuoteReadRepository
{
    public function restrictedMeasurementWorkerId(int $orderId): ?int;

    public function activeWorkerQuoteCount(int $orderId): int;

    public function hasOverdueCommission(int $workerId): bool;

    public function findExistingActiveOrSelectedQuote(int $orderId, int $workerId): ?array;
}
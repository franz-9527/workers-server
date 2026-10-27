<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderMeasurementCompletionRepository
{
    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function complete(array $actor, array $order, int $autoAcceptHours): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function accept(array $actor, array $order, int $autoReviewDays, int $freezeDays, int $maxFreezeDays): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function requestRework(array $actor, array $order, string $reason): array;
}

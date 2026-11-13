<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderCompletionRepository
{
    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function complete(array $actor, array $order, string $description, int $warrantyMonths, string $warrantyNote, int $autoAcceptHours): array;

    /** @param array<string,mixed> $actor @param array<string,mixed> $order @return array<string,mixed> */
    public function accept(array $actor, array $order, int $autoReviewDays): array;
}

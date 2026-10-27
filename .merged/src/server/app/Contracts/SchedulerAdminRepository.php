<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface SchedulerAdminRepository
{
    /** @return array<int,array<string,mixed>> */
    public function recentRuns(int $limit = 300): array;

    /** @return array<int,array<string,mixed>> */
    public function unresolvedFailures(int $limit = 100): array;

    public function unresolvedFailureCount(): int;

    /** @return array<string,mixed>|null */
    public function failure(int $id): ?array;

    public function makeFailureRetryable(int $id): bool;
}

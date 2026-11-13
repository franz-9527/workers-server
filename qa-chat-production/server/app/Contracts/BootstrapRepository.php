<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface BootstrapRepository
{
    /** @return array<string,mixed>|null */
    public function freshWorkerActor(int $userId): ?array;
    /** @return array<string,mixed> */
    public function read(array $actor): array;
    public function hasOverdueCommission(int $workerId): bool;
}

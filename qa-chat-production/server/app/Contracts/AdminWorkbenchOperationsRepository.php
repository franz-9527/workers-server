<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminWorkbenchOperationsRepository
{
    /** @return array<string,mixed> */
    public function reassignWorker(array $actor, string $orderNo, int $workerId, string $reason): array;

    /** @return array<string,mixed> */
    public function interveneRepair(array $actor, string $orderNo, string $afterSaleNo, string $reason): array;

    /** @return array<string,mixed> */
    public function retryPlatformAutoPublish(array $actor, string $orderNo, string $sourceId): array;

    /** @return array<string,mixed> */
    public function addInternalFollowup(array $actor, string $orderNo, string $note, ?string $followUpAt): array;

    /** @return array<string,mixed> */
    public function completeInternalFollowup(array $actor, string $orderNo, int $followupEventId, string $note): array;

    /** @return array<int,array<string,mixed>> */
    public function workerOptions(string $orderNo, string $keyword, int $limit): array;

    /** @return array<int,array<string,mixed>> */
    public function internalFollowups(string $orderNo, int $limit): array;
}

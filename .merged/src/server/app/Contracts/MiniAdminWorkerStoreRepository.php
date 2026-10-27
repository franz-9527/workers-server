<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MiniAdminWorkerStoreRepository
{
    /** @return array{worker:array<string,mixed>,local_worker_id:int} */
    public function updateWorker(string $cloudId, array $patch): array;

    /** @return array<string,mixed> */
    public function workerSmsStats(array $actor, string $cloudId): array;

    /** @return array{worker:array<string,mixed>,local_worker_id:int} */
    public function reviewWorker(string $cloudId, string $decision, string $reason): array;

    /** @return array<string,mixed> */
    public function reviewStore(string $cloudId, string $decision): array;

    /** @return array{items:array<int,array<string,mixed>>,page:int,page_size:int,total:int,has_more:bool} */
    public function reviewRecords(string $source, int $page, int $pageSize, string $keyword, array $cloudIds = []): array;
}

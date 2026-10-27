<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface ReviewRepository
{
    /** @return array{summary:array<string,mixed>,rows:array<int,array<string,mixed>>,has_more:bool} */
    public function publicWorkerPage(string $workerReference, int $page, int $limit): array;

    public function createUserReview(string $token, string $orderPublicNo, int $attitudeRating, int $craftRating, string $content): string;

    public function createAutoForOrder(int $orderId, string $content): bool;

    public function refreshWorkerSummary(int $workerId): void;
}

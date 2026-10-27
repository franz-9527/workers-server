<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminReviewRepository
{
    /** @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,media_by_review:array<int,array<int,array<string,mixed>>>,review_orders:array<int,array<string,mixed>>} */
    public function listing(int $page, bool $includeDemo): array;

    /** @return array{review_id:int,worker_id:int} */
    public function create(int $orderId, int $attitudeRating, int $craftRating, string $content): array;

    public function update(int $reviewId, int $attitudeRating, int $craftRating, string $content, string $status): int;

    public function delete(int $reviewId): int;

    public function toggle(int $reviewId): int;

    public function exists(int $reviewId): bool;

    public function mediaCount(int $reviewId): int;

    public function attachMedia(int $reviewId, int $mediaId, int $sortOrder): void;

    public function removeMedia(int $reviewId, int $mediaId): bool;
}

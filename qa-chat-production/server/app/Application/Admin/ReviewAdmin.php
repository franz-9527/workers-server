<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Admin;

use RuntimeException;
use WorkerAdmin\Contracts\AdminReviewMediaStorage;
use WorkerAdmin\Contracts\AdminReviewRepository;
use WorkerAdmin\Contracts\ReviewRepository;
use WorkerAdmin\Domain\Media\UploadedImageInput;

final class ReviewAdmin
{
    private AdminReviewRepository $adminReviews;
    private ReviewRepository $reviews;
    private AdminReviewMediaStorage $media;

    public function __construct(AdminReviewRepository $adminReviews, ReviewRepository $reviews, AdminReviewMediaStorage $media)
    {
        $this->adminReviews = $adminReviews;
        $this->reviews = $reviews;
        $this->media = $media;
    }

    /** @return array<string,mixed> */
    public function listing(int $page, bool $includeDemo): array
    {
        return $this->adminReviews->listing(max(1, $page), $includeDemo);
    }

    /** @return array{review_id:int,worker_id:int} */
    public function create(int $orderId, int $attitudeRating, int $craftRating, string $content): array
    {
        if ($orderId < 1) throw new RuntimeException('请选择已验收订单。');
        $attitudeRating = max(1, min(5, $attitudeRating));
        $craftRating = max(1, min(5, $craftRating));
        $result = $this->adminReviews->create($orderId, $attitudeRating, $craftRating, mb_substr(trim($content), 0, 2000));
        $this->reviews->refreshWorkerSummary($result['worker_id']);
        return $result;
    }

    public function update(int $reviewId, int $attitudeRating, int $craftRating, string $content, string $status): int
    {
        if ($reviewId < 1) throw new RuntimeException('评价不存在。');
        $workerId = $this->adminReviews->update(
            $reviewId,
            max(1, min(5, $attitudeRating)),
            max(1, min(5, $craftRating)),
            mb_substr(trim($content), 0, 2000),
            $status === 'hidden' ? 'hidden' : 'visible'
        );
        $this->reviews->refreshWorkerSummary($workerId);
        return $workerId;
    }

    public function delete(int $reviewId, string $reason): int
    {
        if (mb_strlen(trim($reason)) < 2) throw new RuntimeException('请填写删除原因。');
        $workerId = $this->adminReviews->delete($reviewId);
        $this->reviews->refreshWorkerSummary($workerId);
        return $workerId;
    }

    public function toggle(int $reviewId, string $reason): int
    {
        if (mb_strlen(trim($reason)) < 2) throw new RuntimeException('请填写评价展示调整原因。');
        $workerId = $this->adminReviews->toggle($reviewId);
        $this->reviews->refreshWorkerSummary($workerId);
        return $workerId;
    }

    public function uploadMedia(int $reviewId, UploadedImageInput $image, int $adminId): int
    {
        if ($reviewId < 1 || !$this->adminReviews->exists($reviewId)) throw new RuntimeException('评价不存在。');
        $count = $this->adminReviews->mediaCount($reviewId);
        if ($count >= 6) throw new RuntimeException('每条评价最多 6 张图片。');
        $mediaId = $this->media->store($image, $reviewId, $count, $adminId);
        $this->adminReviews->attachMedia($reviewId, $mediaId, $count);
        return $mediaId;
    }

    public function removeMedia(int $reviewId, int $mediaId): void
    {
        if ($reviewId < 1 || $mediaId < 1 || !$this->adminReviews->removeMedia($reviewId, $mediaId)) {
            throw new RuntimeException('评价图片不存在。');
        }
    }
}

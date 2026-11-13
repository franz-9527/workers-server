<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

use WorkerAdmin\Domain\Media\UploadedImageInput;

interface AdminReviewMediaStorage
{
    public function store(UploadedImageInput $image, int $reviewId, int $sortOrder, int $adminId): int;
}

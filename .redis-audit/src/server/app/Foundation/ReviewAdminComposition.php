<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\ReviewAdmin;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Media\LegacyAdminReviewMediaStorage;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminReviewRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseReviewRepository;

final class ReviewAdminComposition
{
    public static function build(Database $db): ReviewAdmin
    {
        return new ReviewAdmin(
            new DatabaseAdminReviewRepository($db),
            new DatabaseReviewRepository($db),
            new LegacyAdminReviewMediaStorage($db)
        );
    }
}

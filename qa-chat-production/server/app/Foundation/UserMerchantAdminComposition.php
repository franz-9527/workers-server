<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\UserMerchantAdmin;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\WorkerOrderDiscountRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWorkerOrderDiscountRepository;
use WorkerAdmin\Infrastructure\External\LegacyWorkerCloudGateway;
use WorkerAdmin\Infrastructure\Persistence\DatabaseUserMerchantAdminRepository;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

final class UserMerchantAdminComposition
{
    public static function build(Database $db): UserMerchantAdmin
    {
        return new UserMerchantAdmin(
            new DatabaseUserMerchantAdminRepository($db),
            new LegacyWorkerCloudGateway(),
            new GlobalSettingsProvider()
        );
    }

    public static function workerDiscount(Database $db): WorkerOrderDiscountRepository
    {
        return new DatabaseWorkerOrderDiscountRepository($db);
    }

    private function __construct()
    {
    }
}

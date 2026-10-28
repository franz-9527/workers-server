<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\UserMerchantAdmin;
use WorkerAdmin\Core\Database;
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

    private function __construct()
    {
    }
}

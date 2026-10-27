<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Money\WebAdminFinance;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\WeChatPaymentGatewayFactory;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminFinanceRepository;

final class WebAdminFinanceComposition
{
    public static function build(Database $db): WebAdminFinance
    {
        $shared=new ApplicationContainer($db);
        return new WebAdminFinance(
            new DatabaseAdminFinanceRepository($db,$shared->privateCipher()),
            $shared->wallet(),
            new WeChatPaymentGatewayFactory(),
            $shared->transaction(),
            $shared->settings(),
            (new MessagingApplicationContainer($db))->adminEvents()
        );
    }
}

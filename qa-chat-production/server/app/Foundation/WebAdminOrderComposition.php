<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Order\WebAdminOrder;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Messaging\DatabaseAdminOrderEventRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderRepository;

final class WebAdminOrderComposition
{
    public static function build(Database $db): WebAdminOrder
    {
        $messaging=new MessagingApplicationContainer($db);
        $events=new DatabaseAdminOrderEventRepository($db,$messaging->publisher());
        $orders=new DatabaseAdminOrderRepository($db,$events);
        $container=new OrderApplicationContainer($db);
        return new WebAdminOrder($orders,$container->submission());
    }
}

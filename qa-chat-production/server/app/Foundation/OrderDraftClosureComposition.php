<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Order\OrderDraftClosure;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDraftClosureRepository;

final class OrderDraftClosureComposition
{
    public static function build(Database $db): OrderDraftClosure
    {
        return new OrderDraftClosure(new DatabaseOrderDraftClosureRepository($db));
    }
}

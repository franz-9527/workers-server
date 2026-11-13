<?php
declare(strict_types=1);
namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\OrderCancellationRisk;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRiskRepository;

final class OrderCancellationRiskComposition
{
    public static function build(Database $db): OrderCancellationRisk
    { return new OrderCancellationRisk(new DatabaseOrderCancellationRiskRepository($db)); }
}

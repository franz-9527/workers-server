<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Order\OrderFulfillmentAuxiliary;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFulfillmentAuxiliaryRepository;

/** Order 履约附属板块组合根；只负责对象装配。 */
final class OrderFulfillmentAuxiliaryComposition
{
    public static function build(Database $db): OrderFulfillmentAuxiliary
    {
        $container = new OrderApplicationContainer($db);
        return new OrderFulfillmentAuxiliary(
            new DatabaseOrderFulfillmentAuxiliaryRepository($db, $container->settings(), $container->cipher()),
            (string)\app_config('app.timezone', 'Asia/Shanghai')
        );
    }
}

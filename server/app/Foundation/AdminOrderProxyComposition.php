<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminOrderProxyAction;
use WorkerAdmin\Application\Admin\AdminOrderAssistance;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderProxyActionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminOrderAssistanceRepository;

/** 管理员兼容拒绝路由与事实补录用例组合根。 */
final class AdminOrderProxyComposition
{
    private Database $db;
    private ?AdminOrderProxyAction $action = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function action(): AdminOrderProxyAction
    {
        if (!$this->action) {
            $this->action = new AdminOrderProxyAction(new DatabaseAdminOrderProxyActionRepository($this->db));
        }
        return $this->action;
    }

    public function assistance(OrderApplicationContainer $container): AdminOrderAssistance
    {
        return new AdminOrderAssistance(
            new DatabaseAdminOrderAssistanceRepository($this->db, $container->appointment()),
            (string)\app_config('app.timezone', 'Asia/Shanghai')
        );
    }
}

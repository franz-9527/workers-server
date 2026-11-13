<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\AdminRepairResolution;
use WorkerAdmin\Application\Order\ContactRevealReview;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Messaging\LegacyOrderNotificationAdapter;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAdminRepairResolutionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderContactRevealRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager;

/**
 * 异常工作台写动作组合根。
 *
 * HTTP 模块只做鉴权与参数适配；真正业务用例仍位于 Application，SQL/消息实现位于 Infrastructure。
 */
final class AdminWorkbenchActionComposition
{
    private Database $db;
    private ?ContactRevealReview $contactRevealReview = null;
    private ?AdminRepairResolution $repairResolution = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function contactRevealReview(): ContactRevealReview
    {
        if (!$this->contactRevealReview) {
            $this->contactRevealReview = new ContactRevealReview(
                new DatabaseTransactionManager($this->db),
                new DatabaseOrderContactRevealRepository($this->db),
                new LegacyOrderNotificationAdapter($this->db)
            );
        }
        return $this->contactRevealReview;
    }

    public function repairResolution(): AdminRepairResolution
    {
        if (!$this->repairResolution) {
            $this->repairResolution = new AdminRepairResolution(
                new DatabaseAdminRepairResolutionRepository($this->db)
            );
        }
        return $this->repairResolution;
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin;

use RuntimeException;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\Modules\AdminAccountAdminModule;
use WorkerAdmin\Http\Admin\Modules\AgreedRefundExecutionAdminModule;
use WorkerAdmin\Http\Admin\Modules\AgreementAdminModule;
use WorkerAdmin\Http\Admin\Modules\AuditAdminModule;
use WorkerAdmin\Http\Admin\Modules\ContactRevealReviewAdminModule;
use WorkerAdmin\Http\Admin\Modules\DisputeReviewAdminModule;
use WorkerAdmin\Http\Admin\Modules\ExceptionDashboardAdminModule;
use WorkerAdmin\Http\Admin\Modules\FailedRefundRecoveryAdminModule;
use WorkerAdmin\Http\Admin\Modules\FinanceAdminModule;
use WorkerAdmin\Http\Admin\Modules\HelpCenterAdminModule;
use WorkerAdmin\Http\Admin\Modules\MeasurementHoldReviewAdminModule;
use WorkerAdmin\Http\Admin\Modules\OrderAdminModule;
use WorkerAdmin\Http\Admin\Modules\PlatformOrderAdminModule;
use WorkerAdmin\Http\Admin\Modules\PlatformOrderFinanceAdminModule;
use WorkerAdmin\Http\Admin\Modules\RealNameAdminModule;
use WorkerAdmin\Http\Admin\Modules\OrderCancellationRiskAdminModule;
use WorkerAdmin\Http\Admin\Modules\RefundReviewAdminModule;
use WorkerAdmin\Http\Admin\Modules\RepairInterventionAdminModule;
use WorkerAdmin\Http\Admin\Modules\ReviewAdminModule;
use WorkerAdmin\Http\Admin\Modules\SchedulerAdminModule;
use WorkerAdmin\Http\Admin\Modules\MessageOutboxAdminModule;
use WorkerAdmin\Http\Admin\Modules\RuntimeLogAdminModule;
use WorkerAdmin\Http\Admin\Modules\ConfigurationSettingsAdminModule;
use WorkerAdmin\Http\Admin\Modules\ServiceCategoryAdminModule;
use WorkerAdmin\Http\Admin\Modules\OrderFormDesignerAdminModule;
use WorkerAdmin\Http\Admin\Modules\TestCenterAdminModule;
use WorkerAdmin\Http\Admin\Modules\UserMerchantAdminModule;
use WorkerAdmin\Http\Admin\Modules\WorkerAdminModule;
use WorkerAdmin\Http\Admin\Modules\LegacyAdminModule;

/** 后台模块组合根。 */
final class AdminKernel
{
    /** @var AdminModule[] */
    private array $modules;
    private AdminRequestGuard $guard;

    /** @param AdminModule[]|null $modules */
    public function __construct(Database $db, Auth $auth, Csrf $csrf, ?array $modules = null)
    {
        $this->guard = new AdminRequestGuard($auth, $csrf);
        $this->modules = $modules ?: [
            new AdminAccountAdminModule($db, $auth, $csrf),
            new ExceptionDashboardAdminModule($db, $auth, $csrf),
            new RefundReviewAdminModule($db, $auth, $csrf),
            new DisputeReviewAdminModule($db, $auth, $csrf),
            new AuditAdminModule($db, $auth, $csrf),
            new ContactRevealReviewAdminModule($db, $auth, $csrf),
            new MeasurementHoldReviewAdminModule($db, $auth, $csrf),
            new RepairInterventionAdminModule($db, $auth, $csrf),
            new AgreementAdminModule($db, $auth, $csrf),
            new HelpCenterAdminModule($db, $auth, $csrf),
            new RealNameAdminModule($db, $auth, $csrf),
            new OrderCancellationRiskAdminModule($db, $auth, $csrf),
            new SchedulerAdminModule($db, $auth, $csrf),
            new AgreedRefundExecutionAdminModule($db, $auth, $csrf),
            new FailedRefundRecoveryAdminModule($db, $auth, $csrf),
            new PlatformOrderFinanceAdminModule($db, $auth, $csrf),
            new PlatformOrderAdminModule($db, $auth, $csrf),
            new MessageOutboxAdminModule($db, $auth, $csrf),
            new RuntimeLogAdminModule($db, $auth, $csrf),
            new TestCenterAdminModule($db, $auth, $csrf),
            new ConfigurationSettingsAdminModule($db,$auth,$csrf),
            new ServiceCategoryAdminModule($db,$auth,$csrf),
            new OrderFormDesignerAdminModule($db,$auth,$csrf),
            new UserMerchantAdminModule($db,$auth,$csrf),
            new ReviewAdminModule($db,$auth,$csrf),
            new WorkerAdminModule($db,$auth,$csrf),
            // 最后一块旧网页订单路由由正式模块接管；Legacy 只保留 staged 旧 handler 的物理代码。
            new OrderAdminModule($db,$auth,$csrf),
            new FinanceAdminModule($db,$auth,$csrf),
            new LegacyAdminModule($db, $auth, $csrf),
        ];
    }

    public function dispatch(string $route): void
    {
        try {
            $this->guard->inspect($route);
        } catch (RuntimeException $exception) {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
                \flash('danger', $exception->getMessage());
                \redirect(\admin_url('dashboard'));
            }
            throw $exception;
        }
        foreach ($this->modules as $module) {
            if (!$module instanceof AdminModule || !$module->supports($route)) continue;
            $module->dispatch($route);
            return;
        }
        throw new RuntimeException('后台路由不存在');
    }
}

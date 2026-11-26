<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin;

use RuntimeException;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\Modules\AdminAccountAdminModule;
use WorkerAdmin\Http\Admin\Modules\ChatCredentialAdminModule;
use WorkerAdmin\Http\Admin\Modules\ChatImageReviewAdminModule;
use WorkerAdmin\Http\Admin\Modules\ChatDirectReportAdminModule;
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
    /** @var AdminModule[] Explicit test/embedding override; production routes are lazy. */
    private array $modules;
    private AdminRequestGuard $guard;

    /** @param AdminModule[]|null $modules */
    public function __construct(private Database $db, private Auth $auth, private Csrf $csrf, ?array $modules = null)
    {
        $this->guard = new AdminRequestGuard($auth, $csrf);
        $this->modules = $modules ?: [];
    }

    /**
     * Choose a single owner before loading its class or constructing dependencies.
     * The controller-retirement contract compares this index with formal module
     * predicates and the Legacy anti-fallback list.
     */
    private function moduleForRoute(string $route): ?AdminModule
    {
        if ($this->modules) {
            foreach ($this->modules as $module) {
                if ($module instanceof AdminModule && $module->supports($route)) return $module;
            }
            return null;
        }
        $db=$this->db; $auth=$this->auth; $csrf=$this->csrf;
        $module=match(true){
            in_array($route, ['login','logout','profile','profile.password'], true) => new AdminAccountAdminModule($db, $auth, $csrf),
            in_array($route, ['chat.credentials','chat.participant-profiles'], true) => new ChatCredentialAdminModule($db, $auth, $csrf),
            $route === 'chat.testing' => new \WorkerAdmin\Http\Admin\Modules\ChatTestingAdminModule($db,$auth,$csrf),
            $route === 'chat.settings' => new \WorkerAdmin\Http\Admin\Modules\ChatSettingsAdminModule($db,$auth,$csrf),
            $route === 'chat.avatars' => new \WorkerAdmin\Http\Admin\Modules\ChatAvatarAdminModule($db,$auth,$csrf),
            $route === 'chat.image-review' => new ChatImageReviewAdminModule($db, $auth, $csrf),
            $route === 'chat.direct-reports' => new ChatDirectReportAdminModule($db, $auth),
            $route === 'chat.av-review' => new \WorkerAdmin\Http\Admin\Modules\ChatAVReviewAdminModule($db,$auth,$csrf),
            $route === 'chat.storage' => new \WorkerAdmin\Http\Admin\Modules\ChatStorageAdminModule($db,$auth,$csrf),
            $route === 'chat.calls' => new \WorkerAdmin\Http\Admin\Modules\ChatCallsAdminModule($db,$auth,$csrf),
            in_array($route, ['chat.governance','chat.auditors'], true) => new \WorkerAdmin\Http\Admin\Modules\ChatGovernanceAdminModule($db,$auth,$csrf),
            $route === 'dashboard' => new ExceptionDashboardAdminModule($db, $auth, $csrf),
            in_array($route, ['refund-review','refund-review.resolve','orders.cancel.review'], true) => new RefundReviewAdminModule($db, $auth, $csrf),
            in_array($route, ['disputes','dispute-review','dispute-review.resolve','disputes.resolve'], true) => new DisputeReviewAdminModule($db, $auth, $csrf),
            $route === 'audit' => new AuditAdminModule($db, $auth, $csrf),
            in_array($route, ['contact-reveal-review','contact-reveal-review.resolve','orders.contact-reveal.review'], true) => new ContactRevealReviewAdminModule($db, $auth, $csrf),
            in_array($route, ['measurement-hold-review','measurement-hold-review.resolve'], true) => new MeasurementHoldReviewAdminModule($db, $auth, $csrf),
            in_array($route, ['repair-intervention','repair-intervention.resolve'], true) => new RepairInterventionAdminModule($db, $auth, $csrf),
            $route === 'agreements' || strpos($route, 'agreements.') === 0 => new AgreementAdminModule($db, $auth, $csrf),
            strpos($route, 'help') === 0 => new HelpCenterAdminModule($db, $auth, $csrf),
            in_array($route, ['worker-credentials','workers.realname.review','workers.realname.update','workers.realname.revoke','collection-codes.review'], true) => new RealNameAdminModule($db, $auth, $csrf),
            in_array($route, ['users.order-risk','users.order-risk.release','users.order-risk.restrict','users.order-risk.settings','users.order-risk.rules'], true) => new OrderCancellationRiskAdminModule($db, $auth, $csrf),
            in_array($route, ['tasks','tasks.run','tasks.failure.retry'], true) => new SchedulerAdminModule($db, $auth, $csrf),
            in_array($route, ['refunds.execution','refunds.execution.prepare'], true) => new AgreedRefundExecutionAdminModule($db, $auth, $csrf),
            in_array($route, ['refunds.failures','refunds.failure','refunds.failure.recheck','refunds.failure.replace-closed'], true) => new FailedRefundRecoveryAdminModule($db, $auth, $csrf),
            $route === 'platform-order-finance' || strpos($route, 'platform-order-finance.') === 0 => new PlatformOrderFinanceAdminModule($db, $auth, $csrf),
            $route === 'platform-orders' || strpos($route, 'platform-orders.') === 0 => new PlatformOrderAdminModule($db, $auth, $csrf),
            in_array($route, ['message-outbox','message-outbox.retry','message-outbox.close','message-outbox.suppression.add','message-outbox.suppression.remove'], true) => new MessageOutboxAdminModule($db, $auth, $csrf),
            in_array($route, ['runtime-logs','runtime-logs.purge','runtime-logs.clear','runtime-logs.clear-category','runtime-logs.purge-runs'], true) => new RuntimeLogAdminModule($db, $auth, $csrf),
            $route === 'test-center' || strpos($route, 'test-center.') === 0 => new TestCenterAdminModule($db, $auth, $csrf),
            in_array($route, ['settings','settings.messages','announcement','settings.developer','settings.save','settings.notifications.bootstrap','settings.notifications.sync','settings.notifications.library','settings.notifications.library.add','settings.notifications.import','settings.notifications.test','settings.sms.bootstrap','settings.sms.recommend','settings.sms.reliable','settings.sms.test','settings.wecom.test'], true) => new ConfigurationSettingsAdminModule($db,$auth,$csrf),
            in_array($route, ['categories','categories.save','categories.toggle'], true) => new ServiceCategoryAdminModule($db,$auth,$csrf),
            in_array($route, ['forms','forms.designer','forms.designer.reorder','forms.ui.save','forms.schema.save','forms.version.create','forms.step.save','forms.step.toggle','forms.field.save','forms.field.toggle','forms.publish'], true) => new OrderFormDesignerAdminModule($db,$auth,$csrf),
            in_array($route, ['users','users.membership.grant','users.membership.revoke','merchants','merchants.review','merchants.worker-discount.review'], true) => new UserMerchantAdminModule($db,$auth,$csrf),
            in_array($route, ['reviews','reviews.create','reviews.update','reviews.delete','reviews.media.upload','reviews.media.delete','reviews.toggle'], true) => new ReviewAdminModule($db,$auth,$csrf),
            in_array($route, ['workers','workers.create','workers.update','workers.media.upload','workers.media.delete','workers.delete','workers.review','workers.cloud.sync'], true) => new WorkerAdminModule($db,$auth,$csrf),
            in_array($route, ['orders','orders.proxy-create','orders.create','orders.view','orders.update','orders.audit','orders.publish','orders.cancel','orders.quote','orders.assign','orders.invite','orders.invite.revoke','orders.release','orders.media.upload','orders.media.delete','media.view'], true) => new OrderAdminModule($db,$auth,$csrf),
            in_array($route, ['payments','wallets','wallets.recharge.refund','refunds','refunds.approve','commissions','tail-settlement','tail-settlement.bad-debt','tail-settlement.review','withdrawals','withdrawals.transition'], true) => new FinanceAdminModule($db,$auth,$csrf),
            default => new LegacyAdminModule($db, $auth, $csrf),
        };
        return $module->supports($route) ? $module : null;
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
        $module=$this->moduleForRoute($route);
        if ($module !== null) {
            $module->dispatch($route);
            return;
        }
        throw new RuntimeException('后台路由不存在');
    }
}

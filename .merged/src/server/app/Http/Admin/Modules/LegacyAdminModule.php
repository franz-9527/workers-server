<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Admin\AdminModule;

/**
 * AdminController 已完全退休。
 *
 * 正式模块接管的历史路由继续显式列在 anti-fallback 清单中；
 * 其余未知路由只在这里统一返回 404，不再委托任何旧 Controller。
 */
final class LegacyAdminModule implements AdminModule
{
    private const MIGRATED_ROUTES = [
        'login','logout','profile','profile.password',
        'orders','orders.create','orders.view','orders.update','orders.audit','orders.publish','orders.cancel','orders.quote','orders.assign','orders.invite','orders.invite.revoke','orders.release','orders.media.upload','orders.media.delete','media.view',
        'categories','categories.save','categories.toggle',
        'forms','forms.designer','forms.designer.reorder','forms.ui.save','forms.schema.save','forms.version.create',
        'forms.step.save','forms.step.toggle','forms.field.save','forms.field.toggle','forms.publish',
        'users','users.membership.grant','users.membership.revoke','merchants','merchants.review',
        'worker-credentials','workers.realname.review','workers.realname.update','workers.realname.revoke','collection-codes.review',
        'reviews','reviews.create','reviews.update','reviews.delete','reviews.media.upload','reviews.media.delete','reviews.toggle',
        'workers','workers.create','workers.update','workers.media.upload','workers.media.delete','workers.delete','workers.review','workers.cloud.sync',
        'disputes','disputes.resolve','dispute-review','dispute-review.resolve','audit',
        'payments','wallets','wallets.recharge.refund','refunds','refunds.approve','commissions',
        'tail-settlement','tail-settlement.bad-debt','tail-settlement.review','withdrawals','withdrawals.transition',
        'refunds.execution','refunds.execution.prepare','refunds.failures','refunds.failure','refunds.failure.recheck','refunds.failure.replace-closed',
    ];

    public function __construct(Database $db, Auth $auth, Csrf $csrf) {}

    public function supports(string $route): bool
    {
        return !in_array($route, self::MIGRATED_ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        http_response_code(404);
        echo \view('errors/404', ['pageTitle'=>'页面不存在']);
    }
}

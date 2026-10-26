<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Api\ApiModule;

/** ApiController 完全退休后的 anti-fallback / unknown-route 404 模块。 */
final class LegacyApiModule implements ApiModule
{
    /** @var string[] */
    private const MIGRATED_ROUTES = [
        'bootstrap',
        'auth.wechat','auth.phone','membership.summary',
        'workers.map','workers.detail','workers.phone-reveal.policy','workers.phone-reveal.authorize','workers.phone-reveal','workers.search','worker.profile','worker.profile.save',
        'media.order','media.worker','media.upload','media.delete','orders.measurement.media.upload','orders.measurement.media.delete','reviews.media.upload','finance.realname.upload','finance.collection-code.upload','merchant.license.upload',
        'payments.create','payments.status','wechatpay.notify','wechatpay.refund_notify','wallet.summary','wallet.ledger','wallet.recharge.create','wallet.recharge.cancel','finance.summary','finance.account.save','finance.withdraw','finance.withdraw.cancel','commission.payment.create','offline.delay.create','offline.collection.adjustment.submit',
        'announcement.current','subscriptions.status','subscriptions.diagnose','subscriptions.preference','notifications.summary','notifications.list','notifications.read','notifications.read_batch','notifications.read_all',
        'forms.order','forms.order.products','admin.orders.proxy.form','admin.orders.proxy.customers','admin.orders.proxy.create','admin.orders.proxy.ready','orders.create','orders.finalize','orders.supplement','orders.center','hall.orders','orders.detail','orders.nearby-workers','orders.nearby-workers.notify','orders.nearby-workers.notify-status',
        'invites.claim','quotes.create','quotes.select','quotes.switch','orders.grab','orders.grab.quote','assignments.accept','assignments.reject','appointments.save',
        'changes.create','changes.decide','fulfillment.pricing.update','fulfillment.complete','fulfillment.accept','arrivals.report','orders.measurement.space.save','orders.measurement.space.delete','orders.measurement.links.eligible',
        'after_sales.repair.create','after_sales.repair.withdraw','exceptions.respond','exceptions.escalate','refunds.propose','after_sales.repair.respond','disputes.create','disputes.escalate','disputes.resolve',
        'admin.dashboard','admin.orders','admin.workbench.order','admin.proxy.action','admin.order.assistance.context','admin.order.assistance.save','admin.order.assistance.media.upload','admin.dispute.resolve','admin.after_sales.repair.resolve','orders.cancel.request','admin.refund.review',
        'orders.worker-contact.request','orders.measurement.hold.extend.request','admin.measurement.hold.extend.review','admin.order.contact-reveal.review','after_sales.repair.escalate',
        'run-empty.claim.submit','run-empty.claim.decide','orders.contact.record','orders.submission-policy','orders.measurement.submit','orders.measurement.rework','refunds.withdraw',
        'platform.order.customer-share.create','platform.order.customer-share.preview','platform.order.customer-share.claim','admin.platform.pricing.review',
        'merchant.submit','merchant.profile','finance.realname.submit',
        'admin.worker.update','admin.worker.review','admin.worker.sms-stats','admin.store.review','admin.review.records',
        'admin.withdrawals','admin.withdrawal.review','admin.order.action','admin.order.quote','admin.order.share','admin.demo.status','admin.demo.switch',
    ];

    public function __construct(Database $db)
    {
    }

    public function supports(string $route): bool
    {
        return !in_array($route, self::MIGRATED_ROUTES, true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        \json_response(['ok'=>false,'code'=>'NOT_FOUND','message'=>'接口不存在','request_id'=>\request_id()],404);
    }
}

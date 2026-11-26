<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api;

use RuntimeException;
use WorkerAdmin\Contracts\RequestContentSecurityGate;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Http\Api\Modules\AdminOrderProxyApiModule;
use WorkerAdmin\Http\Api\Modules\AdminWorkbenchActionApiModule;
use WorkerAdmin\Http\Api\Modules\AdminWorkbenchApiModule;
use WorkerAdmin\Http\Api\Modules\AgreementApiModule;
use WorkerAdmin\Http\Api\Modules\BootstrapApiModule;
use WorkerAdmin\Http\Api\Modules\ChatCredentialApiModule;
use WorkerAdmin\Http\Api\Modules\HelpCenterApiModule;
use WorkerAdmin\Http\Api\Modules\IdentityWorkerApiModule;
use WorkerAdmin\Http\Api\Modules\MediaApiModule;
use WorkerAdmin\Http\Api\Modules\MediaDeliveryApiModule;
use WorkerAdmin\Http\Api\Modules\MerchantAuthApiModule;
use WorkerAdmin\Http\Api\Modules\MiniAdminDemoApiModule;
use WorkerAdmin\Http\Api\Modules\MiniAdminMoneyApiModule;
use WorkerAdmin\Http\Api\Modules\MiniAdminOrderApiModule;
use WorkerAdmin\Http\Api\Modules\MiniPlatformOrderCreationApiModule;
use WorkerAdmin\Http\Api\Modules\MiniAdminWorkerStoreApiModule;
use WorkerAdmin\Http\Api\Modules\MoneyApiModule;
use WorkerAdmin\Http\Api\Modules\OcrApiModule;
use WorkerAdmin\Http\Api\Modules\OrderAppointmentApiModule;
use WorkerAdmin\Http\Api\Modules\OrderCancellationApiModule;
use WorkerAdmin\Http\Api\Modules\OrderCancellationRiskApiModule;
use WorkerAdmin\Http\Api\Modules\OrderCenterApiModule;
use WorkerAdmin\Http\Api\Modules\OrderContactRevealApiModule;
use WorkerAdmin\Http\Api\Modules\OrderDealApiModule;
use WorkerAdmin\Http\Api\Modules\OrderDiscoveryApiModule;
use WorkerAdmin\Http\Api\Modules\OrderDisputeApiModule;
use WorkerAdmin\Http\Api\Modules\OrderDraftClosureApiModule;
use WorkerAdmin\Http\Api\Modules\OrderNegotiationApiModule;
use WorkerAdmin\Http\Api\Modules\OrderFulfillmentApiModule;
use WorkerAdmin\Http\Api\Modules\OrderInvitationApiModule;
use WorkerAdmin\Http\Api\Modules\OrderMeasurementHoldExtensionApiModule;
use WorkerAdmin\Http\Api\Modules\OrderRepairInterventionApiModule;
use WorkerAdmin\Http\Api\Modules\OrderRunEmptyApiModule;
use WorkerAdmin\Http\Api\Modules\OrderSubmissionApiModule;
use WorkerAdmin\Http\Api\Modules\PlatformOrderCustomerShareApiModule;
use WorkerAdmin\Http\Api\Modules\PlatformOrderPricingReviewApiModule;
use WorkerAdmin\Http\Api\Modules\RealNameApiModule;
use WorkerAdmin\Http\Api\Modules\StorePublicApiModule;
use WorkerAdmin\Http\Api\Modules\WorkerReviewsApiModule;
use WorkerAdmin\Http\Api\Modules\MessagingApiModule;
use WorkerAdmin\Http\Api\Modules\PaymentCreationApiModule;
use WorkerAdmin\Http\Api\Modules\PaymentNotificationApiModule;
use WorkerAdmin\Http\Api\Modules\RefundNotificationApiModule;
use WorkerAdmin\Http\Api\Modules\LegacyApiModule;
use WorkerAdmin\Infrastructure\External\WeChatRequestContentSecurityGate;

/** 小程序 API 组合根。 */
final class ApiKernel
{
    /** @var ApiModule[] Explicit test override; production uses the lazy routing index. */
    private array $modules;
    private ?RequestContentSecurityGate $contentSecurity;

    /** @param ApiModule[]|null $modules */
    public function __construct(private Database $db, ?array $modules = null, ?RequestContentSecurityGate $contentSecurity = null)
    {
        $this->contentSecurity = $contentSecurity;
        $this->modules = $modules ?: [];
    }

    private function moduleForRoute(string $route): ?ApiModule
    {
        if($this->modules){
            foreach($this->modules as $module){
                if($module instanceof ApiModule && $module->supports($route))return $module;
            }
            return null;
        }
        $db=$this->db;
        $module=match(true){
            str_starts_with($route,'agreements.') => new AgreementApiModule($db),
            str_starts_with($route,'help.') => new HelpCenterApiModule($db),
            $route === 'bootstrap' => new BootstrapApiModule($db),
            str_starts_with($route,'chat.sms.') || str_starts_with($route,'chat.collaboration.') || str_starts_with($route,'chat.direct.') || in_array($route,['chat.support.entry','chat.support.prepare','chat.support.settings','chat.support.configure','chat.credentials','chat.audit.credentials','chat.moderation.credentials','chat.participants','chat.participant.profile','chat.participant.avatar','chat.availability','chat.voice-route','chat.simulation','chat.telemetry'],true) => new ChatCredentialApiModule($db),
            in_array($route,['chat.avatar.self','chat.avatar.upload','chat.avatar.remove','chat.avatar.admin','chat.avatar.image'],true) => new \WorkerAdmin\Http\Api\Modules\ChatAvatarApiModule($db),
            in_array($route,['auth.wechat','auth.phone','membership.summary','workers.map','workers.detail','workers.phone-reveal.policy','workers.phone-reveal.authorize','workers.phone-reveal','workers.search','worker.profile','worker.profile.save'],true) => new IdentityWorkerApiModule($db),
            in_array($route,['media.order','media.worker'],true) => new MediaDeliveryApiModule($db),
            in_array($route,['media.upload','media.delete','orders.measurement.media.upload','orders.measurement.media.delete','reviews.media.upload','finance.realname.upload','finance.collection-code.upload','merchant.license.upload'],true) => new MediaApiModule($db),
            $route === 'ocr.result.save' => new OcrApiModule($db),
            in_array($route,['finance.realname.submit','finance.realname.draft','finance.realname.reauth-policy','finance.realname.restart'],true) => new RealNameApiModule($db),
            in_array($route,['merchant.submit','merchant.profile','merchant.license.preview','merchant.worker-discount.submit'],true) => new MerchantAuthApiModule($db),
            $route === 'store.public-phone' => new StorePublicApiModule($db),
            in_array($route,['admin.worker.update','admin.worker.review','admin.worker.sms-stats','admin.store.review','admin.review.records'],true) => new MiniAdminWorkerStoreApiModule($db),
            in_array($route,['admin.withdrawals','admin.withdrawal.review'],true) => new MiniAdminMoneyApiModule($db),
            in_array($route,['admin.order.action','admin.order.quote','admin.order.share'],true) => new MiniAdminOrderApiModule($db),
            in_array($route,['admin.platform.orders.create-options','admin.platform.orders.customers','admin.platform.orders.create'],true) => new MiniPlatformOrderCreationApiModule($db),
            in_array($route,['admin.demo.status','admin.demo.switch','admin.test-entry.redeem'],true) => new MiniAdminDemoApiModule($db),
            in_array($route,['payments.status','wallet.summary','wallet.ledger','wallet.recharge.create','wallet.recharge.cancel','finance.summary','finance.account.save','finance.withdraw','finance.withdraw.cancel','commission.payment.create','offline.delay.create','offline.collection.adjustment.submit'],true) => new MoneyApiModule($db),
            in_array($route,['forms.order','forms.order.products','admin.orders.proxy.form','admin.orders.proxy.customers','admin.orders.proxy.create','admin.orders.proxy.ready','orders.create','orders.finalize','orders.supplement'],true) => new OrderSubmissionApiModule($db),
            $route === 'orders.center' => new OrderCenterApiModule($db),
            in_array($route,['hall.orders','orders.detail','orders.nearby-workers','orders.nearby-workers.notify','orders.nearby-workers.notify-status'],true) => new OrderDiscoveryApiModule($db),
            $route === 'invites.claim' => new OrderInvitationApiModule($db),
            in_array($route,['quotes.create','quotes.select','quotes.switch','orders.grab','orders.grab.quote','assignments.accept','assignments.reject'],true) => new OrderDealApiModule($db),
            $route === 'appointments.save' => new OrderAppointmentApiModule($db),
            in_array($route,['changes.create','changes.decide','orders.measurement.submit','orders.measurement.rework','fulfillment.pricing.update','fulfillment.complete','fulfillment.accept','arrivals.report','orders.measurement.space.save','orders.measurement.space.delete','orders.measurement.links.eligible','after_sales.repair.create','after_sales.repair.withdraw'],true) => new OrderFulfillmentApiModule($db),
            in_array($route,['disputes.create','disputes.escalate','disputes.resolve','admin.dispute.resolve'],true) => new OrderDisputeApiModule($db),
            in_array($route,['exceptions.respond','exceptions.escalate','refunds.propose','refunds.withdraw','after_sales.repair.respond'],true) => new OrderNegotiationApiModule($db),
            in_array($route,['orders.cancel.request','admin.refund.review'],true) => new OrderCancellationApiModule($db),
            $route === 'orders.draft.close' => new OrderDraftClosureApiModule($db),
            in_array($route,['orders.contact.record','orders.submission-policy'],true) => new OrderCancellationRiskApiModule($db),
            $route === 'orders.worker-contact.request' => new OrderContactRevealApiModule($db),
            in_array($route,['orders.measurement.hold.extend.request','admin.measurement.hold.extend.review'],true) => new OrderMeasurementHoldExtensionApiModule($db),
            $route === 'after_sales.repair.escalate' => new OrderRepairInterventionApiModule($db),
            in_array($route,['run-empty.claim.submit','run-empty.claim.decide'],true) => new OrderRunEmptyApiModule($db),
            in_array($route,['platform.order.customer-share.create','platform.order.customer-share.preview','platform.order.customer-share.claim'],true) => new PlatformOrderCustomerShareApiModule($db),
            $route === 'admin.platform.pricing.review' => new PlatformOrderPricingReviewApiModule($db),
            in_array($route,['admin.dashboard','admin.orders','admin.workbench.order','admin.workbench.workers'],true) => new AdminWorkbenchApiModule($db),
            in_array($route,['admin.order.contact-reveal.review','admin.after_sales.repair.resolve','admin.platform-income.confirm','admin.order.worker.reassign','admin.after_sales.repair.intervene','admin.task.retry-platform-publish','admin.order.followup.add','admin.order.followup.complete'],true) => new AdminWorkbenchActionApiModule($db),
            in_array($route,['admin.proxy.action','admin.order.assistance.context','admin.order.assistance.save','admin.order.assistance.media.upload'],true) => new AdminOrderProxyApiModule($db),
            in_array($route,['workers.reviews','reviews.templates','reviews.create'],true) => new WorkerReviewsApiModule($db),
            in_array($route,['announcement.current','announcement.home','subscriptions.status','subscriptions.diagnose','subscriptions.preference','notifications.threads','notifications.thread_messages','notifications.summary','notifications.list','notifications.read','notifications.read_batch','notifications.read_all'],true) => new MessagingApiModule($db),
            $route === 'payments.create' => new PaymentCreationApiModule($db),
            $route === 'wechatpay.notify' => new PaymentNotificationApiModule($db),
            $route === 'wechatpay.refund_notify' => new RefundNotificationApiModule($db),
            default => new LegacyApiModule($db),
        };
        return $module->supports($route)?$module:null;
    }

    public function dispatch(string $route): void
    {
        $module=$this->moduleForRoute($route);
        if($module!==null){
            if($module->requiresContentSecurity($route)){
                $this->contentSecurity ??= new WeChatRequestContentSecurityGate($this->db);
                $this->contentSecurity->inspect($route);
            }
            $module->dispatch($route);
            return;
        }
        throw new RuntimeException('接口不存在');
    }
}


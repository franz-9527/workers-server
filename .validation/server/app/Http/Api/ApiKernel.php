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
    /** @var ApiModule[] */
    private array $modules;
    private RequestContentSecurityGate $contentSecurity;

    /** @param ApiModule[]|null $modules */
    public function __construct(Database $db, ?array $modules = null, ?RequestContentSecurityGate $contentSecurity = null)
    {
        $this->contentSecurity = $contentSecurity ?: new WeChatRequestContentSecurityGate($db);
        $this->modules = $modules ?: [
            new AgreementApiModule($db),
            new HelpCenterApiModule($db),
            new BootstrapApiModule($db),
            new IdentityWorkerApiModule($db),
            new MediaDeliveryApiModule($db),
            new MediaApiModule($db),
            new OcrApiModule($db),
            new RealNameApiModule($db),
            new MerchantAuthApiModule($db),
            new StorePublicApiModule($db),
            new MiniAdminWorkerStoreApiModule($db),
            new MiniAdminMoneyApiModule($db),
            new MiniAdminOrderApiModule($db),
            new MiniPlatformOrderCreationApiModule($db),
            new MiniAdminDemoApiModule($db),
            new MoneyApiModule($db),
            new OrderSubmissionApiModule($db),
            new OrderCenterApiModule($db),
            new OrderDiscoveryApiModule($db),
            new OrderInvitationApiModule($db),
            new OrderDealApiModule($db),
            new OrderAppointmentApiModule($db),
            new OrderFulfillmentApiModule($db),
            new OrderDisputeApiModule($db),
            new OrderNegotiationApiModule($db),
            new OrderCancellationApiModule($db),
            new OrderDraftClosureApiModule($db),
            new OrderCancellationRiskApiModule($db),
            new OrderContactRevealApiModule($db),
            new OrderMeasurementHoldExtensionApiModule($db),
            new OrderRepairInterventionApiModule($db),
            new OrderRunEmptyApiModule($db),
            new PlatformOrderCustomerShareApiModule($db),
            new PlatformOrderPricingReviewApiModule($db),
            new AdminWorkbenchApiModule($db),
            new AdminWorkbenchActionApiModule($db),
            new AdminOrderProxyApiModule($db),
            new WorkerReviewsApiModule($db),
            new MessagingApiModule($db),
            new PaymentCreationApiModule($db),
            new PaymentNotificationApiModule($db),
            new RefundNotificationApiModule($db),
            new LegacyApiModule($db),
        ];
    }

    public function dispatch(string $route): void
    {
        foreach($this->modules as $module){
            if(!$module instanceof ApiModule||!$module->supports($route))continue;
            if($module->requiresContentSecurity($route))$this->contentSecurity->inspect($route);
            $module->dispatch($route);
            return;
        }
        throw new RuntimeException('接口不存在');
    }
}
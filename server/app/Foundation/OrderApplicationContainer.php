<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Order\OrderAppointment;
use WorkerAdmin\Application\Order\OrderCancellation;
use WorkerAdmin\Application\Order\OrderCenterQuery;
use WorkerAdmin\Application\Order\OrderContactRevealRequest;
use WorkerAdmin\Application\Order\OrderDeal;
use WorkerAdmin\Application\Order\OrderDetailQuery;
use WorkerAdmin\Application\Order\OrderDiscoveryQuery;
use WorkerAdmin\Application\Order\OrderDispute;
use WorkerAdmin\Application\Order\OrderNegotiation;
use WorkerAdmin\Application\Order\OrderFulfillment;
use WorkerAdmin\Application\Order\OrderInvitationClaim;
use WorkerAdmin\Application\Order\OrderMeasurementHoldExtension;
use WorkerAdmin\Application\Order\OrderNearbyWorkerOutreach;
use WorkerAdmin\Application\Order\OrderQuoteSelection;
use WorkerAdmin\Application\Order\OrderQuoteSwitch;
use WorkerAdmin\Application\Order\OrderRepairIntervention;
use WorkerAdmin\Application\Order\OrderRunEmpty;
use WorkerAdmin\Application\Order\OrderSubmission;
use WorkerAdmin\Application\Order\PlatformOrderAdmin;
use WorkerAdmin\Application\Order\MiniPlatformOrderCreation;
use WorkerAdmin\Application\Order\PlatformOrderCustomerShare;
use WorkerAdmin\Application\Order\PlatformOrderFinance;
use WorkerAdmin\Application\Order\PlatformOrderPricingReview;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Agreements\PlatformOrderAgreementProvider;
use WorkerAdmin\Infrastructure\Agreements\PlatformWorkerAgreementVersionProvider;
use WorkerAdmin\Infrastructure\External\QiniuObjectStorage;
use WorkerAdmin\Infrastructure\Persistence\DatabaseApiActorRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderAppointmentRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCancellationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCenterRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderChangeRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderCompletionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderContactRevealRequestRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDealRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDetailRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDiscoveryRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderDisputeRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderNegotiationRepository;
use WorkerAdmin\Infrastructure\Persistence\NegotiationAwareOrderDetailRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderFulfillmentContextRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderInvitationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderMeasurementCompletionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderMeasurementHoldExtensionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderNearbyWorkerRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderPricingRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderQuoteSelectionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderQuoteSwitchRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderRepairInterventionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderRunEmptyRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderAdminRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderBookingFeeManager;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMiniPlatformOrderCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderCustomerShareRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderFinanceRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePlatformOrderPricingReviewRepository;
use WorkerAdmin\Infrastructure\Persistence\MeasurementAwarePlatformOrderAdminRepository;
use WorkerAdmin\Infrastructure\Persistence\PlatformMeasurementAwareOrderDetailRepository;
use WorkerAdmin\Infrastructure\Persistence\PlatformPricingAwareOrderMeasurementCompletionRepository;
use WorkerAdmin\Infrastructure\Persistence\PolicyAwareOrderSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\RepairGuardedPlatformOrderAdminRepository;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Security\HmacOrderMediaUrlSigner;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/**
 * 订单域轻量组合根。
 *
 * 与通用 ApplicationContainer 分开，便于后续把 Discovery/Deal/Fulfillment/AfterSales
 * 分批迁出旧 ApiController，而不让 Http 模块直接组装 Infrastructure。
 */
final class OrderApplicationContainer
{
    private Database $db;
    private ?SettingsProvider $settings = null;
    private ?PrivateValueCipher $cipher = null;
    private ?ApiActorResolver $actorResolver = null;
    private ?OrderSubmission $submission = null;
    private ?OrderCenterQuery $center = null;
    private ?OrderDiscoveryQuery $discovery = null;
    private ?OrderDetailQuery $detail = null;
    private ?OrderInvitationClaim $invitation = null;
    private ?OrderNearbyWorkerOutreach $nearbyWorkerOutreach = null;
    private ?OrderDeal $deal = null;
    private ?OrderQuoteSelection $quoteSelection = null;
    private ?OrderQuoteSwitch $quoteSwitch = null;
    private ?OrderAppointment $appointment = null;
    private ?OrderFulfillment $fulfillment = null;
    private ?OrderDispute $dispute = null;
    private ?OrderCancellation $cancellation = null;
    private ?OrderContactRevealRequest $contactRevealRequest = null;
    private ?OrderMeasurementHoldExtension $measurementHoldExtension = null;
    private ?OrderRepairIntervention $repairIntervention = null;
    private ?OrderRunEmpty $runEmpty = null;
    private ?PlatformOrderAdmin $platformOrderAdmin = null;
    private ?MiniPlatformOrderCreation $miniPlatformOrderCreation = null;
    private ?PlatformOrderCustomerShare $platformOrderCustomerShare = null;
    private ?PlatformOrderFinance $platformOrderFinance = null;
    private ?PlatformOrderPricingReview $platformOrderPricingReview = null;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function settings(): SettingsProvider
    {
        if (!$this->settings) $this->settings = new GlobalSettingsProvider();
        return $this->settings;
    }

    public function cipher(): PrivateValueCipher
    {
        if (!$this->cipher) $this->cipher = new GlobalPrivateValueCipher();
        return $this->cipher;
    }

    public function actorResolver(): ApiActorResolver
    {
        if (!$this->actorResolver) {
            $this->actorResolver = new ApiActorResolver(
                new DatabaseApiActorRepository($this->db, $this->settings()),
                $this->settings()
            );
        }
        return $this->actorResolver;
    }

    public function submission(): OrderSubmission
    {
        if (!$this->submission) {
            $baseRepository = new DatabaseOrderSubmissionRepository(
                $this->db,
                $this->settings(),
                $this->cipher(),
                new QiniuObjectStorage(),
                (string)\app_config('app.timezone', 'Asia/Shanghai')
            );
            $repository = new PolicyAwareOrderSubmissionRepository(
                $baseRepository,
                $this->db,
                $this->settings(),
                $this->cipher()
            );
            $this->submission = new OrderSubmission(
                $repository,
                new PlatformOrderAgreementProvider($this->db),
                $this->settings()
            );
        }
        return $this->submission;
    }

    public function center(): OrderCenterQuery
    {
        if (!$this->center) {
            $this->center = new OrderCenterQuery(
                new DatabaseOrderCenterRepository($this->db),
                $this->cipher(),
                new HmacOrderMediaUrlSigner(),
                (string)\app_config('app.timezone', 'Asia/Shanghai')
            );
        }
        return $this->center;
    }

    public function discovery(): OrderDiscoveryQuery
    {
        if (!$this->discovery) {
            $this->discovery = new OrderDiscoveryQuery(
                new DatabaseOrderDiscoveryRepository($this->db),
                new HmacOrderMediaUrlSigner(),
                $this->settings(),
                (string)\app_config('app.timezone', 'Asia/Shanghai')
            );
        }
        return $this->discovery;
    }

    public function detail(): OrderDetailQuery
    {
        if (!$this->detail) {
            $signer = new HmacOrderMediaUrlSigner();
            $base = new DatabaseOrderDetailRepository(
                $this->db,
                $this->settings(),
                $this->cipher(),
                $signer,
                (string)\app_config('app.timezone', 'Asia/Shanghai')
            );
            $this->detail = new OrderDetailQuery(
                new NegotiationAwareOrderDetailRepository(new PlatformMeasurementAwareOrderDetailRepository(
                    $base,
                    $this->db,
                    $this->settings(),
                    $signer,
                    (string)\app_config('app.timezone', 'Asia/Shanghai')
                ), $this->db),
                new PlatformWorkerAgreementVersionProvider($this->db)
            );
        }
        return $this->detail;
    }

    public function invitation(): OrderInvitationClaim
    {
        if (!$this->invitation) {
            $this->invitation = new OrderInvitationClaim(
                new DatabaseOrderInvitationRepository($this->db),
                $this->settings()
            );
        }
        return $this->invitation;
    }

    public function nearbyWorkerOutreach(): OrderNearbyWorkerOutreach
    {
        if (!$this->nearbyWorkerOutreach) {
            $this->nearbyWorkerOutreach = new OrderNearbyWorkerOutreach(
                new DatabaseOrderNearbyWorkerRepository($this->db, $this->settings())
            );
        }
        return $this->nearbyWorkerOutreach;
    }

    public function deal(): OrderDeal
    {
        if (!$this->deal) {
            $this->deal = new OrderDeal(
                new DatabaseOrderDealRepository(
                    $this->db,
                    $this->settings(),
                    (string)\app_config('app.timezone', 'Asia/Shanghai')
                ),
                new PlatformWorkerAgreementVersionProvider($this->db)
            );
        }
        return $this->deal;
    }

    public function quoteSelection(): OrderQuoteSelection
    {
        if (!$this->quoteSelection) {
            $this->quoteSelection = new OrderQuoteSelection(
                new DatabaseOrderQuoteSelectionRepository(
                    $this->db,
                    $this->settings(),
                    (string)\app_config('app.timezone', 'Asia/Shanghai')
                )
            );
        }
        return $this->quoteSelection;
    }

    public function quoteSwitch(): OrderQuoteSwitch
    {
        if (!$this->quoteSwitch) {
            $this->quoteSwitch = new OrderQuoteSwitch(
                new DatabaseOrderQuoteSwitchRepository($this->db)
            );
        }
        return $this->quoteSwitch;
    }

    public function appointment(): OrderAppointment
    {
        if (!$this->appointment) {
            $this->appointment = new OrderAppointment(
                new DatabaseOrderAppointmentRepository($this->db),
                (string)\app_config('app.timezone', 'Asia/Shanghai')
            );
        }
        return $this->appointment;
    }

    public function fulfillment(): OrderFulfillment
    {
        if (!$this->fulfillment) {
            $measurementRepository = new PlatformPricingAwareOrderMeasurementCompletionRepository(
                new DatabaseOrderMeasurementCompletionRepository($this->db),
                $this->db
            );
            $this->fulfillment = new OrderFulfillment(
                new DatabaseOrderFulfillmentContextRepository($this->db),
                new DatabaseOrderChangeRepository($this->db),
                new DatabaseOrderCompletionRepository($this->db),
                $measurementRepository,
                new DatabaseOrderPricingRepository($this->db),
                $this->settings()
            );
        }
        return $this->fulfillment;
    }

    public function dispute(): OrderDispute
    {
        if (!$this->dispute) {
            $this->dispute = new OrderDispute(new DatabaseOrderDisputeRepository($this->db));
        }
        return $this->dispute;
    }

    public function negotiation(): OrderNegotiation
    {
        return new OrderNegotiation(new DatabaseOrderNegotiationRepository($this->db));
    }

    public function cancellation(): OrderCancellation
    {
        if (!$this->cancellation) {
            $this->cancellation = new OrderCancellation(new DatabaseOrderCancellationRepository($this->db));
        }
        return $this->cancellation;
    }

    public function contactRevealRequest(): OrderContactRevealRequest
    {
        if (!$this->contactRevealRequest) {
            $this->contactRevealRequest = new OrderContactRevealRequest(new DatabaseOrderContactRevealRequestRepository($this->db));
        }
        return $this->contactRevealRequest;
    }

    public function measurementHoldExtension(): OrderMeasurementHoldExtension
    {
        if (!$this->measurementHoldExtension) {
            $this->measurementHoldExtension = new OrderMeasurementHoldExtension(
                new DatabaseOrderMeasurementHoldExtensionRepository($this->db, $this->settings())
            );
        }
        return $this->measurementHoldExtension;
    }

    public function repairIntervention(): OrderRepairIntervention
    {
        if (!$this->repairIntervention) {
            $this->repairIntervention = new OrderRepairIntervention(
                new DatabaseOrderRepairInterventionRepository($this->db)
            );
        }
        return $this->repairIntervention;
    }

    public function runEmpty(): OrderRunEmpty
    {
        if (!$this->runEmpty) {
            $this->runEmpty = new OrderRunEmpty(
                new DatabaseOrderRunEmptyRepository($this->db, $this->settings())
            );
        }
        return $this->runEmpty;
    }

    public function platformOrderAdmin(): PlatformOrderAdmin
    {
        if (!$this->platformOrderAdmin) {
            $base = new DatabasePlatformOrderAdminRepository($this->db, $this->cipher());
            $measurementAware = new MeasurementAwarePlatformOrderAdminRepository($base, $this->db);
            $this->platformOrderAdmin = new PlatformOrderAdmin(
                new RepairGuardedPlatformOrderAdminRepository($measurementAware, $this->db),
                $this->settings()
            );
        }
        return $this->platformOrderAdmin;
    }

    public function miniPlatformOrderCreation(): MiniPlatformOrderCreation
    {
        if (!$this->miniPlatformOrderCreation) {
            $this->miniPlatformOrderCreation = new MiniPlatformOrderCreation(
                new DatabaseMiniPlatformOrderCreationRepository($this->db, $this->cipher(), $this->platformOrderAdmin()),
                $this->settings()
            );
        }
        return $this->miniPlatformOrderCreation;
    }

    public function platformOrderPrepaymentManager(): DatabasePlatformOrderBookingFeeManager
    {
        return new DatabasePlatformOrderBookingFeeManager($this->db);
    }

    public function platformOrderCustomerShare(): PlatformOrderCustomerShare
    {
        if (!$this->platformOrderCustomerShare) {
            $this->platformOrderCustomerShare = new PlatformOrderCustomerShare(
                new DatabasePlatformOrderCustomerShareRepository($this->db, $this->cipher())
            );
        }
        return $this->platformOrderCustomerShare;
    }

    public function platformOrderFinance(): PlatformOrderFinance
    {
        if (!$this->platformOrderFinance) {
            $this->platformOrderFinance = new PlatformOrderFinance(
                new DatabasePlatformOrderFinanceRepository($this->db)
            );
        }
        return $this->platformOrderFinance;
    }

    public function platformOrderPricingReview(): PlatformOrderPricingReview
    {
        if (!$this->platformOrderPricingReview) {
            $this->platformOrderPricingReview = new PlatformOrderPricingReview(
                new DatabasePlatformOrderPricingReviewRepository($this->db)
            );
        }
        return $this->platformOrderPricingReview;
    }
}


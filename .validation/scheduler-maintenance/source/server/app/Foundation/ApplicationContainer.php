<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Application\Identity\ActorCapabilityResolver;
use WorkerAdmin\Application\Identity\ApiActorResolver;
use WorkerAdmin\Application\Identity\MerchantAuthSubmission;
use WorkerAdmin\Application\Identity\MobileNumberProtection;
use WorkerAdmin\Application\Identity\OcrResultRecorder;
use WorkerAdmin\Application\Identity\RealNameReauthentication;
use WorkerAdmin\Application\Identity\RealNameSubmission;
use WorkerAdmin\Application\Form\FormSystemFieldManager;
use WorkerAdmin\Application\Store\PublicStoreContactLookup;
use WorkerAdmin\Application\Messaging\ChannelDispatcher;
use WorkerAdmin\Application\Money\PaymentCreation;
use WorkerAdmin\Application\Money\PaymentNotificationProcessor;
use WorkerAdmin\Application\Money\RefundNotificationProcessor;
use WorkerAdmin\Application\Money\UserWalletManager;
use WorkerAdmin\Application\Money\WalletRechargeLifecycle;
use WorkerAdmin\Application\Order\ContactRevealReview;
use WorkerAdmin\Application\Order\WorkerQuoteEligibility;
use WorkerAdmin\Application\Review\ReviewManager;
use WorkerAdmin\Application\Scheduling\SchedulerRunner;
use WorkerAdmin\Contracts\AdminAlertGateway;
use WorkerAdmin\Contracts\ContentModerationGateway;
use WorkerAdmin\Contracts\ObjectStorage;
use WorkerAdmin\Contracts\PaymentGateway;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Contracts\SmsGateway;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\External\AliyunSmsGateway;
use WorkerAdmin\Infrastructure\External\QiniuObjectStorage;
use WorkerAdmin\Infrastructure\External\TestingAdminAlertGateway;
use WorkerAdmin\Infrastructure\External\TestingPaymentGateway;
use WorkerAdmin\Infrastructure\External\TestingSmsGateway;
use WorkerAdmin\Infrastructure\External\TestingSubscribeMessageGateway;
use WorkerAdmin\Infrastructure\External\WeChatContentModerationGateway;
use WorkerAdmin\Infrastructure\External\WeChatPaymentGateway;
use WorkerAdmin\Infrastructure\External\WeChatPaymentGatewayFactory;
use WorkerAdmin\Infrastructure\External\WeChatSubscribeGateway;
use WorkerAdmin\Infrastructure\External\WeComAdminAlertGateway;
use WorkerAdmin\Infrastructure\External\WeChatCloudPublicStoreContactGateway;
use WorkerAdmin\Infrastructure\Messaging\LegacyMerchantAuthSubmissionNotifier;
use WorkerAdmin\Infrastructure\Messaging\LegacyOrderNotificationAdapter;
use WorkerAdmin\Infrastructure\Messaging\LegacyRealNameSubmissionNotifier;
use WorkerAdmin\Infrastructure\Persistence\DatabaseMerchantAuthSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseApiActorRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOcrResultRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAgreementDocumentRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseActiveSessionGateway;
use WorkerAdmin\Infrastructure\Persistence\DatabaseFormSystemFieldRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseFormSnapshotBuilder;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderContactRevealRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseOrderQuoteReadRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePaidPaymentSettlement;
use WorkerAdmin\Infrastructure\Persistence\DatabasePaymentCreationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabasePaymentNotificationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseRefundResultSettlement;
use WorkerAdmin\Infrastructure\Persistence\DatabaseRealNameReauthenticationRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseRealNameSubmissionRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseReviewRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTransactionManager;
use WorkerAdmin\Infrastructure\Persistence\DatabaseUserWalletRepository;
use WorkerAdmin\Infrastructure\Persistence\DatabaseWalletRechargeRepository;
use WorkerAdmin\Infrastructure\Safety\TestingEnvironmentPolicy;
use WorkerAdmin\Infrastructure\Scheduling\LegacySchedulerGateway;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Settings\GlobalSettingsProvider;

/** 应用组合根 / 轻量依赖容器：只组装对象，不承载业务规则。 */
final class ApplicationContainer
{
    private Database $db;
    private ?SettingsProvider $settings = null;
    private ?TransactionManager $transaction = null;
    private ?PrivateValueCipher $privateCipher = null;
    private ?UserWalletManager $wallet = null;
    private ?WalletRechargeLifecycle $walletRechargeLifecycle = null;
    private ?PaymentCreation $paymentCreation = null;
    private ?PaymentNotificationProcessor $paymentNotifications = null;
    private ?RefundNotificationProcessor $refundNotifications = null;
    private ?WorkerQuoteEligibility $quoteEligibility = null;
    private ?ContactRevealReview $contactRevealReview = null;
    private ?MobileNumberProtection $mobileProtection = null;
    private ?ActorCapabilityResolver $actorCapabilities = null;
    private ?OcrResultRecorder $ocrResultRecorder = null;
    private ?RealNameReauthentication $realNameReauthentication = null;
    private ?RealNameSubmission $realNameSubmission = null;
    private ?MerchantAuthSubmission $merchantAuthSubmission = null;
    private ?FormSystemFieldManager $formSystemFieldManager = null;
    private ?DatabaseFormSnapshotBuilder $formSnapshotBuilder = null;
    private ?PublicStoreContactLookup $publicStoreContactLookup = null;
    private ?PaymentGateway $paymentGateway = null;
    private ?SmsGateway $smsGateway = null;
    private ?ObjectStorage $objectStorage = null;
    private ?SubscribeMessageGateway $subscribeMessages = null;
    private ?AdminAlertGateway $adminAlerts = null;
    private ?ContentModerationGateway $contentModeration = null;
    private ?ChannelDispatcher $messaging = null;
    private ?ReviewManager $reviewManager = null;

    public function __construct(Database $db) { $this->db = $db; }
    public function db(): Database { return $this->db; }

    public function agreements(): PlatformAgreements
    {
        return new PlatformAgreements(new DatabaseAgreementDocumentRepository($this->db));
    }

    public function settings(): SettingsProvider
    {
        if (!$this->settings) $this->settings = new GlobalSettingsProvider();
        return $this->settings;
    }

    public function apiActorResolver(): ApiActorResolver
    {
        return new ApiActorResolver(new DatabaseApiActorRepository($this->db, $this->settings()), $this->settings(), TestingEnvironmentPolicy::active());
    }

    public function transaction(): TransactionManager
    {
        if (!$this->transaction) $this->transaction = new DatabaseTransactionManager($this->db);
        return $this->transaction;
    }

    public function privateCipher(): PrivateValueCipher
    {
        if (!$this->privateCipher) $this->privateCipher = new GlobalPrivateValueCipher();
        return $this->privateCipher;
    }

    public function wallet(): UserWalletManager
    {
        if (!$this->wallet) $this->wallet = new UserWalletManager(new DatabaseUserWalletRepository($this->db), $this->settings(), $this->transaction());
        return $this->wallet;
    }

    public function walletRechargeLifecycle(): WalletRechargeLifecycle
    {
        if (!$this->walletRechargeLifecycle) {
            $this->walletRechargeLifecycle = new WalletRechargeLifecycle(
                new DatabaseWalletRechargeRepository($this->db),
                $this->paymentGateway()
            );
        }
        return $this->walletRechargeLifecycle;
    }

    public function paymentCreation(): PaymentCreation
    {
        if (!$this->paymentCreation) {
            $this->paymentCreation = new PaymentCreation(
                new DatabasePaymentCreationRepository($this->db, $this->settings()),
                $this->paymentGateway(),
                $this->transaction()
            );
        }
        return $this->paymentCreation;
    }

    public function paymentNotifications(): PaymentNotificationProcessor
    {
        if (!$this->paymentNotifications) {
            $this->paymentNotifications = new PaymentNotificationProcessor(
                new DatabasePaymentNotificationRepository($this->db),
                new DatabasePaidPaymentSettlement($this->db, $this->settings()),
                new WeChatPaymentGatewayFactory(),
                $this->transaction()
            );
        }
        return $this->paymentNotifications;
    }

    public function refundNotifications(): RefundNotificationProcessor
    {
        if (!$this->refundNotifications) {
            $paidSettlement = new DatabasePaidPaymentSettlement($this->db, $this->settings());
            $this->refundNotifications = new RefundNotificationProcessor(
                new DatabasePaymentNotificationRepository($this->db),
                new DatabaseRefundResultSettlement($this->db, $paidSettlement),
                $this->paymentGateway(),
                $this->transaction()
            );
        }
        return $this->refundNotifications;
    }

    public function quoteEligibility(): WorkerQuoteEligibility
    {
        if (!$this->quoteEligibility) $this->quoteEligibility = new WorkerQuoteEligibility(new DatabaseOrderQuoteReadRepository($this->db), $this->settings());
        return $this->quoteEligibility;
    }

    public function contactRevealReview(): ContactRevealReview
    {
        if (!$this->contactRevealReview) $this->contactRevealReview = new ContactRevealReview($this->transaction(), new DatabaseOrderContactRevealRepository($this->db), new LegacyOrderNotificationAdapter($this->db));
        return $this->contactRevealReview;
    }

    public function mobileProtection(): MobileNumberProtection
    {
        if (!$this->mobileProtection) $this->mobileProtection = new MobileNumberProtection($this->privateCipher());
        return $this->mobileProtection;
    }

    public function actorCapabilities(): ActorCapabilityResolver
    {
        if (!$this->actorCapabilities) $this->actorCapabilities = new ActorCapabilityResolver($this->settings());
        return $this->actorCapabilities;
    }

    public function ocrResults(): OcrResultRecorder
    {
        if (!$this->ocrResultRecorder) {
            $this->ocrResultRecorder = new OcrResultRecorder(
                new DatabaseOcrResultRepository($this->db),
                $this->settings(),
                $this->transaction(),
                $this->privateCipher()
            );
        }
        return $this->ocrResultRecorder;
    }

    public function realNameReauthentication(): RealNameReauthentication
    {
        if (!$this->realNameReauthentication) {
            $this->realNameReauthentication = new RealNameReauthentication(
                new DatabaseRealNameReauthenticationRepository($this->db, $this->privateCipher()),
                $this->settings()
            );
        }
        return $this->realNameReauthentication;
    }

    public function realNameSubmission(): RealNameSubmission
    {
        if (!$this->realNameSubmission) {
            $this->realNameSubmission = new RealNameSubmission(
                new DatabaseRealNameSubmissionRepository($this->db, $this->privateCipher(), $this->apiActorResolver(), $this->settings()),
                new LegacyRealNameSubmissionNotifier($this->db),
                $this->transaction()
            );
        }
        return $this->realNameSubmission;
    }

    public function merchantAuthSubmission(): MerchantAuthSubmission
    {
        if (!$this->merchantAuthSubmission) {
            $this->merchantAuthSubmission = new MerchantAuthSubmission(
                new DatabaseMerchantAuthSubmissionRepository($this->db, $this->apiActorResolver()),
                new LegacyMerchantAuthSubmissionNotifier($this->db),
                $this->transaction()
            );
        }
        return $this->merchantAuthSubmission;
    }

    public function formSystemFields(): FormSystemFieldManager
    {
        if (!$this->formSystemFieldManager) $this->formSystemFieldManager = new FormSystemFieldManager(new DatabaseFormSystemFieldRepository($this->db));
        return $this->formSystemFieldManager;
    }

    public function formSnapshotBuilder(): DatabaseFormSnapshotBuilder
    {
        if (!$this->formSnapshotBuilder) $this->formSnapshotBuilder = new DatabaseFormSnapshotBuilder($this->db);
        return $this->formSnapshotBuilder;
    }

    public function publicStoreContactLookup(): PublicStoreContactLookup
    {
        if (!$this->publicStoreContactLookup) {
            $this->publicStoreContactLookup = new PublicStoreContactLookup(
                new DatabaseActiveSessionGateway($this->db),
                new WeChatCloudPublicStoreContactGateway($this->settings()),
                $this->settings()
            );
        }
        return $this->publicStoreContactLookup;
    }

    public function paymentGateway(): PaymentGateway
    {
        if (!$this->paymentGateway) $this->paymentGateway = new TestingPaymentGateway(new WeChatPaymentGateway());
        return $this->paymentGateway;
    }

    public function smsGateway(): SmsGateway
    {
        if (!$this->smsGateway) $this->smsGateway = new TestingSmsGateway(new AliyunSmsGateway());
        return $this->smsGateway;
    }

    public function objectStorage(): ObjectStorage
    {
        if (!$this->objectStorage) $this->objectStorage = new QiniuObjectStorage();
        return $this->objectStorage;
    }

    public function subscribeMessages(): SubscribeMessageGateway
    {
        if (!$this->subscribeMessages) $this->subscribeMessages = new TestingSubscribeMessageGateway(new WeChatSubscribeGateway());
        return $this->subscribeMessages;
    }

    public function adminAlerts(): AdminAlertGateway
    {
        if (!$this->adminAlerts) $this->adminAlerts = new TestingAdminAlertGateway(new WeComAdminAlertGateway());
        return $this->adminAlerts;
    }

    public function contentModeration(): ContentModerationGateway
    {
        if (!$this->contentModeration) $this->contentModeration = new WeChatContentModerationGateway();
        return $this->contentModeration;
    }

    public function messaging(): ChannelDispatcher
    {
        if (!$this->messaging) $this->messaging = new ChannelDispatcher($this->smsGateway(), $this->subscribeMessages(), $this->adminAlerts());
        return $this->messaging;
    }

    public function reviews(): ReviewManager
    {
        if (!$this->reviewManager) $this->reviewManager = new ReviewManager(new DatabaseReviewRepository($this->db), $this->settings());
        return $this->reviewManager;
    }

    public function scheduler(int $limit = 100): SchedulerRunner
    {
        return new SchedulerRunner(new LegacySchedulerGateway($this->db, $limit), $this->walletRechargeLifecycle());
    }
}

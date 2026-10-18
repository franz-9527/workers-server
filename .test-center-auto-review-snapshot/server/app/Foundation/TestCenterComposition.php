<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Testing\TestCenter;
use WorkerAdmin\Application\Testing\TestScenarioCatalog;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTestCenterRepository;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionSafetyRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFailureReplay;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterReplayCaptureRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSchedulerInspector;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSchedulerActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAdminAdjudicationActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAdminAdjudicationVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAutoReviewVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAutoReviewCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAdminRefundReplayRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupGuardRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupSafetyRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterM1Repository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterMeasurementActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterMeasurementCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterMeasurementRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterMeasurementVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterNegativeQuoteRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterNegotiationActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterNegotiationCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterNegotiationVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterProgressVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterRefundActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterRefundCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterRefundVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterRepairActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterRepairCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterRepairVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSingleGrabActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterSingleGrabVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterPlatformMeasurementRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterPlatformMeasurementActionRunner;

use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterProxyOrderActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterProxyOrderRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterInvitationActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterInvitationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterMeasurementTailActionRunner;

final class TestCenterComposition
{
    public static function build(Database $db): TestCenter
    {
        $cipher=new GlobalPrivateValueCipher();
        $base=new DatabaseTestCenterRepository($db,$cipher);
        $m1=new DatabaseTestCenterM1Repository($db,$cipher,$base);
        $measurementBase=new DatabaseTestCenterMeasurementRepository($db,$cipher,$m1);
        $negative=new DatabaseTestCenterNegativeQuoteRepository($db,$measurementBase);
        $dealProgress=new DatabaseTestCenterProgressVerificationRepository($db,$negative);
        $singleGrabProgress=new DatabaseTestCenterSingleGrabVerificationRepository($db,$dealProgress);
        $measurementProgress=new DatabaseTestCenterMeasurementVerificationRepository($db,$singleGrabProgress);
        $fulfillmentProgress=new DatabaseTestCenterFulfillmentVerificationRepository($db,$measurementProgress);
        $negotiationProgress=new DatabaseTestCenterNegotiationVerificationRepository($db,$fulfillmentProgress);
        $refundProgress=new DatabaseTestCenterRefundVerificationRepository($db,$negotiationProgress);
        $repairProgress=new DatabaseTestCenterRepairVerificationRepository($db,$refundProgress);
        $adminProgress=new DatabaseTestCenterAdminAdjudicationVerificationRepository($db,$repairProgress);
        $financeProgress=new DatabaseTestCenterDisputeFinanceVerificationRepository($db,$adminProgress);
        $autoReviewProgress=new DatabaseTestCenterAutoReviewVerificationRepository($db,$financeProgress);
        $financeCleanup=new DatabaseTestCenterDisputeFinanceCleanupRepository($db,$autoReviewProgress);
        $fulfillmentCleanup=new DatabaseTestCenterFulfillmentCleanupRepository($db,$financeCleanup);
        $negotiationCleanup=new DatabaseTestCenterNegotiationCleanupRepository($db,$fulfillmentCleanup);
        $refundCleanup=new DatabaseTestCenterRefundCleanupRepository($db,$negotiationCleanup);
        $repairCleanup=new DatabaseTestCenterRepairCleanupRepository($db,$refundCleanup);
        $measurementCleanup=new DatabaseTestCenterMeasurementCleanupRepository($db,$repairCleanup);
        $platformMeasurement=new DatabaseTestCenterPlatformMeasurementRepository($db,$measurementCleanup);
        $proxyFactory=new DatabaseTestCenterProxyOrderActionRunner($db,new DatabaseTestCenterActionRunner($db));
        $proxyRepository=new DatabaseTestCenterProxyOrderRepository($db,$platformMeasurement,$proxyFactory);
        $invitationRepository=new DatabaseTestCenterInvitationRepository($db,$proxyRepository);
        $autoReviewCleanup=new DatabaseTestCenterAutoReviewCleanupRepository($db,$invitationRepository);
        $cleanupGuard=new DatabaseTestCenterCleanupGuardRepository($db,$autoReviewCleanup);
        $repository=new DatabaseTestCenterCleanupSafetyRepository($db,$cleanupGuard);

        $dealActions=new DatabaseTestCenterActionRunner($db);
        $singleGrabActions=new DatabaseTestCenterSingleGrabActionRunner($db,$dealActions);
        $measurementActions=new DatabaseTestCenterMeasurementActionRunner($db,$singleGrabActions);
        $fulfillmentActions=new DatabaseTestCenterFulfillmentActionRunner($db,$measurementActions);
        $negotiationActions=new DatabaseTestCenterNegotiationActionRunner($db,$fulfillmentActions);
        $refundActions=new DatabaseTestCenterRefundActionRunner($db,$negotiationActions);
        $repairActions=new DatabaseTestCenterRepairActionRunner($db,$refundActions);
        $adminActions=new DatabaseTestCenterAdminAdjudicationActionRunner($db,$repairActions);
        $financeActions=new DatabaseTestCenterDisputeFinanceActionRunner($db,$adminActions);
        $adminReplayActions=new DatabaseTestCenterAdminRefundReplayRunner($db,$financeActions);
        $platformActions=new DatabaseTestCenterPlatformMeasurementActionRunner($db,$adminReplayActions);
        $proxyActions=new DatabaseTestCenterProxyOrderActionRunner($db,$platformActions);
        $invitationActions=new DatabaseTestCenterInvitationActionRunner($db,$proxyActions,$repository);
        $tailActions=new DatabaseTestCenterMeasurementTailActionRunner($db,$invitationActions,$repository);
        $schedulerActions=new DatabaseTestCenterSchedulerActionRunner($db,$tailActions);
        $captureActions=new DatabaseTestCenterReplayCaptureRunner($db,$schedulerActions);
        $actions=new DatabaseTestCenterActionSafetyRunner($db,$captureActions);
        $failureReplay=new DatabaseTestCenterFailureReplay($db,$actions);
        $schedulerInspector=new DatabaseTestCenterSchedulerInspector($db);
        return new TestCenter($repository,new TestScenarioCatalog(),$actions,$failureReplay,$schedulerInspector);
    }
}


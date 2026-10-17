<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Testing\TestCenter;
use WorkerAdmin\Application\Testing\TestScenarioCatalog;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTestCenterRepository;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAdminAdjudicationActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterAdminAdjudicationVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupGuardRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterDisputeFinanceVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterM1Repository;
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

final class TestCenterComposition
{
    public static function build(Database $db): TestCenter
    {
        $cipher=new GlobalPrivateValueCipher();
        $base=new DatabaseTestCenterRepository($db,$cipher);
        $m1=new DatabaseTestCenterM1Repository($db,$cipher,$base);
        $negative=new DatabaseTestCenterNegativeQuoteRepository($db,$m1);
        $dealProgress=new DatabaseTestCenterProgressVerificationRepository($db,$negative);
        $fulfillmentProgress=new DatabaseTestCenterFulfillmentVerificationRepository($db,$dealProgress);
        $negotiationProgress=new DatabaseTestCenterNegotiationVerificationRepository($db,$fulfillmentProgress);
        $refundProgress=new DatabaseTestCenterRefundVerificationRepository($db,$negotiationProgress);
        $repairProgress=new DatabaseTestCenterRepairVerificationRepository($db,$refundProgress);
        $adminProgress=new DatabaseTestCenterAdminAdjudicationVerificationRepository($db,$repairProgress);
        $financeProgress=new DatabaseTestCenterDisputeFinanceVerificationRepository($db,$adminProgress);
        $financeCleanup=new DatabaseTestCenterDisputeFinanceCleanupRepository($db,$financeProgress);
        $fulfillmentCleanup=new DatabaseTestCenterFulfillmentCleanupRepository($db,$financeCleanup);
        $negotiationCleanup=new DatabaseTestCenterNegotiationCleanupRepository($db,$fulfillmentCleanup);
        $refundCleanup=new DatabaseTestCenterRefundCleanupRepository($db,$negotiationCleanup);
        $repairCleanup=new DatabaseTestCenterRepairCleanupRepository($db,$refundCleanup);
        $repository=new DatabaseTestCenterCleanupGuardRepository($db,$repairCleanup);
        $dealActions=new DatabaseTestCenterActionRunner($db);
        $fulfillmentActions=new DatabaseTestCenterFulfillmentActionRunner($db,$dealActions);
        $negotiationActions=new DatabaseTestCenterNegotiationActionRunner($db,$fulfillmentActions);
        $refundActions=new DatabaseTestCenterRefundActionRunner($db,$negotiationActions);
        $repairActions=new DatabaseTestCenterRepairActionRunner($db,$refundActions);
        $adminActions=new DatabaseTestCenterAdminAdjudicationActionRunner($db,$repairActions);
        $actions=new DatabaseTestCenterDisputeFinanceActionRunner($db,$adminActions);
        return new TestCenter($repository,new TestScenarioCatalog(),$actions);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Testing\TestCenter;
use WorkerAdmin\Application\Testing\TestScenarioCatalog;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTestCenterRepository;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupGuardRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentActionRunner;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentCleanupRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterFulfillmentVerificationRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterM1Repository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterNegativeQuoteRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterProgressVerificationRepository;

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
        $fulfillmentCleanup=new DatabaseTestCenterFulfillmentCleanupRepository($db,$fulfillmentProgress);
        $repository=new DatabaseTestCenterCleanupGuardRepository($db,$fulfillmentCleanup);
        $dealActions=new DatabaseTestCenterActionRunner($db);
        $actions=new DatabaseTestCenterFulfillmentActionRunner($db,$dealActions);
        return new TestCenter($repository,new TestScenarioCatalog(),$actions);
    }
}

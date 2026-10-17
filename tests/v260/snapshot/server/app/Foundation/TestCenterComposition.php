<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Testing\TestCenter;
use WorkerAdmin\Application\Testing\TestScenarioCatalog;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Persistence\DatabaseTestCenterRepository;
use WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterCleanupGuardRepository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterM1Repository;
use WorkerAdmin\Infrastructure\Testing\DatabaseTestCenterNegativeQuoteRepository;

final class TestCenterComposition
{
    public static function build(Database $db): TestCenter
    {
        $cipher=new GlobalPrivateValueCipher();
        $base=new DatabaseTestCenterRepository($db,$cipher);
        $m1=new DatabaseTestCenterM1Repository($db,$cipher,$base);
        $negative=new DatabaseTestCenterNegativeQuoteRepository($db,$m1);
        return new TestCenter(
            new DatabaseTestCenterCleanupGuardRepository($db,$negative),
            new TestScenarioCatalog()
        );
    }
}

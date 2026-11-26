<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface ChatRecoveryReadiness
{
    public function ensureReady(array $config,string $environment):void;
}

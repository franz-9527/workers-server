<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Settings;

use WorkerAdmin\Contracts\AdminConfigurationAudit;

final class GlobalAdminConfigurationAudit implements AdminConfigurationAudit
{

    public function record(array $arguments):void { \audit(...$arguments); }
}

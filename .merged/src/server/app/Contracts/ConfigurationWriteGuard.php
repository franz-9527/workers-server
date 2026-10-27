<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface ConfigurationWriteGuard { public function run(callable $operation); }

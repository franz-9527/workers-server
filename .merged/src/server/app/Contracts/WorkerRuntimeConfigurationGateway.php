<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface WorkerRuntimeConfigurationGateway { public function sync(array $values):array; }

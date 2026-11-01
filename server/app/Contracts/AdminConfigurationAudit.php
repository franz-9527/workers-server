<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface AdminConfigurationAudit { public function record(array $arguments):void; }

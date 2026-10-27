<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageDiagnostics { public function write(string $channel,string $context,\Throwable $error):void; }

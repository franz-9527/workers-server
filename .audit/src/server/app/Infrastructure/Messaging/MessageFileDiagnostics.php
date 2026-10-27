<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Contracts\MessageDiagnostics;

final class MessageFileDiagnostics implements MessageDiagnostics
{

    public function write(string $channel,string $context,\Throwable $error):void
    { \write_log($channel,'['.date('c').'] '.$context.' '.mb_substr($error->getMessage(),0,500)."\n"); }
}

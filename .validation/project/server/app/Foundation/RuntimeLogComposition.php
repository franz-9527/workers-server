<?php

declare(strict_types=1);

namespace WorkerAdmin\Foundation;

use WorkerAdmin\Application\Admin\RuntimeLogConsole;
use WorkerAdmin\Infrastructure\Persistence\FileRuntimeLogRepository;

final class RuntimeLogComposition
{
    public static function build(?string $directory = null): RuntimeLogConsole
    {
        $directory = $directory !== null && trim($directory) !== ''
            ? $directory
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

        return new RuntimeLogConsole(new FileRuntimeLogRepository($directory));
    }
}

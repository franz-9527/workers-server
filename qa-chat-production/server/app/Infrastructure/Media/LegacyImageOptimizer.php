<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Media;

use WorkerAdmin\Contracts\ImageOptimizer;
use WorkerAdmin\Services\ImageOptimizerService;

final class LegacyImageOptimizer implements ImageOptimizer
{
    private ImageOptimizerService $service;

    public function __construct(?ImageOptimizerService $service = null)
    {
        $this->service = $service ?: new ImageOptimizerService();
    }

    public function optimize(string $sourcePath): array
    {
        return $this->service->optimize($sourcePath);
    }
}

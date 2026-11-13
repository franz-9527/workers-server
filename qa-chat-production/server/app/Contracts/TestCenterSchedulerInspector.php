<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface TestCenterSchedulerInspector
{
    /** @return array<string,mixed> */
    public function inspect(string $instancePublicId): array;
}

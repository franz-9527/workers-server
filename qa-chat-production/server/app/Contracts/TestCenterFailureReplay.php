<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface TestCenterFailureReplay
{
    /** @return array<string,mixed> */
    public function available(string $instancePublicId): array;

    /** @return array<string,mixed> */
    public function replay(string $instancePublicId, string $runPublicId, int $adminId, string $requestKey): array;
}

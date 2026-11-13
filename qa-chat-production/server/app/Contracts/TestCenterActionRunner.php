<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface TestCenterActionRunner
{
    /** @return array<string,mixed> */
    public function available(string $instancePublicId): array;

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function run(string $instancePublicId, string $actionKey, array $input, int $adminId, string $requestKey): array;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface TestCenterRepository
{
    /** @return array<string,mixed> */
    public function environmentDiagnostics(): array;

    /** @return array<int,array<string,mixed>> */
    public function listInstances(array $filters = []): array;

    /** @return array<string,mixed> */
    public function instanceDetail(string $publicId): array;

    /** @param array<string,mixed> $scenario @param array<string,mixed> $params @return array<string,mixed> */
    public function build(array $scenario, array $params, int $adminId, string $requestKey, ?int $sourceInstanceId = null, ?int $rebuiltFromInstanceId = null): array;

    /** @return array<string,mixed> */
    public function verify(string $publicId, int $adminId): array;

    /** @return array<string,mixed> */
    public function cleanupPreview(string $publicId): array;

    /** @return array<string,mixed> */
    public function cleanup(string $publicId, int $adminId, string $expectedPlanDigest): array;

    /** @return array<string,mixed> */
    public function rebuildSource(string $publicId): array;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface TestCenterRepository
{
    public function environmentDiagnostics(): array;
    public function listInstances(array $filters = []): array;
    public function instanceDetail(string $publicId): array;
    public function build(array $scenario, array $params, int $adminId, string $requestKey, ?int $sourceInstanceId = null, ?int $rebuiltFromInstanceId = null): array;
    public function verify(string $publicId, int $adminId): array;
    public function cleanupPreview(string $publicId): array;
    public function cleanup(string $publicId, int $adminId, string $expectedPlanDigest): array;
    public function rebuildSource(string $publicId): array;
}

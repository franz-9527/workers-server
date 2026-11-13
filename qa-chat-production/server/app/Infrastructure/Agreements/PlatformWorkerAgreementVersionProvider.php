<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Agreements;

use WorkerAdmin\Contracts\WorkerAgreementVersionProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Agreements\PlatformAgreements;
use WorkerAdmin\Infrastructure\Persistence\DatabaseAgreementDocumentRepository;

final class PlatformWorkerAgreementVersionProvider implements WorkerAgreementVersionProvider
{
    private PlatformAgreements $service;

    public function __construct(Database $db)
    {
        $this->service = new PlatformAgreements(new DatabaseAgreementDocumentRepository($db));
    }

    public function currentVersions(): array
    {
        $versions = [];
        foreach ($this->service->publicDocuments('worker_flow', 'worker') as $item) {
            $key = trim((string)($item['key'] ?? ''));
            $version = trim((string)($item['version'] ?? ''));
            if ($key !== '' && $version !== '') $versions[$key] = $version;
        }
        return $versions;
    }

    public function validateVersions(array $versions): array
    {
        return $this->service->validatePublishedVersions($versions, 'worker_flow', 'worker');
    }
}

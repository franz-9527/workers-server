<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AgreementAcknowledgementAudit
{
    /** @param array<string,string> $versions */
    public function recordWorkerOnboarding(int $userId, int $workerId, array $versions, string $ipAddress, string $userAgent): void;
}

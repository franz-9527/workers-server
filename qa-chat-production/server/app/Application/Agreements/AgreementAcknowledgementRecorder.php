<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Agreements;

use WorkerAdmin\Contracts\AgreementAcknowledgementAudit;

final class AgreementAcknowledgementRecorder
{
    private AgreementAcknowledgementAudit $audit;

    public function __construct(AgreementAcknowledgementAudit $audit)
    {
        $this->audit = $audit;
    }

    /** @param array<string,mixed> $actor @param array<string,string> $versions @param mixed $resultData */
    public function recordWorkerOnboarding(array $actor, array $versions, $resultData, string $ipAddress, string $userAgent): void
    {
        $workerId = (int)($actor['worker_id'] ?? 0);
        if (is_array($resultData)) {
            $workerId = max($workerId, (int)($resultData['worker_id'] ?? $resultData['id'] ?? 0));
        }
        $userId = (int)($actor['user_id'] ?? 0);
        if ($userId < 1 && $workerId < 1) return;

        $cleanVersions = [];
        foreach ($versions as $key => $value) {
            $key = trim((string)$key);
            $value = trim((string)$value);
            if ($key !== '' && $value !== '') $cleanVersions[$key] = $value;
        }
        if (!$cleanVersions) return;

        $this->audit->recordWorkerOnboarding(
            $userId,
            $workerId,
            $cleanVersions,
            mb_substr(trim($ipAddress), 0, 45),
            mb_substr(trim($userAgent), 0, 255)
        );
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AgreementAcknowledgementAudit;
use WorkerAdmin\Core\Database;

final class DatabaseAgreementAcknowledgementAudit implements AgreementAcknowledgementAudit
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function recordWorkerOnboarding(int $userId, int $workerId, array $versions, string $ipAddress, string $userAgent): void
    {
        $snapshot = json_encode([
            'agreement_versions' => $versions,
            'worker_agreements_accepted' => true,
            'safety_commitment_accepted' => true,
            'accepted_at' => gmdate('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshot)) return;

        $actorId = $workerId > 0 ? $workerId : ($userId > 0 ? $userId : null);
        $this->db->insert(
            "INSERT INTO audit_logs(request_id,actor_type,actor_id,actor_name_snapshot,action,entity_type,entity_id,before_json,after_json,reason,ip_address,user_agent,result,created_at)
             VALUES(:request_id,:actor_type,:actor_id,NULL,'agreement.worker_onboarding_acknowledged','worker_onboarding',:entity_id,NULL,:after_json,'师傅提交入驻资料时确认当前协议版本',:ip,:ua,'success',UTC_TIMESTAMP())",
            [
                'request_id' => \request_id(),
                'actor_type' => $workerId > 0 ? 'worker' : 'user',
                'actor_id' => $actorId,
                'entity_id' => (string)($workerId > 0 ? $workerId : $userId),
                'after_json' => $snapshot,
                'ip' => $ipAddress,
                'ua' => $userAgent,
            ]
        );
    }
}

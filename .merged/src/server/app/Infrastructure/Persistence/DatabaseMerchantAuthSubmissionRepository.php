<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\MerchantAuthSubmissionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Identity\ApiActorResolver;

final class DatabaseMerchantAuthSubmissionRepository implements MerchantAuthSubmissionRepository
{
    private Database $db;
    private ApiActorResolver $actorResolver;

    public function __construct(Database $db, ApiActorResolver $actorResolver)
    {
        $this->db = $db;
        $this->actorResolver = $actorResolver;
    }

    public function findActorByTokenHash(string $tokenHash): ?array
    {
        return $this->actorResolver->resolveTokenHash($tokenHash, false);
    }

    public function touchSession(int $sessionId): void
    {
        $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id', ['id' => $sessionId]);
    }

    public function findProfileForUpdate(int $userId): ?array
    {
        $row = $this->db->fetch(
            'SELECT id,business_name,business_address,license_media_file_id,verification_status FROM merchant_profiles WHERE user_id=:user_id LIMIT 1 FOR UPDATE',
            ['user_id' => $userId]
        );
        return $row ?: null;
    }

    public function markPending(int $merchantId, string $businessName, string $businessAddress): void
    {
        $this->db->execute(
            "UPDATE merchant_profiles
             SET business_name=:name,business_address=:address,verification_status='pending',review_reason=NULL,
                 submitted_at=UTC_TIMESTAMP(),reviewed_at=NULL,reviewed_by=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['name' => $businessName, 'address' => $businessAddress, 'id' => $merchantId]
        );
    }
}

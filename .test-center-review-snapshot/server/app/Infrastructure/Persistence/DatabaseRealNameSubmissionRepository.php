<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\RealNameSubmissionRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Identity\ApiActorResolver;

final class DatabaseRealNameSubmissionRepository implements RealNameSubmissionRepository
{
    private Database $db;
    private ApiActorResolver $actorResolver;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, PrivateValueCipher $cipher, ApiActorResolver $actorResolver)
    {
        $this->db = $db;
        $this->actorResolver = $actorResolver;
        $this->cipher = $cipher;
    }

    public function findActorByTokenHash(string $tokenHash): ?array
    {
        return $this->actorResolver->resolveTokenHash($tokenHash, false);
    }

    public function touchSession(int $sessionId): void
    {
        $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id', ['id' => $sessionId]);
    }

    public function findVerificationForUpdate(int $workerId): ?array
    {
        $row = $this->db->fetch(
            "SELECT id,status,real_name_ciphertext,id_number_ciphertext
             FROM worker_real_name_verifications
             WHERE worker_id=:worker_id LIMIT 1 FOR UPDATE",
            ['worker_id' => $workerId]
        );
        if (!$row) return null;
        $row['real_name'] = $this->decrypt((string)($row['real_name_ciphertext'] ?? ''), 'worker-real-name');
        $row['id_number'] = $this->decrypt((string)($row['id_number_ciphertext'] ?? ''), 'worker-id-number');
        unset($row['real_name_ciphertext'], $row['id_number_ciphertext']);
        return $row;
    }

    public function hasCurrentIdMedia(int $verificationId): bool
    {
        $rows = $this->db->fetchAll(
            "SELECT purpose FROM media_links
             WHERE entity_type='worker_verification' AND entity_id=:id AND purpose IN ('id_front','id_back')",
            ['id' => $verificationId]
        );
        $purposes = array_column($rows, 'purpose');
        return in_array('id_front', $purposes, true) && in_array('id_back', $purposes, true);
    }

    public function markPending(int $verificationId, string $realName, string $idNumber): void
    {
        $masked = substr($idNumber, 0, 3) . '***********' . substr($idNumber, -4);
        $this->db->execute(
            "UPDATE worker_real_name_verifications
             SET real_name_masked=:name_masked,real_name_ciphertext=:name_cipher,
                 id_number_masked=:id_masked,id_number_ciphertext=:id_cipher,id_number_hmac=:id_hmac,
                 status='pending',review_reason=NULL,reviewed_by=NULL,reviewed_at=NULL,
                 submitted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'name_masked' => mb_substr($realName, 0, 1) . '**',
                'name_cipher' => $this->cipher->encrypt($realName, 'worker-real-name'),
                'id_masked' => $masked,
                'id_cipher' => $this->cipher->encrypt($idNumber, 'worker-id-number'),
                'id_hmac' => $this->cipher->hmac($idNumber, 'worker-id-number'),
                'id' => $verificationId,
            ]
        );
    }

    private function decrypt(string $ciphertext, string $context): string
    {
        if ($ciphertext === '') return '';
        try { return $this->cipher->decrypt($ciphertext, $context); }
        catch (\Throwable $exception) { return ''; }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\OcrResultRepository;
use WorkerAdmin\Core\Database;

final class DatabaseOcrResultRepository implements OcrResultRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findActorByTokenHash(string $tokenHash): ?array
    {
        $row = $this->db->fetch(
            "SELECT s.id AS session_id,u.id AS user_id,w.id AS worker_id
             FROM login_sessions s
             JOIN users u ON u.id=s.user_id AND u.status='active'
             LEFT JOIN worker_profiles w ON w.user_id=u.id
             WHERE s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
             LIMIT 1",
            ['hash' => $tokenHash]
        );
        if (!$row) return null;
        $row['session_id'] = (int)$row['session_id'];
        $row['user_id'] = (int)$row['user_id'];
        $row['worker_id'] = $row['worker_id'] === null ? null : (int)$row['worker_id'];
        return $row;
    }

    public function touchSession(int $sessionId): void
    {
        $this->db->execute(
            'UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id',
            ['id' => $sessionId]
        );
    }

    public function findUsage(string $clientMsgId): ?array
    {
        $row = $this->db->fetch(
            'SELECT id,user_id,scene,ocr_type,result_digest FROM ocr_usage_logs WHERE client_msg_id=:id LIMIT 1',
            ['id' => $clientMsgId]
        );
        return $row ?: null;
    }

    public function countSuccessfulUsage(string $quotaDay): int
    {
        return (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM ocr_usage_logs WHERE quota_day=:day AND status='success'",
            ['day' => $quotaDay]
        );
    }

    public function findRealNameForUpdate(int $workerId): ?array
    {
        $row = $this->db->fetch(
            'SELECT id,status,ocr_payload_ciphertext FROM worker_real_name_verifications WHERE worker_id=:worker_id LIMIT 1 FOR UPDATE',
            ['worker_id' => $workerId]
        );
        return $row ?: null;
    }

    public function createRealNameDraft(int $workerId): array
    {
        $id = $this->db->insert(
            "INSERT INTO worker_real_name_verifications(worker_id,status,created_at,updated_at)
             VALUES(:worker_id,'draft',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['worker_id' => $workerId]
        );
        return ['id' => $id, 'status' => 'draft', 'ocr_payload_ciphertext' => null];
    }

    public function updateRealNameOcr(int $verificationId, string $ciphertext): void
    {
        $this->db->execute(
            "UPDATE worker_real_name_verifications
             SET ocr_payload_ciphertext=:payload,ocr_provider='wechat_service_market',ocr_updated_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            ['payload' => $ciphertext, 'id' => $verificationId]
        );
    }

    public function findMerchantForUpdate(int $userId): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM merchant_profiles WHERE user_id=:user_id LIMIT 1 FOR UPDATE',
            ['user_id' => $userId]
        );
        return $row ?: null;
    }

    public function createMerchantDraft(int $userId): array
    {
        $id = $this->db->insert(
            "INSERT INTO merchant_profiles(public_id,user_id,verification_status,recognition_level,created_at,updated_at)
             VALUES(:public_id,:user_id,'draft','consumer',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            ['public_id' => \public_id(), 'user_id' => $userId]
        );
        $row = $this->db->fetch('SELECT * FROM merchant_profiles WHERE id=:id LIMIT 1 FOR UPDATE', ['id' => $id]);
        if (!$row) throw new RuntimeException('无法建立商家认证档案。');
        return $row;
    }

    public function updateMerchantOcr(int $merchantId, array $fields): void
    {
        $this->db->execute(
            "UPDATE merchant_profiles
             SET business_name=:name,business_address=:address,legal_representative=:legal,business_scope=:scope,
                 credit_code_masked=:credit_masked,credit_code_ciphertext=:credit_cipher,credit_code_hmac=:credit_hmac,
                 ocr_payload_ciphertext=:payload,ocr_provider='wechat_service_market',ocr_updated_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'id' => $merchantId,
                'name' => (string)($fields['name'] ?? ''),
                'address' => (string)($fields['address'] ?? ''),
                'legal' => (string)($fields['legal'] ?? ''),
                'scope' => (string)($fields['scope'] ?? ''),
                'credit_masked' => (string)($fields['credit_masked'] ?? ''),
                'credit_cipher' => $fields['credit_cipher'] ?? null,
                'credit_hmac' => $fields['credit_hmac'] ?? null,
                'payload' => (string)($fields['payload'] ?? ''),
            ]
        );
    }

    public function appendUsage(array $entry): bool
    {
        return $this->db->execute(
            "INSERT IGNORE INTO ocr_usage_logs(client_msg_id,user_id,worker_id,scene,ocr_type,status,quota_day,result_digest,created_at)
             VALUES(:client_msg_id,:user_id,:worker_id,:scene,:ocr_type,'success',:quota_day,:digest,UTC_TIMESTAMP())",
            [
                'client_msg_id' => (string)$entry['client_msg_id'],
                'user_id' => (int)$entry['user_id'],
                'worker_id' => $entry['worker_id'] === null ? null : (int)$entry['worker_id'],
                'scene' => (string)$entry['scene'],
                'ocr_type' => (int)$entry['ocr_type'],
                'quota_day' => (string)$entry['quota_day'],
                'digest' => (string)$entry['result_digest'],
            ]
        ) === 1;
    }
}

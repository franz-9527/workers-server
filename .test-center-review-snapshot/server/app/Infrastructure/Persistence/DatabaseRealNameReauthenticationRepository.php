<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\RealNameReauthenticationRepository;
use WorkerAdmin\Core\Database;

final class DatabaseRealNameReauthenticationRepository implements RealNameReauthenticationRepository
{
    private Database $db;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, PrivateValueCipher $cipher)
    {
        $this->db = $db;
        $this->cipher = $cipher;
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
        return [
            'session_id' => (int)$row['session_id'],
            'user_id' => (int)$row['user_id'],
            'worker_id' => $row['worker_id'] === null ? null : (int)$row['worker_id'],
        ];
    }

    public function touchSession(int $sessionId): void
    {
        $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id', ['id' => $sessionId]);
    }

    public function policy(int $workerId): array
    {
        $row = $this->db->fetch(
            'SELECT status,ocr_payload_ciphertext FROM worker_real_name_verifications WHERE worker_id=:worker_id LIMIT 1',
            ['worker_id' => $workerId]
        );
        if (!$row) return ['status' => 'none', 'self_reauth_used' => 0];
        return [
            'status' => (string)$row['status'],
            'self_reauth_used' => $this->selfReauthUsed($this->decodePayload((string)($row['ocr_payload_ciphertext'] ?? ''))),
        ];
    }

    public function restartApproved(int $workerId, int $limit): array
    {
        return $this->db->transaction(function (Database $db) use ($workerId, $limit): array {
            $verification = $this->currentForUpdate($db, $workerId);
            if (!$verification) throw new RuntimeException('当前没有可重新认证的实名资料', 409);
            if ((string)$verification['status'] === 'pending') throw new RuntimeException('实名认证正在审核中，暂不能重新提交', 409);
            if ((string)$verification['status'] !== 'approved') throw new RuntimeException('当前状态可直接修改并重新提交，无需重新认证', 409);

            $payload = $this->decodePayload((string)($verification['ocr_payload_ciphertext'] ?? ''));
            $used = $this->selfReauthUsed($payload);
            if ($used >= $limit) throw new RuntimeException('已达到自主重新认证次数，请联系管理员取消认证后再重新提交', 409);

            $payload = $this->appendHistory($db, $verification, $payload, 'user_reauth', null, '用户自主重新认证');
            $this->resetCurrent($db, (int)$verification['id'], $payload, 'draft', null, null);
            return ['status' => 'draft', 'self_reauth_used' => $used + 1, 'self_reauth_limit' => $limit];
        });
    }

    public function revokeByAdmin(int $workerId, int $adminId, string $reason): array
    {
        return $this->db->transaction(function (Database $db) use ($workerId, $adminId, $reason): array {
            $verification = $this->currentForUpdate($db, $workerId);
            if (!$verification || (string)$verification['status'] === 'revoked') {
                throw new RuntimeException('该师傅没有可取消的实名认证', 409);
            }
            $payload = $this->decodePayload((string)($verification['ocr_payload_ciphertext'] ?? ''));
            $payload = $this->appendHistory($db, $verification, $payload, 'admin_revoke', $adminId, $reason);
            $this->resetCurrent($db, (int)$verification['id'], $payload, 'revoked', $reason, $adminId);
            return ['status' => 'revoked'];
        });
    }

    private function currentForUpdate(Database $db, int $workerId): ?array
    {
        $row = $db->fetch(
            "SELECT id,worker_id,status,real_name_masked,real_name_ciphertext,id_number_masked,id_number_ciphertext,id_number_hmac,
                    ocr_payload_ciphertext,ocr_provider,ocr_updated_at,review_reason,reviewed_by,submitted_at,reviewed_at
             FROM worker_real_name_verifications WHERE worker_id=:worker_id LIMIT 1 FOR UPDATE",
            ['worker_id' => $workerId]
        );
        return $row ?: null;
    }

    /** @param array<string,mixed> $verification @param array<string,mixed> $payload @return array<string,mixed> */
    private function appendHistory(Database $db, array $verification, array $payload, string $source, ?int $adminId, string $note): array
    {
        $history = is_array($payload['history'] ?? null) ? array_values($payload['history']) : [];
        $sequence = 1;
        foreach ($history as $item) $sequence = max($sequence, (int)($item['sequence'] ?? 0) + 1);

        $currentOcr = $payload;
        unset($currentOcr['history']);
        $history[] = [
            'sequence' => $sequence,
            'source' => $source,
            'snapshot_at' => gmdate('c'),
            'status' => (string)($verification['status'] ?? ''),
            'real_name' => $this->decrypt((string)($verification['real_name_ciphertext'] ?? ''), 'worker-real-name'),
            'id_number' => $this->decrypt((string)($verification['id_number_ciphertext'] ?? ''), 'worker-id-number'),
            'real_name_masked' => (string)($verification['real_name_masked'] ?? ''),
            'id_number_masked' => (string)($verification['id_number_masked'] ?? ''),
            'review_reason' => (string)($verification['review_reason'] ?? ''),
            'reviewed_by' => $verification['reviewed_by'] === null ? null : (int)$verification['reviewed_by'],
            'submitted_at' => $verification['submitted_at'] ?? null,
            'reviewed_at' => $verification['reviewed_at'] ?? null,
            'ocr_provider' => (string)($verification['ocr_provider'] ?? ''),
            'ocr_updated_at' => $verification['ocr_updated_at'] ?? null,
            'ocr' => $currentOcr,
            'admin_id' => $adminId,
            'note' => $note,
        ];

        // 旧照片直接转为历史用途，不复制文件，也不减少 reference_count。
        $db->execute(
            "UPDATE media_links
             SET purpose=CASE purpose
                 WHEN 'id_front' THEN :front
                 WHEN 'id_back' THEN :back
                 ELSE purpose END
             WHERE entity_type='worker_verification' AND entity_id=:id AND purpose IN ('id_front','id_back')",
            [
                'front' => 'history_' . $sequence . '_id_front',
                'back' => 'history_' . $sequence . '_id_back',
                'id' => $verification['id'],
            ]
        );

        return ['history' => $history];
    }

    /** @param array<string,mixed> $payload */
    private function resetCurrent(Database $db, int $verificationId, array $payload, string $status, ?string $reason, ?int $adminId): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 2000000) throw new RuntimeException('历史认证资料过大，请联系管理员处理', 409);
        $encrypted = $this->cipher->encrypt($json, 'worker-realname-ocr');
        if (!is_string($encrypted) || $encrypted === '') throw new RuntimeException('历史认证资料保存失败', 500);

        $db->execute(
            "UPDATE worker_real_name_verifications
             SET real_name_masked='',real_name_ciphertext=NULL,
                 id_number_masked='',id_number_ciphertext=NULL,id_number_hmac=NULL,
                 ocr_payload_ciphertext=:payload,ocr_provider='',ocr_updated_at=NULL,
                 status=:status,review_reason=:reason,reviewed_by=:admin,
                 submitted_at=NULL,reviewed_at=CASE WHEN :is_revoked=1 THEN UTC_TIMESTAMP() ELSE NULL END,
                 updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'payload' => $encrypted,
                'status' => $status,
                'reason' => $reason,
                'admin' => $adminId,
                'is_revoked' => $status === 'revoked' ? 1 : 0,
                'id' => $verificationId,
            ]
        );
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $ciphertext): array
    {
        if ($ciphertext === '') return [];
        try {
            $decoded = json_decode($this->cipher->decrypt($ciphertext, 'worker-realname-ocr'), true);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $exception) {
            return [];
        }
    }

    /** @param array<string,mixed> $payload */
    private function selfReauthUsed(array $payload): int
    {
        $history = is_array($payload['history'] ?? null) ? $payload['history'] : [];
        $count = 0;
        foreach ($history as $item) if (is_array($item) && (string)($item['source'] ?? '') === 'user_reauth') $count++;
        return $count;
    }

    private function decrypt(string $ciphertext, string $context): string
    {
        if ($ciphertext === '') return '';
        try { return $this->cipher->decrypt($ciphertext, $context); }
        catch (Throwable $exception) { return ''; }
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\RealNameSubmissionRepository;
use WorkerAdmin\Contracts\SettingsProvider;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Application\Identity\ApiActorResolver;

final class DatabaseRealNameSubmissionRepository implements RealNameSubmissionRepository
{
    private Database $db;
    private ApiActorResolver $actorResolver;
    private PrivateValueCipher $cipher;
    private SettingsProvider $settings;

    public function __construct(Database $db, PrivateValueCipher $cipher, ApiActorResolver $actorResolver, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->actorResolver = $actorResolver;
        $this->cipher = $cipher;
        $this->settings = $settings;
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
            "SELECT id,status,real_name_ciphertext,id_number_ciphertext,ocr_payload_ciphertext
             FROM worker_real_name_verifications
             WHERE worker_id=:worker_id LIMIT 1 FOR UPDATE",
            ['worker_id' => $workerId]
        );
        if (!$row) return null;
        $payload = json_decode($this->decrypt((string)($row['ocr_payload_ciphertext'] ?? ''), 'worker-realname-ocr'), true);
        $row['has_reauth_draft'] = is_array($payload['reauth_draft'] ?? null) && !empty($payload['reauth_draft']);
        unset($row['ocr_payload_ciphertext']);
        $row['real_name'] = $this->decrypt((string)($row['real_name_ciphertext'] ?? ''), 'worker-real-name');
        $row['id_number'] = $this->decrypt((string)($row['id_number_ciphertext'] ?? ''), 'worker-id-number');
        unset($row['real_name_ciphertext'], $row['id_number_ciphertext']);
        return $row;
    }

    public function hasCurrentIdMedia(int $verificationId): bool
    {
        $verification = $this->db->fetch('SELECT status FROM worker_real_name_verifications WHERE id=:id', ['id' => $verificationId]);
        $prefix = ($verification['status'] ?? '') === 'approved' ? 'draft_' : '';
        $rows = $this->db->fetchAll(
            "SELECT purpose FROM media_links
             WHERE entity_type='worker_verification' AND entity_id=:id AND purpose IN (:front,:back)",
            ['id' => $verificationId, 'front' => $prefix . 'id_front', 'back' => $prefix . 'id_back']
        );
        $purposes = array_column($rows, 'purpose');
        return in_array($prefix . 'id_front', $purposes, true) && in_array($prefix . 'id_back', $purposes, true);
    }

    public function saveDraftFields(int $workerId, string $realName, string $idNumber): void
    {
        $verification = $this->db->fetch('SELECT id,status,ocr_payload_ciphertext FROM worker_real_name_verifications WHERE worker_id=:worker_id LIMIT 1 FOR UPDATE', ['worker_id' => $workerId]);
        if (!$verification) throw new RuntimeException('请先上传身份证资料', 422);
        if ($verification['status'] === 'pending') throw new RuntimeException('实名认证正在审核中，暂不能修改资料', 409);
        $payload = [];
        if (!empty($verification['ocr_payload_ciphertext'])) {
            $payload = json_decode($this->cipher->decrypt((string)$verification['ocr_payload_ciphertext'], 'worker-realname-ocr'), true);
            if (!is_array($payload)) throw new RuntimeException('认证资料无法读取，请联系管理员处理', 500);
        }
        $fields = ['real_name' => $realName, 'id_number' => $idNumber];
        if ($verification['status'] === 'approved') {
            $policy = (new DatabaseRealNameReauthenticationRepository($this->db, $this->cipher))->policy($workerId);
            $limit = max(0, min(10, $this->settings->int('worker.realname_self_reauth_limit', 1)));
            if ($policy['self_reauth_used'] >= $limit) throw new RuntimeException('已达到自主重新认证次数', 409);
            $payload['reauth_draft']['draft_fields'] = $fields;
        } else {
            $payload['draft_fields'] = $fields;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 2000000) throw new RuntimeException('认证资料过大，请联系管理员处理', 422);
        $encrypted = $this->cipher->encrypt($json, 'worker-realname-ocr');
        if (!is_string($encrypted) || $encrypted === '') throw new RuntimeException('认证草稿保存失败', 500);
        $this->db->execute('UPDATE worker_real_name_verifications SET ocr_payload_ciphertext=:payload,updated_at=UTC_TIMESTAMP() WHERE id=:id', ['payload' => $encrypted, 'id' => $verification['id']]);
    }

    public function markPending(int $verificationId, string $realName, string $idNumber): void
    {
        $verification = $this->db->fetch('SELECT worker_id,status FROM worker_real_name_verifications WHERE id=:id FOR UPDATE', ['id' => $verificationId]);
        if (($verification['status'] ?? '') === 'approved') {
            $limit = max(0, min(10, $this->settings->int('worker.realname_self_reauth_limit', 1)));
            (new DatabaseRealNameReauthenticationRepository($this->db, $this->cipher))->submitApprovedDraft((int)$verification['worker_id'], $limit);
        }
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

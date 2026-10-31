<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminWorkerRepository;
use WorkerAdmin\Contracts\IdentityRepository;
use WorkerAdmin\Contracts\PrivateValueCipher;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Services\OrderNotificationService;

final class DatabaseAdminWorkerRepository implements AdminWorkerRepository
{
    private Database $db;
    private IdentityRepository $identity;
    private SettingsProvider $settings;
    private PrivateValueCipher $cipher;

    public function __construct(Database $db, IdentityRepository $identity, SettingsProvider $settings, PrivateValueCipher $cipher)
    {
        $this->db = $db;
        $this->identity = $identity;
        $this->settings = $settings;
        $this->cipher = $cipher;
    }

    public function localEnrichment(string $cloudId, string $openid): array
    {
        $appId = (string)$this->settings->get('wechat.miniapp_appid', '');
        $row = $this->db->fetch(
            "SELECT w.id,w.public_id,u.public_id user_public_id,
                    (SELECT COUNT(*) FROM orders o WHERE o.assigned_worker_id=w.id AND (o.fulfillment_status='accepted' OR o.lifecycle_status='completed')) completed_orders
             FROM worker_profiles w
             JOIN users u ON u.id=w.user_id
             WHERE BINARY w.cloud_worker_id=BINARY :cloud_id
                OR w.user_id=(SELECT wi.user_id FROM wechat_identities wi WHERE BINARY wi.app_id=BINARY :app_id AND BINARY wi.openid=BINARY :openid LIMIT 1)
             ORDER BY (BINARY w.cloud_worker_id=BINARY :cloud_order) DESC,w.id DESC LIMIT 1",
            ['cloud_id'=>$cloudId,'app_id'=>$appId,'openid'=>$openid,'cloud_order'=>$cloudId]
        );
        return $row ?: [];
    }

    public function projectCloudWorker(array $cloudWorker, int $adminId): int
    {
        $cloudId = mb_substr(trim((string)($cloudWorker['cloud_id'] ?? $cloudWorker['_id'] ?? '')), 0, 128);
        $openid = mb_substr(trim((string)($cloudWorker['openid'] ?? $cloudWorker['_openid'] ?? '')), 0, 128);
        if ($cloudId === '' || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $openid) !== 1) return 0;

        $mobile = preg_replace('/\D+/', '', (string)($cloudWorker['mobile'] ?? $cloudWorker['phone'] ?? ''));
        if (preg_match('/^1\d{10}$/', $mobile) !== 1) $mobile = '';
        $appId = (string)$this->settings->get('wechat.miniapp_appid', '');
        $identity = $this->identity->findWechatIdentity($appId, $openid);
        $userId = $identity ? (int)($identity['user_id'] ?? 0) : 0;

        // 保留网页后台原行为：OpenID 尚未建本地身份时，优先按手机号 HMAC 复用已有用户，避免重复账号。
        if ($userId < 1 && $mobile !== '') {
            $userId = (int)$this->db->fetchValue(
                'SELECT id FROM users WHERE mobile_hmac=:hmac LIMIT 1',
                ['hmac'=>$this->cipher->hmac($mobile, 'user-mobile')]
            );
        }
        // 手机号也无法命中时，再复用已有 cloud_worker_id 投影。
        if ($userId < 1) {
            $userId = (int)$this->db->fetchValue(
                'SELECT user_id FROM worker_profiles WHERE BINARY cloud_worker_id=BINARY :cloud_id LIMIT 1',
                ['cloud_id'=>$cloudId]
            );
        }

        if ($userId < 1) {
            $created = $this->identity->createWechatIdentity(
                ['app_id'=>$appId,'openid'=>$openid],
                [
                    'nickname'=>(string)($cloudWorker['workerName'] ?? '微信师傅'),
                    'mobile'=>$mobile,
                ]
            );
            $userId = (int)($created['user_id'] ?? 0);
        } elseif (!$identity) {
            $this->db->execute(
                "INSERT IGNORE INTO wechat_identities(user_id,app_id,openid,status,created_at,updated_at) VALUES(:user_id,:app_id,:openid,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP())",
                ['user_id'=>$userId,'app_id'=>$appId,'openid'=>$openid]
            );
        }
        if ($userId < 1) return 0;

        // 网页后台原投影会把地点名称与详细地址合并保存到本地私有地址；公开云资料仍保持原样。
        $projectionWorker = $cloudWorker;
        $location = is_array($projectionWorker['location'] ?? null) ? $projectionWorker['location'] : [];
        $parts = array_values(array_unique(array_filter([
            trim((string)($location['name'] ?? '')),
            trim((string)($location['address'] ?? '')),
        ], static fn($value): bool => $value !== '')));
        if ($parts) {
            $location['address'] = mb_substr(implode(' ', $parts), 0, 500);
            $projectionWorker['location'] = $location;
        }

        $workerId = $this->identity->projectCloudWorker($userId, $openid, $projectionWorker);
        if ($workerId < 1) return 0;

        $name = mb_substr(trim((string)($cloudWorker['workerName'] ?? '微信师傅')) ?: '微信师傅', 0, 80);
        $status = (string)($cloudWorker['status'] ?? 'pending');
        if (!in_array($status, ['pending','approved','rejected','suspended'], true)) $status = 'pending';

        // IdentityRepository 提供通用投影；这里补网页管理员场景特有的手机号同步、审核人和角色授权来源。
        $params = ['nickname'=>$name,'user_id'=>$userId];
        $sql = 'UPDATE users SET nickname=:nickname,last_synced_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()';
        if ($mobile !== '') {
            $sql .= ',mobile_masked=:masked,mobile_ciphertext=:cipher,mobile_hmac=:hmac';
            $params['masked'] = \mask_private_mobile($mobile);
            $params['cipher'] = $this->cipher->encrypt($mobile, 'user-mobile');
            $params['hmac'] = $this->cipher->hmac($mobile, 'user-mobile');
        }
        $sql .= ' WHERE id=:user_id';
        $this->db->execute($sql, $params);

        $this->db->execute(
            'UPDATE worker_profiles SET invited_only=0,verified_by=:verified_by,updated_at=UTC_TIMESTAMP() WHERE id=:id',
            ['verified_by'=>$status === 'approved' && $adminId > 0 ? $adminId : null,'id'=>$workerId]
        );
        $roleKey = $status === 'approved' ? 'worker' : 'worker_pending';
        $this->db->execute(
            "UPDATE user_roles ur JOIN roles r ON r.id=ur.role_id
             SET ur.source='cloud_projection',ur.granted_by_admin_id=:admin_id
             WHERE ur.user_id=:user_id AND r.role_key=:role_key",
            ['admin_id'=>$adminId > 0 ? $adminId : null,'user_id'=>$userId,'role_key'=>$roleKey]
        );

        return $workerId;
    }

    public function markDeleted(string $cloudId, string $reason): int
    {
        $localId = (int)$this->db->fetchValue(
            'SELECT id FROM worker_profiles WHERE BINARY cloud_worker_id=BINARY :cloud_id LIMIT 1',
            ['cloud_id'=>$cloudId]
        );
        if ($localId < 1) return 0;
        $this->db->transaction(function (Database $db) use ($localId, $reason): void {
            $db->execute(
                "UPDATE worker_profiles SET verification_status='deleted',rejection_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id",
                ['reason'=>$reason,'id'=>$localId]
            );
            $db->execute(
                "DELETE ur FROM user_roles ur
                 JOIN roles r ON r.id=ur.role_id
                 JOIN worker_profiles w ON w.user_id=ur.user_id
                 WHERE w.id=:worker_id AND r.role_key IN ('worker','worker_pending')",
                ['worker_id'=>$localId]
            );
        });
        return $localId;
    }

    public function recordReview(int $workerId, string $cloudId, string $decision, string $reason, int $adminId, string $workerName): void
    {
        $this->db->insert(
            "INSERT INTO worker_verifications(worker_id,submitted_snapshot_json,decision,decision_reason,reviewed_by,submitted_at,reviewed_at)
             VALUES(:worker_id,:snapshot,:decision,:reason,:admin_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())",
            [
                'worker_id'=>$workerId,
                'snapshot'=>json_encode(['cloud_worker_id'=>$cloudId,'status'=>$decision], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'decision'=>$decision,
                'reason'=>$reason,
                'admin_id'=>$adminId > 0 ? $adminId : null,
            ]
        );
        OrderNotificationService::queueWorkerReviewResult($this->db, $workerId, $cloudId, $decision, $reason, $workerName);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\ApiActorRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\SettingsProvider;

final class DatabaseApiActorRepository implements ApiActorRepository
{
    private Database $db;
    private SettingsProvider $settings;

    public function __construct(Database $db, SettingsProvider $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function findByTokenHash(string $tokenHash, string $miniappAppId): ?array
    {
        return $this->db->fetch(
            "SELECT s.id session_id,s.demo_actor_user_id,s.test_actor_user_id,s.test_instance_id,s.test_actor_expires_at,
                    u.id user_id,u.public_id user_public_id,u.nickname,u.avatar_url,u.mobile_masked,
                    (SELECT wi.openid FROM wechat_identities wi
                     WHERE wi.user_id=u.id AND wi.status='active' AND BINARY wi.app_id=BINARY :app_id
                     ORDER BY wi.id DESC LIMIT 1) payer_openid,
                    w.id worker_id,w.public_id worker_public_id,w.display_name worker_name,
                    w.verification_status worker_status,w.rejection_reason worker_status_reason,
                    w.invited_only,w.lat worker_lat,w.lng worker_lng,w.city worker_city,w.district worker_district
             FROM login_sessions s
             JOIN users u ON u.id=s.user_id AND u.status='active'
             LEFT JOIN worker_profiles w ON w.user_id=u.id
             WHERE s.token_hash=:hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
             ORDER BY w.id DESC LIMIT 1",
            ['hash' => $tokenHash, 'app_id' => $miniappAppId]
        ) ?: null;
    }

    public function touchSession(int $sessionId): void
    {
        if ($sessionId > 0) {
            $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id', ['id' => $sessionId]);
        }
    }

    public function findDemoActor(int $userId): ?array
    {
        if ($userId < 1) return null;
        $row = $this->db->fetch(
            "SELECT u.id user_id,u.public_id user_public_id,u.nickname,u.avatar_url,u.mobile_masked,
                    w.id worker_id,w.public_id worker_public_id,w.display_name worker_name,
                    w.verification_status worker_status,w.rejection_reason worker_status_reason,
                    w.invited_only,w.lat worker_lat,w.lng worker_lng,w.city worker_city,w.district worker_district
             FROM users u
             LEFT JOIN worker_profiles w ON w.user_id=u.id
             WHERE u.id=:id AND u.status='active'
             ORDER BY w.id DESC LIMIT 1",
            ['id' => $userId]
        );
        if (!$row) return null;
        $role = $this->demoRoleForPublicId((string)$row['user_public_id']);
        if ($role === '') return null;
        $row['demo_role'] = $role;
        return $row;
    }

    private function demoRoleForPublicId(string $publicId): string
    {
        $catalog=(new DatabaseMiniAdminDemoRepository($this->db,$this->settings))->catalog();
        foreach ($catalog as $role=>$definition) {
            if ($definition['public_id'] !== '' && hash_equals((string)$definition['public_id'],$publicId)) {
                return strpos((string)$role,'test_worker_')===0 ? 'worker' : (string)$role;
            }
        }
        return '';
    }

    public function findTestActor(int $userId,int $instanceId): ?array
    {
        if($userId<1||$instanceId<1)return null;
        $row=$this->db->fetch(
            "SELECT u.id user_id,u.public_id user_public_id,u.nickname,u.avatar_url,u.mobile_masked,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.id END worker_id,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.public_id END worker_public_id,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.display_name END worker_name,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.verification_status END worker_status,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.rejection_reason END worker_status_reason,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.invited_only END invited_only,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.lat END worker_lat,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.lng END worker_lng,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.city END worker_city,
                    CASE WHEN o.user_id=u.id THEN NULL ELSE w.district END worker_district,
                    CASE WHEN o.user_id=u.id THEN 'user' ELSE 'worker' END test_role
             FROM test_instances ti
             JOIN orders o ON o.id=ti.order_id
             JOIN users u ON u.id=:user_id AND u.status='active'
             LEFT JOIN test_participants tp
               ON tp.instance_id=ti.id AND tp.user_id=u.id AND tp.actor_type='worker'
             LEFT JOIN worker_profiles w
               ON w.id=tp.worker_id AND w.user_id=u.id AND w.cloud_record_mode='demo_local'
             WHERE ti.id=:instance_id
               AND ti.build_status='built'
               AND ti.archived_at IS NULL
               AND ti.deleted_at IS NULL
               AND o.is_demo=1
               AND BINARY o.public_no=BINARY ti.order_no_snapshot
               AND BINARY o.public_no NOT LIKE BINARY 'BXDEMOHALL%'
               AND (o.user_id=u.id OR w.id IS NOT NULL)
             ORDER BY CASE WHEN o.user_id=u.id THEN 0 ELSE 1 END,tp.id
             LIMIT 1",
            ['user_id'=>$userId,'instance_id'=>$instanceId]
        );
        return$row?:null;
    }

    public function clearDemoActor(int $sessionId): void
    {
        if ($sessionId > 0) {
            $this->db->execute('UPDATE login_sessions SET demo_actor_user_id=NULL WHERE id=:id', ['id' => $sessionId]);
        }
    }

    public function clearTestActor(int $sessionId): void
    {
        if($sessionId>0){
            $this->db->execute(
                'UPDATE login_sessions SET test_actor_user_id=NULL,test_instance_id=NULL,test_actor_expires_at=NULL WHERE id=:id',
                ['id'=>$sessionId]
            );
        }
    }
}

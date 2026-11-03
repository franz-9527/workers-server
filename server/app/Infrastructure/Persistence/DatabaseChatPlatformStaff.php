<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Application\Identity\ActorCapabilityResolver;
use WorkerAdmin\Contracts\SettingsProvider;
use WorkerAdmin\Core\Database;

/** Current real mini-admin facts; no cached setting(), backend admin IDs or tokens. */
final class DatabaseChatPlatformStaff
{
    private Database $db;

    public function __construct(Database $db) { $this->db = $db; }

    /**
     * Current facts for OrderConversationPolicy; never writes memberships.
     * Caller must own the order transaction; all source rows remain shared-locked.
     * Lock order: source rows, per-user authority versions, configuration version.
     */
    public function readLocked(): array
    { return $this->readAuthorityLocked()['platform_staff']; }

    public function readAuthorityLocked(): array
    {
        if (!$this->db->pdo()->inTransaction()) throw new RuntimeException('平台聊天身份必须在订单事务内解析');
        $values = [];
        $rows = $this->db->fetchAll(
            "SELECT setting_key,setting_value,value_type FROM system_settings
             WHERE setting_key IN ('wechat.admin_openids','wechat.miniapp_appid')
             ORDER BY setting_key LOCK IN SHARE MODE"
        );
        foreach ($rows as $row) {
            $value = $row['setting_value'];
            // These identity settings are plaintext. Unknown/encrypted legacy data
            // cannot silently use a request-local cache or an alternate app ID.
            if (!is_string($value) || strlen($value) > 8192 || strncmp($value, 'enc:v1:', 7) === 0) return $this->authority([]);
            if ($row['value_type'] === 'json') {
                $value = json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) return $this->authority([]);
            } elseif ($row['value_type'] !== 'string') return $this->authority([]);
            $values[$row['setting_key']] = $value;
        }
        $settings = new class($values) implements SettingsProvider {
            private array $values;
            public function __construct(array $values) { $this->values = $values; }
            public function get(string $key, $default = null) { return $this->values[$key] ?? $default; }
            public function bool(string $key, bool $default = false): bool { return $default; }
            public function int(string $key, int $default = 0): int { return $default; }
        };
        $capabilities = new ActorCapabilityResolver($settings);
        $configured = $settings->get('wechat.admin_openids', []);
        if (is_string($configured)) {
            $decoded = json_decode($configured, true);
            if (is_array($decoded)) $configured = $decoded;
        }
        if (is_array($configured)) foreach ($configured as $value) if (!is_string($value)) return $this->authority([]);
        if (!is_array($configured) && !is_string($configured)) return $this->authority([]);
        $openids = $capabilities->adminOpenIds();
        $app = $settings->get('wechat.miniapp_appid', '');
        if (!is_string($app) || trim($app) === '' || count($openids) < 1 || count($openids) > 8) return $this->authority([]);
        $params = ['app'=>trim($app)]; $slots = [];
        foreach ($openids as $i=>$openid) { $slots[] = ':openid'.$i; $params['openid'.$i] = $openid; }
        $identities = $this->db->fetchAll(
            "SELECT CAST(u.id AS CHAR) actor_id,u.status,wi.openid payer_openid
             FROM wechat_identities wi JOIN users u ON u.id=wi.user_id AND u.status='active'
             WHERE wi.status='active' AND BINARY wi.app_id=BINARY :app
               AND BINARY wi.openid IN (".implode(',', $slots).")
             ORDER BY u.id,wi.id LIMIT 9 LOCK IN SHARE MODE", $params
        );
        if (count($identities) > 8) return $this->authority([]);
        $staff = [];
        foreach ($identities as $actor) {
            // Explicit current locking read: a correlated MAX subquery can retain
            // an earlier REPEATABLE READ snapshot even inside an outer lock read.
            $current = $this->db->fetch(
                "SELECT openid payer_openid FROM wechat_identities
                 WHERE user_id=:user AND status='active' AND BINARY app_id=BINARY :app
                 ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE",
                ['user'=>$actor['actor_id'],'app'=>trim($app)]
            );
            if (!$current || !$capabilities->isMiniAdmin($current)) continue;
            $staff[(string)$actor['actor_id']] = ['actor_id'=>(string)$actor['actor_id'],'kind'=>'mini','status'=>'active','is_mini_admin'=>true,
                'authority_version'=>$this->versionLocked((string)$actor['actor_id'])];
        }
        return $this->authority(array_values($staff));
    }
    private function authority(array $staff): array
    {
        return ['platform_staff'=>$staff,'staff_config_version'=>$this->versionLocked('0')];
    }

    private function versionLocked(string $user): string
    {
        $version=$this->db->fetchValue('SELECT CAST(authority_version AS CHAR) FROM chat_staff_authority_versions WHERE user_id=:id LOCK IN SHARE MODE',['id'=>$user]);
        if (!is_string($version) || preg_match('/^[1-9][0-9]{0,19}$/D',$version)!==1) throw new RuntimeException('平台聊天权限代次不可用');
        return $version;
    }
}

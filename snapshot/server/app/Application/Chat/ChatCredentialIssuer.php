<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Core\Auth;

/** Only exchanges an existing PHP identity; never creates a login or touches last_seen. */
final class ChatCredentialIssuer
{
    private Database $db;
    private array $config;
    private string $environment;

    public function __construct(Database $db, array $config, string $phpEnvironment)
    {
        $this->db = $db;
        $this->config = $config;
        $this->environment = ['testing'=>'test','development'=>'development','production'=>'production'][$phpEnvironment] ?? '';
    }

    public function exchangeMini(string $token, bool $audit = false): array
    {
        $this->assertEnabled();
        if (preg_match('/^[A-Fa-f0-9]{64}$/D', $token) !== 1) throw new RuntimeException('请重新登录', 401);
        // Match the original persisted login authority. Proxy/test identities are exported only as sanitized offline fixtures; they never borrow this production identity.
        $row = $this->db->fetch(
            "SELECT CAST(s.id AS CHAR) source_id,CAST(s.user_id AS CHAR) user_id,s.token_hash,
                    UNIX_TIMESTAMP(s.expires_at) expires_unix
             FROM login_sessions s JOIN users u ON u.id=s.user_id AND u.status='active'
             WHERE s.token_hash=:token_hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP()
               AND COALESCE(s.demo_actor_user_id,0)=0 AND COALESCE(s.test_actor_user_id,0)=0
               AND COALESCE(s.test_instance_id,0)=0 AND s.test_actor_expires_at IS NULL LIMIT 1",
            ['token_hash'=>hash('sha256', $token)]
        );
        if (!$row) throw new RuntimeException('登录状态已失效，请重新进入', 401);
        $source = $this->id($row['source_id']);
        $actor = $this->id($row['user_id']);
        $hash = (string)$row['token_hash'];
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new RuntimeException('聊天身份暂不可用', 503);
        $auditVersion='';
        if($audit){
            $grant=$this->db->fetch('SELECT authority_version FROM chat_mini_auditors WHERE user_id=:id AND enabled=1',['id'=>$actor]);
            if(!$grant||preg_match('/^[a-f0-9]{64}$/D',(string)$grant['authority_version'])!==1)throw new RuntimeException('当前账号无会话抽检权限',403);
            $auditVersion=(string)$grant['authority_version'];
        }
        return $this->issue([
            'sub'=>$actor,'kind'=>$audit?'mini_audit':'mini','source_id'=>$source,
            'session_id'=>hash('sha256', "mini\n".$source."\n".$hash),
            'session_version'=>$audit?hash('sha256',"mini-audit-v1\n".$source."\n".$hash."\n".$actor."\n".$auditVersion):hash('sha256', "mini-v1\n".$source."\n".$hash."\n".$actor),
            'scopes'=>[$audit?'chat:audit':'chat:use'],
        ], (int)$row['expires_unix']);
    }

    public function participantsMini(string $token,array $requests): array
    {
        $identity=$this->exchangeMini($token);
        return (new ChatParticipantProfiles($this->db,new \WorkerAdmin\Infrastructure\Security\GlobalPrivateValueCipher()))
            ->read((string)$identity['actor_id'],hash('sha256',$token),$requests);
    }

    private function assertEnabled(): void
    {
        \WorkerAdmin\Infrastructure\Chat\ChatRecoveryGate::assertReady($this->config,$this->environment);
        if (($this->config['enabled'] ?? false) !== true || $this->environment === '') {
            throw new RuntimeException('聊天凭证交换未启用', 503);
        }
    }

    public function exchangeAdmin(Auth $auth): array
    {
        $this->assertEnabled();
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') throw new RuntimeException('请重新登录', 401);
        $admin = $auth->user();
        if (!$admin) throw new RuntimeException('请重新登录', 401);
        if (!empty($admin['must_change_password']) || !$auth->can('audit.view')) throw new RuntimeException('当前账号无聊天审计权限', 403);
        $scopes = ['chat:audit'];
        if ($auth->can('users.manage')) $scopes[] = 'chat:moderate';
        if ($auth->can('chat.images.review')) $scopes[] = 'chat:images';
        if ($auth->can('chat.media.review')) $scopes[] = 'chat:media';
        if ($auth->can('chat.evidence.view')) $scopes[] = 'chat:evidence';
        $scopeJSON = json_encode($scopes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $adminID = $this->id($admin['id']);
        $sessionHash = hash('sha256', "admin\n".$this->environment."\n".session_id());
        $deadline = (int)($_SESSION['_last_activity'] ?? time()) + max(1, (int)\app_config('security.session_lifetime',7200));
        $expiry = min(time()+300, $deadline);
        if ($expiry <= time()) throw new RuntimeException('请重新登录',401);
        $expectedRole = (string)$admin['role'];
        $row = $this->db->transaction(function() use($adminID,$sessionHash,$scopeJSON,$expiry,$expectedRole): array {
            $current = $this->db->fetch("SELECT CAST(id AS CHAR) id,role,password_hash FROM admin_users WHERE id=:id AND status IN ('active','1') AND must_change_password=0 FOR UPDATE",['id'=>$adminID]);
            if (!$current) throw new RuntimeException('请重新登录',401);
            if ((string)$current['role'] !== $expectedRole) throw new RuntimeException('后台权限已变化，请刷新页面',403);
            $authority = hash('sha256', "admin-v1\n".$adminID."\n".(string)$current['role']."\n".(string)$current['password_hash']."\n".$scopeJSON);
            $this->db->execute(
                "INSERT INTO chat_admin_sessions(admin_id,session_hash,session_version,authority_hash,scopes_json,expires_at,created_at)
                 VALUES(:admin_id,:session_hash,:version,:authority,:scopes,:expires,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE session_version=IF(revoked_at IS NOT NULL OR authority_hash<>VALUES(authority_hash),VALUES(session_version),session_version),
                 authority_hash=VALUES(authority_hash),scopes_json=VALUES(scopes_json),expires_at=VALUES(expires_at),revoked_at=NULL",
                ['admin_id'=>$adminID,'session_hash'=>$sessionHash,'version'=>bin2hex(random_bytes(32)),
                 'authority'=>$authority,'scopes'=>$scopeJSON,'expires'=>gmdate('Y-m-d H:i:s',$expiry)]
            );
            $row = $this->db->fetch('SELECT CAST(id AS CHAR) source_id,session_version,revoked_at FROM chat_admin_sessions WHERE session_hash=:hash AND admin_id=:admin_id LIMIT 1', ['hash'=>$sessionHash,'admin_id'=>$adminID]);
            if (!$row || $row['revoked_at'] !== null) throw new RuntimeException('请重新登录',401);
            return $row;
        });
        $_SESSION['chat_admin_session_id'] = $this->id($row['source_id']);
        return $this->issue(['sub'=>$adminID,'kind'=>'admin','source_id'=>$this->id($row['source_id']),
            'session_id'=>$sessionHash,'session_version'=>(string)$row['session_version'],'scopes'=>$scopes],$expiry);
    }

    private function id($value): string
    {
        $id = (string)$value;
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $id) !== 1 || strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0) {
            throw new RuntimeException('聊天身份暂不可用', 503);
        }
        return $id;
    }

    private function issue(array $identity, int $sessionExpiry): array
    {
        $kid = (string)($this->config['key_id'] ?? '');
        $name = (string)($this->config['seed_env'] ?? '');
        if (preg_match('/^[a-z0-9_-]{1,32}$/D', $kid) !== 1 || preg_match('/^CHAT_[A-Z0-9_]{1,60}$/D', $name) !== 1 || !function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new RuntimeException('聊天凭证配置不可用', 503);
        }
        $encoded = getenv($name);
        $seed = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($seed) || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES || base64_encode($seed) !== $encoded) {
            throw new RuntimeException('聊天凭证配置不可用', 503);
        }
        $now = time();
        $expires = min($now + 300, $sessionExpiry);
        if ($expires <= $now) throw new RuntimeException('登录状态已失效，请重新进入', 401);
        $claims = array_merge($identity, [
            'iss'=>'bixuan-php:'.$this->environment,'aud'=>'bixuan-chat','env'=>$this->environment,
            'iat'=>$now,'nbf'=>$now,'exp'=>$expires,'jti'=>bin2hex(random_bytes(32)),
        ]);
        $header = ['alg'=>'EdDSA','typ'=>'JWT','kid'=>$kid];
        $input = $this->base64url(json_encode($header, JSON_THROW_ON_ERROR)).'.'.$this->base64url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $pair = sodium_crypto_sign_seed_keypair($seed);
        sodium_memzero($seed);
        $secret = sodium_crypto_sign_secretkey($pair);
        $signature = sodium_crypto_sign_detached($input, $secret);
        sodium_memzero($secret);
        sodium_memzero($pair);
        return ['access_token'=>$input.'.'.$this->base64url($signature),'token_type'=>'Bearer','actor_id'=>$identity['sub'],'environment'=>$this->environment,
            'expires_at'=>gmdate('Y-m-d\TH:i:s\Z', $expires),'expires_in'=>$expires-$now];
    }

    private function base64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}

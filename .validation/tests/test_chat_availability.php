<?php
declare(strict_types=1);

namespace WorkerAdmin\Contracts {
    interface PrivateValueCipher {}
    interface ChatRecoveryReadiness { public function ensureReady(array $config, string $environment): void; }
}

namespace WorkerAdmin\Core {
    final class Database {
        public bool $grant = true;
        public bool $throwAudit = false;
        public function fetch(string $sql, array $params = []): ?array {
            if (str_contains($sql, 'FROM login_sessions')) {
                return [
                    'user_id' => '7',
                    'demo_actor_user_id' => 0,
                    'test_actor_user_id' => 0,
                    'test_instance_id' => 0,
                    'test_actor_expires_at' => null,
                ];
            }
            if (str_contains($sql, 'FROM chat_mini_auditors')) {
                if ($this->throwAudit) throw new \RuntimeException('table unavailable');
                return $this->grant ? ['1' => 1] : null;
            }
            throw new \RuntimeException('unexpected query: '.$sql);
        }
    }
    class Auth {}
}

namespace WorkerAdmin\Application\Chat {
    use WorkerAdmin\Core\Database;
    use WorkerAdmin\Contracts\PrivateValueCipher;
    use WorkerAdmin\Contracts\ChatRecoveryReadiness;

    final class ChatReleaseSettings {
        public function __construct(Database $db) {}
        public function enabled(): bool { return true; }
    }
    final class ChatSimulation {
        public function __construct(Database $db) {}
        public function identity(string $token) { return false; }
    }
    final class ChatGoRuntimeStatus {
        public function __construct(string $environment) {}
        public function status(): array { return ['ready' => true]; }
        public function callsEnabled(): bool { return false; }
    }
    final class ChatSigningKeyManager {
        public function __construct(...$args) {}
        public function material(array $config): array { return ['seed' => str_repeat("\0", 32), 'kid' => 'test']; }
    }

    require __DIR__.'/../source/server/app/Application/Chat/ChatCredentialIssuer.php';

    final class Cipher implements PrivateValueCipher {}
    final class Recovery implements ChatRecoveryReadiness {
        public function ensureReady(array $config, string $environment): void {}
    }

    function expect(bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
    }

    $db = new Database();
    $issuer = new ChatCredentialIssuer($db, [], 'testing', new Cipher(), new Recovery());
    $allowed = $issuer->availabilityMini(str_repeat('a', 64));
    expect($allowed['enabled'] === true, 'chat must remain enabled');
    expect(($allowed['audit_allowed'] ?? null) === true, 'authorized auditor must be advertised');

    $db->grant = false;
    $denied = $issuer->availabilityMini(str_repeat('a', 64));
    expect(($denied['audit_allowed'] ?? null) === false, 'ordinary user must not be advertised as auditor');

    $db->throwAudit = true;
    $degraded = $issuer->availabilityMini(str_repeat('a', 64));
    expect(($degraded['audit_allowed'] ?? null) === false, 'audit capability lookup failure must degrade closed');
    expect($degraded['enabled'] === true, 'audit capability failure must not disable chat');

    echo "chat availability audit capability checks passed\n";
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Contracts\ActiveSessionGateway;
use WorkerAdmin\Core\Database;

final class DatabaseActiveSessionGateway implements ActiveSessionGateway
{
    private Database $db;

    public function __construct(Database $db) { $this->db = $db; }

    public function assertActive(string $token): void
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $token) !== 1) throw new RuntimeException('登录状态已失效，请重新进入', 401);
        $session = $this->db->fetch(
            "SELECT s.id AS session_id FROM login_sessions s INNER JOIN users u ON u.id=s.user_id WHERE s.token_hash=:token_hash AND s.status='active' AND s.expires_at>UTC_TIMESTAMP() AND u.status='active' LIMIT 1",
            ['token_hash' => hash('sha256', $token)]
        );
        if (!$session) throw new RuntimeException('登录状态已失效，请重新进入', 401);
        $this->db->execute('UPDATE login_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE id=:id', ['id' => (int)$session['session_id']]);
    }
}

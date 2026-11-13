<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminAccountRepository;
use WorkerAdmin\Core\Database;

final class DatabaseAdminAccountRepository implements AdminAccountRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function passwordHash(int $adminId): ?string
    {
        $row = $this->db->fetch('SELECT password_hash FROM admin_users WHERE id=:id LIMIT 1', ['id'=>$adminId]);
        if (!$row) return null;
        $hash = isset($row['password_hash']) ? (string)$row['password_hash'] : '';
        return $hash !== '' ? $hash : null;
    }

    public function replacePassword(int $adminId, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE admin_users SET password_hash=:hash,must_change_password=0,failed_attempts=0,locked_until=NULL,updated_at=NOW() WHERE id=:id',
            ['hash'=>$passwordHash,'id'=>$adminId]
        );
    }
}

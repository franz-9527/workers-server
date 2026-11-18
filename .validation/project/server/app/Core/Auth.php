<?php

namespace WorkerAdmin\Core;

use RuntimeException;

class Auth
{
    private $db;
    private $userLoaded = false;
    private $user;

    private $permissions = [
        'operations' => ['chat.calls.view', 'chat.images.review', 'chat.media.review', 'dashboard.view', 'orders.manage', 'workers.manage', 'users.view', 'users.manage', 'catalog.manage', 'help.manage', 'reviews.manage', 'disputes.manage', 'audit.view'],
        'finance' => ['dashboard.view', 'orders.view', 'payments.manage', 'refunds.manage', 'commissions.manage', 'withdrawals.manage', 'audit.view'],
        'viewer' => ['dashboard.view', 'orders.view', 'users.view'],
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // 2026-08-14：登录包含失败锁定、会话轮换和密码哈希升级。
    public function attempt($username, $password)
    {
        $username = trim((string) $username);
        $user = $this->db->fetch('SELECT * FROM admin_users WHERE username = :username LIMIT 1', ['username' => $username]);
        if (!$user || !in_array((string) $user['status'], ['active', '1'], true)) {
            return false;
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until'] . ' UTC') > time()) {
            throw new RuntimeException('登录失败次数过多，请稍后再试。');
        }

        if (!password_verify((string) $password, $user['password_hash'])) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $max = (int) \app_config('security.login_max_attempts', 5);
            // 2026-08-14：锁定时间按 UTC 写库，与订单及审计时间约定保持一致。
            $lockedUntil = $attempts >= $max ? gmdate('Y-m-d H:i:s', time() + ((int) \app_config('security.login_lock_minutes', 15) * 60)) : null;
            $this->db->execute('UPDATE admin_users SET failed_attempts=:attempts, locked_until=:locked_until, updated_at=NOW() WHERE id=:id', [
                'attempts' => $attempts, 'locked_until' => $lockedUntil, 'id' => $user['id'],
            ]);
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->db->execute('UPDATE admin_users SET password_hash=:hash WHERE id=:id', [
                'hash' => password_hash((string) $password, PASSWORD_BCRYPT, ['cost' => 12]), 'id' => $user['id'],
            ]);
        }

        $this->revokeChatIdentity();
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['admin_login_at'] = time();
        $this->db->execute('UPDATE admin_users SET failed_attempts=0, locked_until=NULL, last_login_at=NOW(), last_login_ip=:ip, updated_at=NOW() WHERE id=:id', [
            'ip' => \request_ip(), 'id' => $user['id'],
        ]);
        $this->userLoaded = false;
        $this->user = null;
        \csrf()->rotate();
        return true;
    }

    public function user()
    {
        if ($this->userLoaded) {
            return $this->user;
        }
        $this->userLoaded = true;
        if (empty($_SESSION['admin_id'])) {
            return $this->user = null;
        }
        // 2026-08-14：管理员表不保存手机号，避免查询不存在字段导致登录后页面报错。
        $this->user = $this->db->fetch('SELECT id, username, real_name, role, status, must_change_password, last_login_at, last_login_ip, created_at FROM admin_users WHERE id=:id LIMIT 1', ['id' => (int) $_SESSION['admin_id']]);
        if (!$this->user || !in_array((string) $this->user['status'], ['active', '1'], true)) {
            $this->logout();
            return null;
        }
        return $this->user;
    }

    public function check()
    {
        return $this->user() !== null;
    }

    public function logout()
    {
        $this->revokeChatIdentity();
        $this->user = null;
        $this->userLoaded = true;
        unset($_SESSION['admin_id'], $_SESSION['admin_login_at']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function revokeChatIdentity(): void
    {
        if (empty($_SESSION['chat_admin_session_id'])) return;
        try {
            $this->db->execute('UPDATE chat_admin_sessions SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()) WHERE id=:id AND admin_id=:admin_id',
                ['id'=>(string)$_SESSION['chat_admin_session_id'],'admin_id'=>(string)($_SESSION['admin_id'] ?? '0')]);
        } catch (\Throwable $error) {
            // Don't report successful logout if persistent chat revocation failed.
            throw new RuntimeException('聊天会话暂时无法撤销，请稍后再试。');
        }
        unset($_SESSION['chat_admin_session_id']);
    }

    public function can($permission)
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }
        if ($user['role'] === 'super_admin') {
            return true;
        }
        $grants = isset($this->permissions[$user['role']]) ? $this->permissions[$user['role']] : [];
        if (in_array($permission, $grants, true)) {
            return true;
        }
        if (substr($permission, -5) === '.view') {
            return in_array(str_replace('.view', '.manage', $permission), $grants, true);
        }
        return false;
    }

    public function requireLogin()
    {
        if (!$this->check()) {
            \redirect(\admin_url('login'));
        }
    }

    public function requirePermission($permission)
    {
        $this->requireLogin();
        if (!$this->can($permission)) {
            http_response_code(403);
            echo \view('errors/403', ['pageTitle' => '无权访问']);
            exit;
        }
    }
}

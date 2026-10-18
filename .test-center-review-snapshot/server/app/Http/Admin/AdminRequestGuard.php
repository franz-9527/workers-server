<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin;

use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;

final class AdminRequestGuard
{
    private Auth $auth;
    private Csrf $csrf;

    public function __construct(Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
    }

    public function inspect(string $route): void
    {
        if ($route === 'login') return;
        $this->auth->requireLogin();
        $user = $this->auth->user();
        if (!empty($user['must_change_password']) && !in_array($route, ['profile','profile.password','logout'], true)) {
            \flash('warning', '首次登录必须先修改演示密码。');
            \redirect(\admin_url('profile'));
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $this->csrf->verifyRequest();
        }
    }
}

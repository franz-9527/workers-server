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
        if ($route === 'chat.credentials') {
            $status = 0;
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                header('Allow: POST'); $status = 405;
            } elseif (!$this->auth->check()) { $status = 401;
            } elseif (!empty($this->auth->user()['must_change_password'])) { $status = 403;
            } else {
                try { $this->csrf->verifyRequest(); } catch (\RuntimeException $error) { $status = 403; }
            }
            if ($status) \json_response(['ok'=>false,'code'=>$status===401?'CHAT_UNAUTHORIZED':($status===405?'CHAT_METHOD_NOT_ALLOWED':'CHAT_FORBIDDEN'),
                'message'=>'请刷新页面后重试','retryable'=>false,'request_id'=>\request_id(),'server_time'=>gmdate('Y-m-d\TH:i:s\Z')],$status);
            return;
        }
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


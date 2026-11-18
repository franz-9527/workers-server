<?php

namespace WorkerAdmin\Core;

use RuntimeException;

class Csrf
{
    // 2026-08-14：所有后台写操作统一使用会话 CSRF 令牌。
    public function token()
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public function input()
    {
        return '<input type="hidden" name="_token" value="' . \e($this->token()) . '">';
    }

    public function validate($token)
    {
        return is_string($token) && hash_equals($this->token(), $token);
    }

    public function verifyRequest()
    {
        $token = isset($_POST['_token']) ? $_POST['_token'] : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
        if (!$this->validate($token)) {
            throw new RuntimeException('页面已过期，请刷新后重试。');
        }
    }

    public function rotate()
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }
}

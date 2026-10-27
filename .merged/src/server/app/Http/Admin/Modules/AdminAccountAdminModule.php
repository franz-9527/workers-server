<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Admin\AdminAccountSecurity;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminAccountComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class AdminAccountAdminModule implements AdminModule
{
    private const ROUTES = ['login','logout','profile','profile.password'];

    private Auth $auth;
    private Csrf $csrf;
    private AdminAccountSecurity $security;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->security = AdminAccountComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        if ($route === 'login') {
            $this->login();
            return;
        }

        $expected = $route === 'profile' ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 '.$expected.' 请求。');
        }

        try {
            if ($route === 'logout') {
                $this->logout();
                return;
            }
            if ($route === 'profile') {
                $this->profile();
                return;
            }
            $this->changePassword();
        } catch (RuntimeException $exception) {
            if ($route === 'profile.password') {
                \flash('danger', $exception->getMessage());
                \redirect(\admin_url('profile'));
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-account-error', '['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception->getMessage()."\n");
            if ($expected === 'POST') {
                \flash('danger', '账号操作失败，请稍后重试。请求编号：'.$requestId);
                \redirect(\admin_url('profile'));
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'账号信息读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function login(): void
    {
        if ($this->auth->check()) {
            \redirect(\admin_url('dashboard'));
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET','POST'], true)) {
            http_response_code(405);
            throw new RuntimeException('登录只接受 GET 或 POST 请求。');
        }

        $error = null;
        if ($method === 'POST') {
            try {
                // AdminRequestGuard 对未认证 login 主动放行，因此登录 POST 在本模块自行校验 CSRF。
                $this->csrf->verifyRequest();
                $username = isset($_POST['username']) && !is_array($_POST['username']) ? trim((string)$_POST['username']) : '';
                $password = isset($_POST['password']) && !is_array($_POST['password']) ? (string)$_POST['password'] : '';
                if ($username === '' || $password === '') throw new RuntimeException('请输入账号和密码。');
                if (!$this->auth->attempt($username, $password)) throw new RuntimeException('账号或密码错误。');
                $admin = $this->auth->user();
                \audit('admin.login', 'admin_user', $admin ? (int)$admin['id'] : null, '管理员登录后台');
                \redirect(\admin_url('dashboard'));
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                $requestId = \request_id();
                \write_log('security', '['.date('c').'] login request_id='.$requestId.' '.$exception->getMessage()."\n");
                $error = '系统暂时无法完成登录，请稍后重试。请求编号：'.$requestId;
            }
        }

        echo \view('auth/login', ['pageTitle'=>'后台登录','error'=>$error], null);
    }

    private function logout(): void
    {
        $admin = $this->auth->user();
        \audit('admin.logout', 'admin_user', $admin ? (int)$admin['id'] : null, '管理员退出后台');
        $this->auth->logout();
        \redirect(\admin_url('login'));
    }

    private function profile(): void
    {
        echo \view('admin/profile', [
            'pageTitle'=>'账号安全',
            'activeMenu'=>'profile',
            'admin'=>$this->auth->user(),
        ]);
    }

    private function changePassword(): void
    {
        $admin = $this->auth->user();
        if (!$admin) throw new RuntimeException('管理员登录状态已失效。');
        $current = $this->post('current_password');
        $password = $this->post('password');
        $confirmation = $this->post('password_confirmation');
        $this->security->changePassword((int)$admin['id'], $current, $password, $confirmation);
        \audit('admin.password_change', 'admin_user', (int)$admin['id'], '管理员修改密码');
        \flash('success', '密码已修改。');
        \redirect(\admin_url('profile'));
    }

    private function post(string $key): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? (string)$_POST[$key] : '';
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Admin\UserMerchantAdmin;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\UserMerchantAdminComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class UserMerchantAdminModule implements AdminModule
{
    private const ROUTES = [
        'users','users.membership.grant','users.membership.revoke','merchants','merchants.review',
    ];

    private Auth $auth;
    private UserMerchantAdmin $application;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->application = UserMerchantAdminComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, self::ROUTES, true);
    }

    public function dispatch(string $route): void
    {
        $expected = in_array($route, ['users','merchants'], true) ? 'GET' : 'POST';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 '.$expected.' 请求。');
        }

        try {
            switch ($route) {
                case 'users': $this->users(); return;
                case 'users.membership.grant': $this->grantMembership(); return;
                case 'users.membership.revoke': $this->revokeMembership(); return;
                case 'merchants': $this->merchants(); return;
                case 'merchants.review': $this->reviewMerchant(); return;
            }
            throw new RuntimeException('用户商家后台路由不存在。');
        } catch (RuntimeException $exception) {
            if ($expected === 'POST') {
                \flash('danger', $exception->getMessage());
                \redirect(in_array($route, ['merchants.review'], true) ? \admin_url('merchants') : \admin_url('users'));
            }
            throw $exception;
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-user-merchant-error', '['.date('c').'] request_id='.$requestId.' route='.$route.' '.$exception->getMessage()."\n");
            if ($expected === 'POST') {
                \flash('danger', '用户/商家操作失败，请稍后重试。请求编号：'.$requestId);
                \redirect(in_array($route, ['merchants.review'], true) ? \admin_url('merchants') : \admin_url('users'));
            }
            http_response_code(500);
            echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'用户/商家数据读取失败，请稍后重试。请求编号：'.$requestId]);
        }
    }

    private function users(): void
    {
        $this->auth->requirePermission('users.view');
        $search = $this->query('q');
        $riskFilter = $this->query('risk');
        $page = max(1, (int)$this->query('page', '1'));
        $result = $this->application->users($search, $riskFilter, $page);
        echo \view('admin/users', [
            'pageTitle'=>'用户管理','activeMenu'=>'users','result'=>$result,'search'=>$search,'riskFilter'=>$riskFilter,
            'canManage'=>$this->auth->can('users.manage'),
        ]);
    }

    private function grantMembership(): void
    {
        $this->auth->requirePermission('users.manage');
        $admin = $this->auth->user();
        $userId = $this->id();
        $result = $this->application->grantMembership($userId, $this->post('activation_code'), (int)($admin['id'] ?? 0));
        \audit('user.membership.grant', 'user_membership_grant', (int)$result['grant_id'], '后台使用激活码开通/重新校验会员；用户 '.$result['user_public_id']);
        \flash('success', '会员已通过服务器 + 云函数双重校验并开通，有效至 '.$result['end'].'。');
        \redirect(\admin_url('users'));
    }

    private function revokeMembership(): void
    {
        $this->auth->requirePermission('users.manage');
        $admin = $this->auth->user();
        $userId = $this->id();
        $result = $this->application->revokeMembership($userId, (int)($admin['id'] ?? 0));
        \audit('user.membership.revoke', 'user', $userId, '撤销会员；激活码仍保持已使用，不恢复可用状态');
        \flash('success', (int)$result['changed'] > 0 ? '会员已撤销。' : '云端会员状态已撤销，本地没有有效会员授权。');
        \redirect(\admin_url('users'));
    }

    private function merchants(): void
    {
        $this->auth->requirePermission('users.view');
        $search = $this->query('q');
        $status = $this->query('status');
        $page = max(1, (int)$this->query('page', '1'));
        $data = $this->application->merchants($search, $status, $page);
        $stats = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : [];
        unset($data['stats']);
        echo \view('admin/merchants', [
            'pageTitle'=>'商家管理','activeMenu'=>'merchants','result'=>$data,'search'=>$search,'status'=>$status,
            'merchantStats'=>$stats,'canManage'=>$this->auth->can('workers.manage'),
        ]);
    }

    private function reviewMerchant(): void
    {
        $this->auth->requirePermission('workers.manage');
        $admin = $this->auth->user();
        $id = $this->id();
        $decision = $this->post('decision');
        $reason = mb_substr($this->post('reason'), 0, 500);
        $this->application->reviewMerchant($id, $decision, $reason, (int)($admin['id'] ?? 0));
        \audit('merchant.review', 'merchant_profile', $id, '商家认证：'.$decision.'；'.$reason);
        \flash('success', '商家认证状态已更新。');
        \redirect(\admin_url('merchants'));
    }

    private function query(string $key, string $default = ''): string
    {
        return isset($_GET[$key]) && !is_array($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    private function post(string $key, string $default = ''): string
    {
        return isset($_POST[$key]) && !is_array($_POST[$key]) ? (string)$_POST[$key] : $default;
    }

    private function id(): int
    {
        $raw = $_GET['id'] ?? $_POST['id'] ?? 0;
        return is_array($raw) ? 0 : max(0, (int)$raw);
    }
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\AdminWorkbenchReadComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/**
 * Web 后台异常工作台。
 *
 * 只消费统一异常读模型；正常审核、派单、核价等运营动作不在首页生成待办。
 */
final class ExceptionDashboardAdminModule implements AdminModule
{
    private const SCOPES = ['exception','publish','refund_dispute','finance','income_verification','privacy'];

    private Auth $auth;
    private AdminWorkbenchReadComposition $composition;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->composition = new AdminWorkbenchReadComposition($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'dashboard';
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('dashboard.view');

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
        }

        $scope = trim((string)($_GET['scope'] ?? 'exception'));
        if ($scope === '') $scope = 'exception';
        if (!in_array($scope, self::SCOPES, true)) {
            throw new OrderOperationException('异常工作台筛选不存在', 'ADMIN_WORKBENCH_SCOPE_NOT_ALLOWED', 422);
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $keyword = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80, 'UTF-8'));
        $query = $this->composition->query();

        echo \view('admin/exception-dashboard', [
            'pageTitle'=>'异常工作台',
            'activeMenu'=>'dashboard',
            'counts'=>$query->summary(),
            'result'=>$query->list($scope, $page, 20, $keyword),
            'scope'=>$scope,
            'search'=>$keyword,
            'demoDataEnabled'=>function_exists('setting') ? (bool)\setting('demo.data_enabled', false) : false,
        ]);
    }
}

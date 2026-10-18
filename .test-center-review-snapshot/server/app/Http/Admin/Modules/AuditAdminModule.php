<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Application\Admin\AdminOversightQuery;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminOversightComposition;
use WorkerAdmin\Http\Admin\AdminModule;

final class AuditAdminModule implements AdminModule
{
    private Auth $auth;
    private AdminOversightQuery $query;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->query = AdminOversightComposition::build($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'audit';
    }

    public function dispatch(string $route): void
    {
        $this->auth->requirePermission('audit.view');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            http_response_code(405);
            throw new RuntimeException('该操作只接受 GET 请求。');
        }

        try {
            $keyword = isset($_GET['q']) && !is_array($_GET['q']) ? trim((string)$_GET['q']) : '';
            $result = $this->query->audit(
                max(1, (int)($_GET['page'] ?? 1)),
                $keyword,
                (bool)\setting('demo.data_enabled', true)
            );
            echo \view('admin/audit', [
                'pageTitle'=>'操作审计',
                'activeMenu'=>'audit',
                'result'=>$result,
                'q'=>$keyword,
            ]);
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('admin-audit-error', '['.date('c').'] request_id='.$requestId.' '.$exception->getMessage()."\n");
            http_response_code(500);
            echo \view('errors/500', [
                'pageTitle'=>'系统处理失败',
                'message'=>'操作审计读取失败，请稍后重试。请求编号：'.$requestId,
            ]);
        }
    }
}

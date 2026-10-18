<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Api\Modules;

use WorkerAdmin\Application\Admin\AdminWorkbenchQuery;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Foundation\AdminWorkbenchOperationsComposition;
use WorkerAdmin\Foundation\AdminWorkbenchReadComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Api\ApiModule;

/** 管理员订单工作台读模型 API。 */
final class AdminWorkbenchApiModule implements ApiModule
{
    use OrderApiModuleSupport;
    use AdminWorkbenchModuleSupport;

    private const SCOPES = [
        'todo','all','operation','exception','audit','assignment','pricing','refund_after_sale','settlement',
        'publish','refund_dispute','finance','income_verification','privacy','measurement','after_sale','attention','followup'
    ];

    private OrderApplicationContainer $container;
    private AdminWorkbenchQuery $query;
    private AdminWorkbenchOperationsComposition $operations;
    private AdminWorkbenchReadComposition $read;

    public function __construct(Database $db)
    {
        $this->container = new OrderApplicationContainer($db);
        $this->read = new AdminWorkbenchReadComposition($db);
        $this->query = $this->read->query();
        $this->operations = new AdminWorkbenchOperationsComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['admin.dashboard','admin.orders','admin.workbench.order','admin.workbench.workers'], true);
    }

    public function requiresContentSecurity(string $route): bool
    {
        return false;
    }

    public function dispatch(string $route): void
    {
        $this->orderRequireMethod('GET');
        $actor = $this->orderActor($this->container);
        $this->requireWorkbenchAdmin($this->container, $actor);

        if ($route === 'admin.dashboard') {
            \json_response(['ok'=>true,'data'=>[
                'counts'=>$this->query->summary(),
                'admin_url'=>\app_url('admin/'),
            ]]);
            return;
        }

        if ($route === 'admin.workbench.workers') {
            $publicNo = trim((string)($_GET['id'] ?? ''));
            if ($publicNo === '') $this->orderFail('缺少订单编号', 'ORDER_ID_REQUIRED', 422);
            $keyword = trim((string)($_GET['q'] ?? ''));
            $items = $this->operations->repository()->workerOptions($publicNo, $keyword, 30);
            \json_response(['ok'=>true,'data'=>['items'=>$items]]);
            return;
        }

        if ($route === 'admin.workbench.order') {
            $publicNo = trim((string)($_GET['id'] ?? ''));
            if ($publicNo === '') $this->orderFail('缺少订单编号', 'ORDER_ID_REQUIRED', 422);
            $projection = $this->query->orderProjection($publicNo);
            if ($projection === null) $this->orderFail('订单不存在', 'ORDER_NOT_FOUND', 404);
            $projection['internal_followups'] = $this->operations->repository()->internalFollowups($publicNo, 20);
            $projection['admin_capabilities'] = $this->read->capabilitiesForOrder($publicNo);
            \json_response(['ok'=>true,'data'=>$projection]);
            return;
        }

        $scope = trim((string)($_GET['scope'] ?? 'todo'));
        if ($scope === '') $scope = 'todo';
        if (!in_array($scope, self::SCOPES, true)) {
            $this->orderFail('管理工作台筛选不存在', 'ADMIN_WORKBENCH_SCOPE_NOT_ALLOWED', 422);
        }

        $page = (int)($_GET['page'] ?? 1);
        $keyword = (string)($_GET['q'] ?? '');
        $data = $scope === 'all'
            ? $this->query->allOrders($page, 25, $keyword)
            : $this->query->list($scope, $page, 25, $keyword);
        \json_response(['ok'=>true,'data'=>$data]);
    }
}

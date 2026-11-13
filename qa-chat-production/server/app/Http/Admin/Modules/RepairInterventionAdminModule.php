<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\AdminWorkbenchActionComposition;
use WorkerAdmin\Foundation\AdminWorkbenchReadComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/** Web 后台处理已进入平台介入阶段的维修售后；不承载退款或赔付。 */
final class RepairInterventionAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private AdminWorkbenchReadComposition $read;
    private AdminWorkbenchActionComposition $actions;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->read = new AdminWorkbenchReadComposition($db);
        $this->actions = new AdminWorkbenchActionComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['repair-intervention','repair-intervention.resolve'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('orders.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'repair-intervention') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $orderNo = trim((string)($_GET['order_id'] ?? ''));
                $afterSaleNo = trim((string)($_GET['after_sale_id'] ?? ''));
                if ($orderNo === '' || $afterSaleNo === '') {
                    throw new OrderOperationException('缺少订单或售后编号', 'REPAIR_PLATFORM_TARGET_REQUIRED', 422);
                }
                $projection = $this->read->query()->orderProjection($orderNo);
                if ($projection === null) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
                $task = null;
                foreach (($projection['tasks'] ?? []) as $candidate) {
                    if ((string)($candidate['task_type'] ?? '') === 'after_sale_intervention'
                        && (string)($candidate['source_id'] ?? '') === $afterSaleNo) {
                        $task = $candidate;
                        break;
                    }
                }
                if (!$task) {
                    throw new OrderOperationException('该售后当前不需要平台介入，请刷新异常工作台', 'REPAIR_PLATFORM_TASK_NOT_FOUND', 409);
                }
                echo \view('admin/repair-intervention', [
                    'pageTitle'=>'售后平台介入',
                    'activeMenu'=>'dashboard',
                    'orderNo'=>$orderNo,
                    'afterSaleNo'=>$afterSaleNo,
                    'task'=>$task,
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();
            $adminId = (int)($this->auth->user()['id'] ?? 0);
            $result = $this->actions->repairResolution()->execute(
                ['channel'=>'web_admin','user_id'=>$adminId],
                $_POST
            );
            \audit(
                'repair.platform.resolve',
                'after_sale',
                (string)($_POST['after_sale_id'] ?? ''),
                (string)($result['message'] ?? '平台处理维修售后'),
                null,
                [
                    'order_id'=>$_POST['order_id'] ?? '',
                    'decision'=>$_POST['decision'] ?? '',
                ]
            );
            \flash('success', (string)($result['message'] ?? '售后处理完成'));
            \redirect(\admin_url('dashboard'));
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('dashboard'));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('repair-intervention-admin', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '售后平台介入处理失败。请求编号：'.$requestId);
            \redirect(\admin_url('dashboard'));
        }
    }
}

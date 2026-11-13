<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\AdminWorkbenchReadComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Admin\AdminModule;

/** Web 后台测量冻结延期异常审核；首次低风险延期仍由自动化直接批准。 */
final class MeasurementHoldReviewAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private AdminWorkbenchReadComposition $read;
    private OrderApplicationContainer $orders;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->read = new AdminWorkbenchReadComposition($db);
        $this->orders = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['measurement-hold-review','measurement-hold-review.resolve'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('orders.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'measurement-hold-review') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $orderNo = trim((string)($_GET['order_id'] ?? ''));
                $requestNo = trim((string)($_GET['request_id'] ?? ''));
                $task = $this->currentTask($orderNo, $requestNo);
                echo \view('admin/measurement-hold-review', [
                    'pageTitle'=>'测量冻结延期审核',
                    'activeMenu'=>'dashboard',
                    'orderNo'=>$orderNo,
                    'requestNo'=>$requestNo,
                    'task'=>$task,
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();
            $orderNo = trim((string)($_POST['order_id'] ?? ''));
            $requestNo = trim((string)($_POST['request_id'] ?? ''));
            // 页面可能长时间停留；提交前先确认仍属于统一异常投影，事务内还会再次锁行复核冻结状态和期限。
            $this->currentTask($orderNo, $requestNo);

            $decision = trim((string)($_POST['decision'] ?? ''));
            $body = [
                'request_id'=>$requestNo,
                'decision'=>$decision,
                'note'=>mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500),
            ];
            $adminId = (int)($this->auth->user()['id'] ?? 0);
            $result = $this->orders->measurementHoldExtension()->review(
                ['channel'=>'web_admin','admin_user_id'=>$adminId],
                $body
            );
            \audit(
                'order.measurement.hold-extension.review',
                'measurement_hold_extension',
                $requestNo,
                (string)($result['message'] ?? '测量冻结延期审核已完成'),
                null,
                ['order_id'=>$orderNo,'decision'=>$decision]
            );
            \flash('success', (string)($result['message'] ?? '测量冻结延期审核已完成'));
            \redirect(\admin_url('dashboard',['scope'=>'exception']));
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('dashboard',['scope'=>'exception']));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('measurement-hold-review', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '测量冻结延期审核处理失败。请求编号：'.$requestId);
            \redirect(\admin_url('dashboard',['scope'=>'exception']));
        }
    }

    /** @return array<string,mixed> */
    private function currentTask(string $orderNo, string $requestNo): array
    {
        if ($orderNo === '' || $requestNo === '') {
            throw new OrderOperationException('缺少订单或延期申请编号', 'MEASUREMENT_EXTENSION_ID_REQUIRED', 422);
        }
        $projection = $this->read->query()->orderProjection($orderNo);
        if ($projection === null) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        foreach (($projection['tasks'] ?? []) as $candidate) {
            if ((string)($candidate['task_type'] ?? '') === 'measurement_hold_review'
                && (string)($candidate['source_id'] ?? '') === $requestNo) {
                return $candidate;
            }
        }
        throw new OrderOperationException('该延期申请当前不需要人工审核，请刷新异常工作台', 'MEASUREMENT_EXTENSION_CHANGED', 409);
    }
}

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

/** Web 后台普通取消退款异常审核；资金计算与拆分继续由统一取消领域完成。 */
final class RefundReviewAdminModule implements AdminModule
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
        // orders.cancel.review 是旧订单列表内嵌表单的兼容入口；在 Legacy 前接管后不再执行旧 SQL 状态机。
        return in_array($route, ['refund-review','refund-review.resolve','orders.cancel.review'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('orders.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'refund-review') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $orderNo = trim((string)($_GET['order_id'] ?? ''));
                $requestNo = trim((string)($_GET['request_id'] ?? ''));
                $task = $this->currentRefundTask($orderNo, $requestNo);
                $requestNo = (string)$task['source_id'];
                echo \view('admin/refund-review', [
                    'pageTitle'=>'取消退款审核',
                    'activeMenu'=>'dashboard',
                    'orderNo'=>$orderNo,
                    'requestNo'=>$requestNo,
                    'task'=>$task,
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();

            if ($route === 'orders.cancel.review') {
                $projection = $this->read->query()->orderProjectionById((int)($_GET['id'] ?? $_POST['id'] ?? 0));
                if ($projection === null) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
                $orderNo = (string)$projection['order_id'];
                $task = $this->refundTaskFromProjection($projection, '');
                $requestNo = (string)$task['source_id'];
            } else {
                $orderNo = trim((string)($_POST['order_id'] ?? ''));
                $requestNo = trim((string)($_POST['request_id'] ?? ''));
                // 先确认提交的仍是当前异常任务；真正资金/状态校验仍在事务内再次执行。
                $task = $this->currentRefundTask($orderNo, $requestNo);
                $requestNo = (string)$task['source_id'];
            }

            $decision = trim((string)($_POST['decision'] ?? ''));
            $body = [
                'order_id'=>$orderNo,
                'decision'=>$decision,
                'worker_arrived'=>trim((string)($_POST['worker_arrived'] ?? 'no')),
                'responsibility'=>trim((string)($_POST['responsibility'] ?? 'unclear')),
                'reason'=>mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 1000),
                // 拒绝退款时金额没有业务意义，直接清零；批准时只接受普通十进制两位小数。
                'refund_amount_cents'=>$decision === 'approve'
                    ? $this->moneyToCents((string)($_POST['refund_amount'] ?? ''))
                    : 0,
            ];
            $adminId = (int)($this->auth->user()['id'] ?? 0);
            $result = $this->orders->cancellation()->review(
                ['channel'=>'web_admin','admin_user_id'=>$adminId],
                $body
            );
            \audit(
                'order.cancel.review',
                'order',
                $orderNo,
                (string)($result['message'] ?? '取消退款审核已完成'),
                null,
                [
                    'request_id'=>$requestNo,
                    'decision'=>$body['decision'],
                    'refund_amount_cents'=>$body['decision'] === 'approve' ? $body['refund_amount_cents'] : 0,
                    'responsibility'=>$body['responsibility'],
                    'entry_route'=>$route,
                ]
            );
            \flash('success', (string)($result['message'] ?? '取消退款审核已完成'));
            \redirect(\admin_url('dashboard',['scope'=>'refund_dispute']));
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('dashboard',['scope'=>'refund_dispute']));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('refund-review', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '取消退款审核处理失败。请求编号：'.$requestId);
            \redirect(\admin_url('dashboard',['scope'=>'refund_dispute']));
        }
    }

    /** @return array<string,mixed> */
    private function currentRefundTask(string $orderNo, string $requestNo): array
    {
        if ($orderNo === '') {
            throw new OrderOperationException('缺少订单编号', 'REFUND_REVIEW_ID_REQUIRED', 422);
        }
        $projection = $this->read->query()->orderProjection($orderNo);
        if ($projection === null) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        return $this->refundTaskFromProjection($projection, $requestNo);
    }

    /** @param array<string,mixed> $projection @return array<string,mixed> */
    private function refundTaskFromProjection(array $projection, string $requestNo): array
    {
        foreach (($projection['tasks'] ?? []) as $candidate) {
            if ((string)($candidate['task_type'] ?? '') !== 'refund_review') continue;
            if ($requestNo !== '' && (string)($candidate['source_id'] ?? '') !== $requestNo) continue;
            return $candidate;
        }
        throw new OrderOperationException('该退款申请当前不需要人工审核，请刷新异常工作台', 'REFUND_REVIEW_CHANGED', 409);
    }

    private function moneyToCents(string $raw): int
    {
        $raw = trim($raw);
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw)) {
            throw new OrderOperationException('退款金额格式不正确，最多保留两位小数', 'REFUND_AMOUNT_INVALID', 422);
        }
        $parts = explode('.', $raw, 2);
        $yuan = (int)$parts[0];
        $fen = isset($parts[1]) ? (int)str_pad($parts[1], 2, '0') : 0;
        return $yuan * 100 + $fen;
    }
}

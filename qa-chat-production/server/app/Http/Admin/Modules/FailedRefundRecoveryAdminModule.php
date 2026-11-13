<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\FailedRefundRecoveryComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/** 取消订单退款被微信判定异常/关闭后的安全恢复入口。 */
final class FailedRefundRecoveryAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private FailedRefundRecoveryComposition $composition;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->composition = new FailedRefundRecoveryComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['refunds.failures','refunds.failure','refunds.failure.recheck','refunds.failure.replace-closed'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('refunds.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'refunds.failures') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                echo \view('admin/refund-failures', [
                    'pageTitle'=>'失败退款处理',
                    'activeMenu'=>'refunds',
                    'cases'=>$this->composition->useCase()->cases(),
                ]);
                return;
            }

            if ($route === 'refunds.failure') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                echo \view('admin/refund-failure', [
                    'pageTitle'=>'失败退款详情',
                    'activeMenu'=>'refunds',
                    'refund'=>$this->composition->useCase()->detail($_GET),
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();
            $adminId = (int)($this->auth->user()['id'] ?? 0);

            if ($route === 'refunds.failure.replace-closed') {
                $result = $this->composition->useCase()->replaceClosed($_POST, $adminId);
                \audit(
                    'refund.failure.closed.replace',
                    'refund',
                    (string)($result['old_refund_id'] ?? ''),
                    '微信 CLOSED 退款按原协议生成新的退款单',
                    null,
                    [
                        'old_refund_no'=>$result['old_refund_no'] ?? '',
                        'new_refund_id'=>$result['refund_id'] ?? 0,
                        'new_refund_no'=>$result['refund_no'] ?? '',
                        'order_no'=>$result['order_no'] ?? '',
                        'amount_cents'=>$result['amount_cents'] ?? 0,
                    ]
                );
                \flash('success', (string)($result['message'] ?? '已生成新的待提交退款单。'));
                \redirect(\admin_url('refunds'));
            }

            $result = $this->composition->useCase()->recheck($_POST, $adminId);
            \audit(
                'refund.failure.recheck',
                'refund',
                (string)($result['refund_id'] ?? ''),
                '重新查询失败退款微信真实状态',
                null,
                [
                    'refund_no'=>$result['refund_no'] ?? '',
                    'order_no'=>$result['order_no'] ?? '',
                    'wechat_status'=>$result['wechat_status'] ?? '',
                    'local_status'=>$result['local_status'] ?? '',
                    'recovered'=>!empty($result['recovered']),
                ]
            );
            if (!empty($result['recovered'])) {
                \flash('success', (string)($result['message'] ?? '微信退款仍有效，已恢复自动查单。'));
                \redirect(\admin_url('refunds'));
            }
            \flash('warning', (string)($result['message'] ?? '退款仍处于异常状态，请按提示处理。'));
            \redirect(\admin_url('refunds.failure', ['id'=>$result['refund_id'] ?? 0]));
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            \redirect($id > 0 ? \admin_url('refunds.failure', ['id'=>$id]) : \admin_url('refunds.failures'));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('failed-refund-recovery', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '失败退款状态查询失败。请求编号：'.$requestId);
            \redirect(\admin_url('refunds.failures'));
        }
    }
}

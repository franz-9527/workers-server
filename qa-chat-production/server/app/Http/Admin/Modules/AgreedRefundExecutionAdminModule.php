<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\AgreedRefundExecutionComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/** 双方已同意退款后的 Web 财务执行入口；核账完成即进入自动原路退款，不提供“拒绝协议”。 */
final class AgreedRefundExecutionAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private AgreedRefundExecutionComposition $composition;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->composition = new AgreedRefundExecutionComposition($db);
    }

    public function supports(string $route): bool
    {
        return in_array($route, ['refunds.execution','refunds.execution.prepare'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('refunds.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'refunds.execution') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $cases = $this->composition->useCase()->cases();
                echo \view('admin/refund-execution', [
                    'pageTitle'=>'退款执行核账',
                    'activeMenu'=>'refunds',
                    'cases'=>$cases,
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();
            $adminId = (int)($this->auth->user()['id'] ?? 0);
            $result = $this->composition->useCase()->prepare($_POST, $adminId);
            \audit(
                'refund.execution.prepare',
                'order_cancel_request',
                (string)($result['request_id'] ?? ''),
                '双方退款协议完成财务核账并进入自动原路退款执行',
                null,
                [
                    'order_no'=>$result['order_no'] ?? '',
                    'refund_cents'=>$result['refund_cents'] ?? 0,
                    'refund_count'=>$result['refund_count'] ?? 0,
                    'refund_ids'=>$result['refund_ids'] ?? [],
                    'auto_submit_queued'=>!empty($result['auto_submit_queued']),
                ]
            );
            \flash(
                'success',
                '已按双方协议和当前真实原支付生成 '.(int)($result['refund_count'] ?? 0).' 笔退款单，并进入自动原路退款执行；无需再到退款管理重复审核。'
            );
            \redirect(\admin_url('dashboard',['scope'=>'finance']));
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('refunds.execution'));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('agreed-refund-execution', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '退款执行核账失败。请求编号：'.$requestId);
            \redirect(\admin_url('refunds.execution'));
        }
    }
}

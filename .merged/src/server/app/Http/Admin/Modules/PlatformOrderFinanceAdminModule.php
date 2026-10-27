<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Admin\AdminModule;

final class PlatformOrderFinanceAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private OrderApplicationContainer $container;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->container = new OrderApplicationContainer($db);
    }

    public function supports(string $route): bool
    {
        return $route === 'platform-order-finance' || strpos($route, 'platform-order-finance.') === 0;
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('orders.manage');
        $map = [
            'platform-order-finance'=>'index',
            'platform-order-finance.change-approve'=>'approveChange',
            'platform-order-finance.change-reject'=>'rejectChange',
            'platform-order-finance.income-paid'=>'markIncomePaid',
            'platform-order-finance.refund-open'=>'openRefundReview',
            'platform-order-finance.refund-decide'=>'decideRefundReview',
            'platform-order-finance.refund-recovery'=>'confirmRefundRecovery',
        ];
        if (!isset($map[$route])) {
            http_response_code(404);
            echo \view('errors/404', ['pageTitle'=>'页面不存在']);
            return;
        }

        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'POST') $this->csrf->verifyRequest();
            $adminId = (int)(($this->auth->user()['id'] ?? 0));

            if ($route === 'platform-order-finance') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $data = $this->container->platformOrderFinance()->dashboard();
                echo \view('admin/platform-order-finance', array_merge([
                    'pageTitle'=>'平台承接结算',
                    'activeMenu'=>'platform-orders',
                ], $data));
                return;
            }
            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);

            if ($route === 'platform-order-finance.change-approve') {
                $this->container->platformOrderFinance()->approveChange($_POST, $adminId);
                \flash('success', '工费加价已批准；师傅工费已增加，客户加收金额按本次填写处理。');
            } elseif ($route === 'platform-order-finance.change-reject') {
                $this->container->platformOrderFinance()->rejectChange($_POST, $adminId);
                \flash('success', '工费加价申请已驳回。');
            } elseif ($route === 'platform-order-finance.income-paid') {
                $this->container->platformOrderFinance()->markIncomePaid($_POST, $adminId);
                \flash('success', '平台收入款已确认到账，本单平台结算完成。');
            } elseif ($route === 'platform-order-finance.refund-open') {
                $this->container->platformOrderFinance()->openRefundReview($_POST, $adminId);
                \flash('success', '事后退款已建档，请核对原工费入账、退款流水和资金责任。');
            } elseif ($route === 'platform-order-finance.refund-decide') {
                $this->container->platformOrderFinance()->decideRefundReview($_POST, $adminId);
                \flash('success', '退款资金责任已记录；若需师傅补款，将等待线下真实到账核实。');
            } else {
                $this->container->platformOrderFinance()->confirmRefundRecovery($_POST, $adminId);
                \flash('success', '已核对本笔线下真实到账，累计金额已更新；未收齐的退款继续等待追偿，未扣减师傅钱包。');
            }
            \redirect(\admin_url('platform-order-finance'));
        } catch (OrderOperationException $exception) {
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('platform-order-finance'));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('platform-order-finance', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '平台承接单结算处理失败。请求编号：'.$requestId);
            \redirect(\admin_url('platform-order-finance'));
        }
    }
}

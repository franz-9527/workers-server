<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use Throwable;
use WorkerAdmin\Application\Admin\AdminOversightQuery;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\AdminOversightComposition;
use WorkerAdmin\Foundation\AdminWorkbenchReadComposition;
use WorkerAdmin\Foundation\OrderApplicationContainer;
use WorkerAdmin\Http\Admin\AdminModule;

/** Web 后台争议列表与裁定；资金裁定继续共用 OrderDispute 领域事务。 */
final class DisputeReviewAdminModule implements AdminModule
{
    private Auth $auth;
    private Csrf $csrf;
    private AdminWorkbenchReadComposition $read;
    private OrderApplicationContainer $orders;
    private AdminOversightQuery $oversight;

    public function __construct(Database $db, Auth $auth, Csrf $csrf)
    {
        $this->auth = $auth;
        $this->csrf = $csrf;
        $this->read = new AdminWorkbenchReadComposition($db);
        $this->orders = new OrderApplicationContainer($db);
        $this->oversight = AdminOversightComposition::build($db);
    }

    public function supports(string $route): bool
    {
        // disputes.resolve 是旧争议列表弹窗的兼容入口；在 Legacy 前接管后旧 SQL 状态机不再执行。
        return in_array($route, ['disputes','dispute-review','dispute-review.resolve','disputes.resolve'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('disputes.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'disputes') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $result = $this->oversight->disputes(
                    max(1, (int)($_GET['page'] ?? 1)),
                    (bool)\setting('demo.data_enabled', true)
                );
                echo \view('admin/resource-list', [
                    'pageTitle'=>'争议处理',
                    'activeMenu'=>'disputes',
                    'result'=>$result,
                    'resource'=>'disputes',
                    'columns'=>[
                        ['key'=>'dispute_no','label'=>'争议号'],
                        ['key'=>'public_no','label'=>'订单号'],
                        ['key'=>'type','label'=>'类型','type'=>'status'],
                        ['key'=>'status','label'=>'状态','type'=>'status'],
                        ['key'=>'responsibility','label'=>'责任','type'=>'status'],
                        ['key'=>'description','label'=>'说明'],
                        ['key'=>'refund_cents','label'=>'退款','type'=>'money'],
                        ['key'=>'runner_fee_cents','label'=>'跑空费','type'=>'money'],
                        ['key'=>'created_at','label'=>'时间','type'=>'datetime'],
                    ],
                ]);
                return;
            }

            if ($route === 'dispute-review') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $orderNo = trim((string)($_GET['order_id'] ?? ''));
                $disputeNo = trim((string)($_GET['dispute_id'] ?? ''));
                $task = $this->currentDisputeTask($orderNo, $disputeNo);
                $disputeNo = (string)$task['source_id'];
                echo \view('admin/dispute-review', [
                    'pageTitle'=>'争议裁定',
                    'activeMenu'=>'dashboard',
                    'orderNo'=>$orderNo,
                    'disputeNo'=>$disputeNo,
                    'task'=>$task,
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();

            if ($route === 'disputes.resolve') {
                $projection = $this->read->query()->disputeProjectionById((int)($_GET['id'] ?? $_POST['id'] ?? 0));
                if ($projection === null) {
                    throw new OrderOperationException('争议不存在、已处理或当前不需要平台介入', 'DISPUTE_ADMIN_NOT_FOUND', 409);
                }
                $orderNo = (string)$projection['order_id'];
                $disputeNo = (string)$projection['dispute_id'];
                $task = is_array($projection['task'] ?? null) ? $projection['task'] : [];
                $facts = is_array($task['facts'] ?? null) ? $task['facts'] : [];
                if ((int)($facts['refund_amount_cents'] ?? 0) > 0 || (int)($facts['run_empty_fee_cents'] ?? 0) > 0) {
                    throw new OrderOperationException(
                        '该争议已有退款或跑空费金额，请从异常工作台进入“争议裁定”专项页处理',
                        'DISPUTE_FINANCIAL_USE_FOCUSED_REVIEW',
                        409
                    );
                }
            } else {
                $orderNo = trim((string)($_POST['order_id'] ?? ''));
                $disputeNo = trim((string)($_POST['dispute_id'] ?? ''));
                $task = $this->currentDisputeTask($orderNo, $disputeNo);
                $disputeNo = (string)$task['source_id'];
            }

            $refundCents = $this->moneyToCents((string)($_POST['refund_amount'] ?? '0'), '退款金额');
            $runEmptyCents = $this->moneyToCents((string)($_POST['runner_amount'] ?? '0'), '跑空费');
            $body = [
                'order_id'=>$orderNo,
                'resolution'=>mb_substr(trim((string)($_POST['resolution'] ?? '')), 0, 1000),
                'responsibility'=>trim((string)($_POST['responsibility'] ?? 'unclear')),
                'refund_amount_cents'=>$refundCents,
                'run_empty_fee_cents'=>$runEmptyCents,
            ];
            $adminId = (int)($this->auth->user()['id'] ?? 0);
            $result = $this->orders->dispute()->execute(
                'admin.dispute.resolve',
                ['channel'=>'web_admin','admin_user_id'=>$adminId],
                $body
            );
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            \audit(
                'dispute.resolve',
                'dispute',
                $disputeNo,
                (string)($result['message'] ?? '争议裁定已完成'),
                null,
                [
                    'order_id'=>$orderNo,
                    'responsibility'=>$body['responsibility'],
                    'refund_cents'=>(int)($data['refund_cents'] ?? $refundCents),
                    'run_empty_fee_cents'=>(int)($data['run_empty_fee_cents'] ?? $runEmptyCents),
                    'auto_refund_cents'=>(int)($data['auto_refund_cents'] ?? 0),
                    'entry_route'=>$route,
                ]
            );
            \flash('success', (string)($result['message'] ?? '争议裁定已完成'));
            \redirect(\admin_url('dashboard',['scope'=>'refund_dispute']));
        } catch (OrderOperationException $exception) {
            if ($route === 'disputes' && $method === 'GET') {
                http_response_code($exception->getCode() >= 400 ? $exception->getCode() : 500);
                echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>$exception->getMessage()]);
                return;
            }
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('dashboard',['scope'=>'refund_dispute']));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('dispute-review', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            if ($route === 'disputes' && $method === 'GET') {
                http_response_code(500);
                echo \view('errors/500', ['pageTitle'=>'系统处理失败','message'=>'争议列表读取失败，请稍后重试。请求编号：'.$requestId]);
                return;
            }
            \flash('danger', '争议裁定处理失败。请求编号：'.$requestId);
            \redirect(\admin_url('dashboard',['scope'=>'refund_dispute']));
        }
    }

    /** @return array<string,mixed> */
    private function currentDisputeTask(string $orderNo, string $disputeNo): array
    {
        if ($orderNo === '') throw new OrderOperationException('缺少订单编号', 'ORDER_ID_REQUIRED', 422);
        $projection = $this->read->query()->orderProjection($orderNo);
        if ($projection === null) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
        foreach (($projection['tasks'] ?? []) as $candidate) {
            if ((string)($candidate['task_type'] ?? '') !== 'dispute_open') continue;
            if ($disputeNo !== '' && (string)($candidate['source_id'] ?? '') !== $disputeNo) continue;
            return $candidate;
        }
        throw new OrderOperationException('该争议当前不需要平台介入，请刷新异常工作台', 'DISPUTE_ADMIN_NOT_FOUND', 409);
    }

    private function moneyToCents(string $raw, string $label): int
    {
        $raw = trim($raw);
        if ($raw === '') $raw = '0';
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw)) {
            throw new OrderOperationException($label.'格式不正确，最多保留两位小数', 'DISPUTE_AMOUNT_INVALID', 422);
        }
        $parts = explode('.', $raw, 2);
        $yuan = (int)$parts[0];
        $fen = isset($parts[1]) ? (int)str_pad($parts[1], 2, '0') : 0;
        return $yuan * 100 + $fen;
    }
}

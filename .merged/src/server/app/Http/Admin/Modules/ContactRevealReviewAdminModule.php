<?php

declare(strict_types=1);

namespace WorkerAdmin\Http\Admin\Modules;

use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Auth;
use WorkerAdmin\Core\Csrf;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Order\OrderOperationException;
use WorkerAdmin\Foundation\AdminWorkbenchActionComposition;
use WorkerAdmin\Foundation\AdminWorkbenchReadComposition;
use WorkerAdmin\Http\Admin\AdminModule;

/** Web 后台成交师傅电话公开异常审批；正常客观场景仍由自动化直接处理。 */
final class ContactRevealReviewAdminModule implements AdminModule
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
        // orders.contact-reveal.review 是旧订单页兼容入口；在 Legacy 前接管，避免两套审批状态机。
        return in_array($route, ['contact-reveal-review','contact-reveal-review.resolve','orders.contact-reveal.review'], true);
    }

    public function dispatch(string $route): void
    {
        $this->auth->requireLogin();
        $this->auth->requirePermission('orders.manage');
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($route === 'contact-reveal-review') {
                if ($method !== 'GET') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
                $orderNo = trim((string)($_GET['order_id'] ?? ''));
                $requestNo = trim((string)($_GET['request_id'] ?? ''));
                if ($orderNo === '' || $requestNo === '') {
                    throw new OrderOperationException('缺少订单或电话公开申请编号', 'CONTACT_REVEAL_REVIEW_ID_REQUIRED', 422);
                }

                $projection = $this->read->query()->orderProjection($orderNo);
                if ($projection === null) throw new OrderOperationException('订单不存在', 'ORDER_NOT_FOUND', 404);
                $task = null;
                foreach (($projection['tasks'] ?? []) as $candidate) {
                    if ((string)($candidate['task_type'] ?? '') === 'contact_reveal_review'
                        && (string)($candidate['source_id'] ?? '') === $requestNo) {
                        $task = $candidate;
                        break;
                    }
                }
                if (!$task) {
                    throw new OrderOperationException('该电话公开申请当前不需要人工审核，请刷新异常工作台', 'CONTACT_REVEAL_REVIEW_CHANGED', 409);
                }

                echo \view('admin/contact-reveal-review', [
                    'pageTitle'=>'电话公开审核',
                    'activeMenu'=>'dashboard',
                    'orderNo'=>$orderNo,
                    'requestNo'=>$requestNo,
                    'task'=>$task,
                ]);
                return;
            }

            if ($method !== 'POST') throw new OrderOperationException('请求方式不正确', 'METHOD_NOT_ALLOWED', 405);
            $this->csrf->verifyRequest();
            $decision = trim((string)($_POST['decision'] ?? ''));
            $note = mb_substr(trim((string)($_POST[$route === 'orders.contact-reveal.review' ? 'review_note' : 'note'] ?? '')), 0, 500);
            $adminId = (int)($this->auth->user()['id'] ?? 0);

            if ($route === 'orders.contact-reveal.review') {
                $internalId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
                $result = $this->actions->contactRevealReview()->reviewById(
                    $internalId,
                    $decision,
                    $note,
                    ['channel'=>'web_admin','admin_user_id'=>$adminId]
                );
                $auditTarget = 'internal:'.$internalId;
            } else {
                $requestNo = trim((string)($_POST['request_id'] ?? ''));
                $result = $this->actions->contactRevealReview()->reviewByPublicNo(
                    $requestNo,
                    $decision,
                    $note,
                    ['channel'=>'web_admin','admin_user_id'=>$adminId]
                );
                $auditTarget = $requestNo;
            }

            \audit(
                'order.contact-reveal.review',
                'contact_reveal_request',
                $auditTarget,
                (string)($result['message'] ?? '电话公开申请已处理'),
                null,
                ['decision'=>$decision,'status'=>$result['status'] ?? '','entry_route'=>$route]
            );
            \flash('success', (string)($result['message'] ?? '电话公开申请已处理'));
            \redirect(\admin_url('dashboard',['scope'=>'privacy']));
        } catch (RuntimeException $exception) {
            // 统一电话公开 Application 仍以 RuntimeException 表达“师傅已响应/维修已结束”等可预期业务冲突。
            \flash('danger', $exception->getMessage());
            \redirect(\admin_url('dashboard',['scope'=>'privacy']));
        } catch (Throwable $exception) {
            $requestId = \request_id();
            \write_log('contact-reveal-review', '['.date('c').'] request_id='.$requestId.' '.$exception."\n");
            \flash('danger', '电话公开审核处理失败。请求编号：'.$requestId);
            \redirect(\admin_url('dashboard',['scope'=>'privacy']));
        }
    }
}

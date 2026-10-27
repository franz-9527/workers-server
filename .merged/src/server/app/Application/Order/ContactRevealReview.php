<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use RuntimeException;
use WorkerAdmin\Contracts\OrderContactRevealRepository;
use WorkerAdmin\Contracts\OrderNotificationPort;
use WorkerAdmin\Contracts\TransactionManager;
use WorkerAdmin\Domain\Order\ContactRevealReviewPolicy;

/**
 * 成交师傅电话公开审批用例。
 *
 * 保持事务、事件与消息投递在同一业务用例中协调，但不感知 SQL 或具体消息渠道。
 */
final class ContactRevealReview
{
    private TransactionManager $transaction;
    private OrderContactRevealRepository $repository;
    private OrderNotificationPort $notifications;
    private ContactRevealReviewPolicy $policy;

    public function __construct(
        TransactionManager $transaction,
        OrderContactRevealRepository $repository,
        OrderNotificationPort $notifications,
        ?ContactRevealReviewPolicy $policy = null
    ) {
        $this->transaction = $transaction;
        $this->repository = $repository;
        $this->notifications = $notifications;
        $this->policy = $policy ?: new ContactRevealReviewPolicy();
    }

    public function reviewById(int $id, string $decision, string $note, array $operator): array
    {
        if ($id < 1) {
            throw new RuntimeException('电话公开申请不存在。');
        }
        return $this->review('id', $id, $decision, $note, $operator);
    }

    public function reviewByPublicNo(string $publicNo, string $decision, string $note, array $operator): array
    {
        $publicNo = trim($publicNo);
        if ($publicNo === '') {
            throw new RuntimeException('缺少电话公开申请编号。');
        }
        return $this->review('public_no', $publicNo, $decision, $note, $operator);
    }

    private function review(string $lookupField, $lookupValue, string $decision, string $note, array $operator): array
    {
        $decision = $this->policy->normalizeDecision($decision);
        $note = $this->policy->normalizeNote($decision, $note);
        $reviewer = $this->policy->normalizeOperator($operator);

        return $this->transaction->run(function () use ($lookupField, $lookupValue, $decision, $note, $reviewer): array {
            $request = $lookupField === 'id'
                ? $this->repository->findRequestForUpdateById((int) $lookupValue)
                : $this->repository->findRequestForUpdateByPublicNo((string) $lookupValue);
            if (!$request || (string) ($request['status'] ?? '') !== 'pending') {
                throw new RuntimeException('申请不存在或已经处理，请刷新列表。');
            }

            $order = $this->repository->findOrderForUpdate((int) $request['order_id']);
            if (!$order) {
                throw new RuntimeException('订单不存在。');
            }
            if ($this->policy->isMerchantOrder($order)) {
                throw new RuntimeException('商家订单不允许直接公开师傅电话，只能由客服在争议场景协助核实。');
            }

            $cancelStale = function (string $reviewNote, string $message) use ($order, $request, $reviewer): array {
                $this->repository->cancelRequest(
                    (int) $request['id'],
                    $reviewer['reviewed_by'],
                    $reviewNote
                );
                $this->appendEvent(
                    $order,
                    $request,
                    'worker_contact_reveal_cancelled',
                    $reviewer,
                    $message
                );
                $this->notifications->queue(
                    (int) $order['id'],
                    'contact-reveal:' . (string) $request['public_no'] . ':cancelled',
                    'worker_contact_reveal_cancelled',
                    ['request_id' => (string) $request['public_no']]
                );
                return [
                    'order_id' => (int) $order['id'],
                    'status' => 'cancelled',
                    'message' => $message,
                ];
            };

            if ($this->policy->assignedWorkerChanged($order, $request)) {
                return $cancelStale(
                    '订单当前成交师傅已变更',
                    '当前成交师傅已变更，本次电话公开申请已自动取消。'
                );
            }
            if ($this->policy->isNotEligibleYet($request, time())) {
                throw new RuntimeException('尚未达到电话公开审核时间，请稍后再处理。');
            }

            $context = (string) ($request['context_type'] ?? '');
            if ($context === 'service' && $this->policy->isServiceFinished($order)) {
                return $cancelStale('本次施工已经完成', '本次施工已经完成，电话公开申请已自动取消。');
            }

            if ($context === 'repair') {
                $repair = $this->repository->findRepair(
                    (int) ($request['after_sale_id'] ?: 0),
                    (int) $order['id']
                );
                if (!$this->policy->repairIsActive($repair)) {
                    return $cancelStale('维修申请已结束', '维修申请已结束，电话公开申请已自动取消。');
                }
            }

            if ($decision === 'approve' && $this->repository->hasAppointmentResponse(
                (int) $order['id'],
                (int) $request['worker_id'],
                $context,
                $context === 'repair' ? (int) ($request['after_sale_id'] ?: 0) : null
            )) {
                return $cancelStale(
                    '师傅已提交预约或联系响应，无需公开电话',
                    '师傅已经响应，本次电话公开申请已自动取消。'
                );
            }

            $status = $this->policy->statusForDecision($decision);
            $reviewNote = $this->policy->reviewNote($status, $note);
            $this->repository->decideRequest(
                (int) $request['id'],
                $status,
                $reviewer['reviewed_by'],
                $reviewNote !== '' ? $reviewNote : null
            );

            $message = $status === 'approved'
                ? '电话公开申请已批准，仅当前履约场景生效。'
                : '电话公开申请已拒绝。';
            $eventType = $status === 'approved'
                ? 'worker_contact_reveal_approved'
                : 'worker_contact_reveal_rejected';

            $this->appendEvent(
                $order,
                $request,
                $eventType,
                $reviewer,
                $message . ($reviewNote !== '' ? ' ' . $reviewNote : '')
            );
            $this->notifications->queue(
                (int) $order['id'],
                'contact-reveal:' . (string) $request['public_no'] . ':' . $status,
                $eventType,
                ['request_id' => (string) $request['public_no']]
            );

            return [
                'order_id' => (int) $order['id'],
                'status' => $status,
                'message' => $message,
            ];
        });
    }

    private function appendEvent(
        array $order,
        array $request,
        string $eventType,
        array $reviewer,
        string $message
    ): void {
        $payload = [
            'message' => $message,
            'contact_request_id' => (string) $request['public_no'],
            'context_type' => (string) $request['context_type'],
            'after_sale_id' => $request['after_sale_id'] ? (int) $request['after_sale_id'] : null,
            'reviewer_channel' => (string) $reviewer['channel'],
        ];
        if ($reviewer['channel'] === 'web_admin') {
            $payload['reviewer_admin_user_id'] = (int) $reviewer['web_admin_id'];
        } else {
            $payload['reviewer_user_id'] = (int) $reviewer['mini_user_id'];
        }

        $this->repository->appendEvent(
            (int) $order['id'],
            $eventType,
            (string) $reviewer['channel'],
            (int) $reviewer['actor_id'],
            $payload
        );
    }
}

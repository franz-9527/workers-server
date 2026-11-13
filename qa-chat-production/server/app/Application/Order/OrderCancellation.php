<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderCancellationRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class OrderCancellation
{
    private OrderCancellationRepository $repository;

    public function __construct(OrderCancellationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function request(array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 1000);
        if (mb_strlen($reason) < 4) {
            throw new OrderOperationException('请说明取消原因', 'REASON_REQUIRED', 422);
        }
        $reasonCode = mb_substr(trim((string)($body['reason_code'] ?? 'user_request')), 0, 64);
        if ($reasonCode === '') $reasonCode = 'user_request';

        $result = $this->repository->request($actor, $orderNo, $reason, $reasonCode);
        $auto = !empty($result['auto_approved']);
        $amount = max(0, (int)($result['refund_cents'] ?? 0));
        return [
            'message'=>!empty($result['worker_cancelled']) ? '师傅因自身原因取消，客户不承担补偿费用；可退线上实收已全额进入原路退款处理。' : ($auto
                ? '订单尚未成交或履约，已自动取消；可退线上款项 ¥'.number_format($amount/100, 2).' 已进入原路退款处理，无需管理员审核。'
                : (!empty($result['bilateral_negotiation']) ? '退款方案已提交，当前可退线上款项合计 ¥'.number_format($amount/100, 2).'；请先由对方接受或拒绝方案；拒绝或超时后可申请平台介入。' : '退款申请已提交，缺少有效协商对方或涉及资金异常，等待平台核对。')),
            'data'=>$result,
        ];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function review(array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        $decision = trim((string)($body['decision'] ?? ''));
        if (!in_array($decision, ['approve','reject'], true)) {
            throw new OrderOperationException('请选择通过或拒绝退款', 'INVALID_REFUND_DECISION', 422);
        }
        $workerArrived = trim((string)($body['worker_arrived'] ?? 'no'));
        $responsibility = trim((string)($body['responsibility'] ?? 'unclear'));
        if (!in_array($responsibility, ['worker','user','platform','shared','unclear'], true)) {
            throw new OrderOperationException('请选择责任归属', 'INVALID_RESPONSIBILITY', 422);
        }
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 1000);
        if (mb_strlen($reason) < 2) {
            throw new OrderOperationException('请填写审核说明', 'REASON_REQUIRED', 422);
        }
        $refundCents = max(0, (int)($body['refund_amount_cents'] ?? 0));

        // 小程序管理员调用历史上没有 channel 字段，因此空 channel 仅兼容为 mini_admin；
        // Web 必须显式传 web_admin + admin_user_id，避免把 admin_users.id 混入 users.id 域。
        $channel = trim((string)($actor['channel'] ?? ''));
        if ($channel === '' || $channel === 'mini_admin') {
            $userId = max(0, (int)($actor['user_id'] ?? 0));
            if ($userId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
            $operator = ['channel'=>'mini_admin','user_id'=>$userId];
        } elseif ($channel === 'web_admin') {
            $adminId = max(0, (int)($actor['admin_user_id'] ?? 0));
            if ($adminId < 1) throw new OrderOperationException('管理员身份无效', 'ADMIN_REQUIRED', 403);
            $operator = ['channel'=>'web_admin','admin_user_id'=>$adminId];
        } else {
            throw new OrderOperationException('管理员操作来源无效', 'ADMIN_REQUIRED', 403);
        }

        $result = $this->repository->review(
            $operator,
            $orderNo,
            $decision,
            $workerArrived,
            $responsibility,
            $reason,
            $refundCents
        );
        return [
            'message'=>!empty($result['approved']) ? '退款审核已完成' : '已拒绝退款申请',
            'data'=>$result,
        ];
    }
}

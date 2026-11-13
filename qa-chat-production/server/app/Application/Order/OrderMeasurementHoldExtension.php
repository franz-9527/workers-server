<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderMeasurementHoldExtensionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 测量冻结延期：首次小范围自动，其余异常/重复请求转管理员。 */
final class OrderMeasurementHoldExtension
{
    private OrderMeasurementHoldExtensionRepository $repository;

    public function __construct(OrderMeasurementHoldExtensionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function request(array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        $days = max(1, min(36500, (int)($body['days'] ?? 5)));
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 500);
        $result = $this->repository->request($actor, $orderNo, $days, $reason);
        return [
            'message'=>(string)($result['message'] ?? (!empty($result['auto_approved'])
                ? '首次小范围延期已自动生效，无需平台审核。'
                : '延期申请已提交，当前情况需要平台审核。')),
            'data'=>$result,
        ];
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function review(array $actor, array $body): array
    {
        $requestId = trim((string)($body['request_id'] ?? ''));
        if ($requestId === '' || strlen($requestId) > 80) {
            throw new OrderOperationException('延期申请编号无效', 'MEASUREMENT_EXTENSION_ID_REQUIRED', 422);
        }
        $decision = trim((string)($body['decision'] ?? ''));
        if (!in_array($decision, ['approved','rejected'], true)) {
            throw new OrderOperationException('延期审核动作无效', 'INVALID_DECISION', 422);
        }
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 500);
        if ($decision === 'rejected' && mb_strlen($note) < 2) {
            throw new OrderOperationException('驳回时请填写原因', 'REVIEW_NOTE_REQUIRED', 422);
        }

        // 小程序管理员历史调用没有 channel，空值仅兼容为 mini_admin。
        // Web 后台必须显式传 web_admin + admin_user_id，避免 users.id 混入 reviewed_by(admin_users.id) 语义。
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
            $requestId,
            $decision,
            $note,
            $operator
        );
        return [
            'message'=>(string)($result['message'] ?? ($decision === 'approved' ? '延期已批准' : '延期申请已驳回')),
            'data'=>$result,
        ];
    }
}

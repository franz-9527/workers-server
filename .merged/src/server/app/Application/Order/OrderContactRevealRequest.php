<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderContactRevealRequestRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 成交师傅电话公开申请：正常场景自动，风险场景转人工。 */
final class OrderContactRevealRequest
{
    private OrderContactRevealRequestRepository $repository;

    public function __construct(OrderContactRevealRequestRepository $repository)
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
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 500);
        $result = $this->repository->request($actor, $orderNo, $reason);
        return [
            'message'=>(string)($result['message'] ?? (!empty($result['auto_approved'])
                ? '师傅超过规定时间仍未响应，联系电话已自动公开。'
                : '当前订单存在异常状态，电话公开申请已转平台审核。')),
            'data'=>$result,
        ];
    }
}

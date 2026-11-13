<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderDraftClosureRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class OrderDraftClosure
{
    private OrderDraftClosureRepository $repository;

    public function __construct(OrderDraftClosureRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $body
     * @return array{message:string,data:array<string,mixed>}
     */
    public function close(array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }

        $result = $this->repository->close($actor, $orderNo);
        return [
            'message' => !empty($result['already_closed']) ? '该未付款订单已关闭' : '未付款订单已关闭',
            'data' => $result,
        ];
    }
}

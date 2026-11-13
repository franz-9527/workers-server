<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderRepairInterventionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

final class OrderRepairIntervention
{
    private OrderRepairInterventionRepository $repository;

    public function __construct(OrderRepairInterventionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array<string,mixed> */
    public function request(array $actor, array $body): array
    {
        $orderNo = trim((string)($body['order_id'] ?? ''));
        $afterSaleNo = trim((string)($body['after_sale_id'] ?? ''));
        $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 1000);
        if ($orderNo === '' || strlen($orderNo) > 80) {
            throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        }
        if ($afterSaleNo === '' || strlen($afterSaleNo) > 80) {
            throw new OrderOperationException('维修申请编号无效', 'AFTER_SALE_ID_REQUIRED', 422);
        }
        $data = $this->repository->request($actor, $orderNo, $afterSaleNo, $note);
        return ['message'=>'已申请平台介入，平台会查看订单与维修记录','data'=>$data];
    }
}

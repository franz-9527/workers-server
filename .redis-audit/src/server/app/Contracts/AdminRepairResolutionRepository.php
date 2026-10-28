<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 平台介入后的维修售后裁定端口；不承载退款、赔付或资金处理。 */
interface AdminRepairResolutionRepository
{
    /**
     * @param array{channel:string,user_id:int} $operator
     * @return array<string,mixed>
     */
    public function resolve(
        array $operator,
        string $orderNo,
        string $afterSaleNo,
        string $decision,
        string $note
    ): array;
}

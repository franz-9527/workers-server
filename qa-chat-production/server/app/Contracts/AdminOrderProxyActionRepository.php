<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 管理员极少量订单代操作端口；不得承载平台售后裁定。 */
interface AdminOrderProxyActionRepository
{
    /**
     * @param array{channel:string,user_id:int} $operator
     * @return array<string,mixed>
     */
    public function acceptAssignment(array $operator, string $orderNo, string $note): array;
}

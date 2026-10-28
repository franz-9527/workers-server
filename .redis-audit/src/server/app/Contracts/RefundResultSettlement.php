<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

/** 已验签微信退款结果的本地资金/订单事实收口。 */
interface RefundResultSettlement
{
    /** 在调用方数据库事务内应用 SUCCESS / ABNORMAL / CLOSED 退款结果。 */
    public function apply(array $resource): void;
}

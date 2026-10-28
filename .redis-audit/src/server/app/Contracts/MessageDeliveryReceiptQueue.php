<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

/** 以一次数据库更新同时确认队列并保存脱敏供应商回执。 */
interface MessageDeliveryReceiptQueue
{
    public function completeWithReceipt(int $id, array $receipt): void;
}

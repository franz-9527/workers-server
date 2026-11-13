<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

/** 由服务端装配的下单数据域；HTTP 请求不能选择或构造 scope。 */
interface OrderSubmissionScope
{
    public function assertActor(array $actor): void;
    public function isDemo(): bool;
    public function bindDraft(array $actor,int $orderId): void;
    public function assertOrder(array $actor,array $order): void;
}

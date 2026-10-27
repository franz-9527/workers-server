<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderQuoteSwitchRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** 兼容旧客户端入口；仓储统一拒绝直接更换，保留取消协商边界。 */
final class OrderQuoteSwitch
{
    private OrderQuoteSwitchRepository $repository;

    public function __construct(OrderQuoteSwitchRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function execute(array $actor, array $body): array
    {
        $orderId = trim((string)($body['order_id'] ?? ''));
        $quoteId = trim((string)($body['quote_id'] ?? ''));
        $reason = mb_substr(trim((string)($body['reason'] ?? '')), 0, 500);
        if ($orderId === '' || strlen($orderId) > 80) throw new OrderOperationException('订单编号无效', 'ORDER_ID_REQUIRED', 422);
        if ($quoteId === '' || strlen($quoteId) > 80) throw new OrderOperationException('请选择新的师傅报价', 'QUOTE_ID_REQUIRED', 422);
        if (mb_strlen($reason) < 6) throw new OrderOperationException('为保障双方权益，请说明更换原因（至少 6 个字）', 'SWITCH_REASON_REQUIRED', 422);
        return $this->repository->switchWorker($actor, $orderId, $quoteId, $reason);
    }
}

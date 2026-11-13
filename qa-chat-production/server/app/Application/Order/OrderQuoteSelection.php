<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Order;

use WorkerAdmin\Contracts\OrderQuoteSelectionRepository;
use WorkerAdmin\Domain\Order\OrderOperationException;

/** V2.5.0 用户选择报价；平台承接单由平台选师傅，不进入该用户动作。 */
final class OrderQuoteSelection
{
    private OrderQuoteSelectionRepository $repository;

    public function __construct(OrderQuoteSelectionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $body @return array{message:string,data:array<string,mixed>} */
    public function execute(array $actor, array $body): array
    {
        $quoteId = trim((string)($body['quote_id'] ?? ''));
        if ($quoteId === '' || strlen($quoteId) > 80) {
            throw new OrderOperationException('报价编号无效', 'QUOTE_ID_REQUIRED', 422);
        }
        return $this->repository->select($actor, $quoteId);
    }
}

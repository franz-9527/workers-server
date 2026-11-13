<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderMediaUrlSigner
{
    /**
     * @param array<string,mixed> $actor
     * @param string|null $shareHash 当前普通分享上下文的 token hash；仅订单分享读取时传入。
     */
    public function sign(
        string $mediaPublicId,
        array $actor,
        int $orderId,
        string $entityType = 'order',
        ?int $entityId = null,
        ?string $shareHash = null
    ): string;
}

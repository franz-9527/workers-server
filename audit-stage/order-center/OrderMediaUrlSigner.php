<?php

declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface OrderMediaUrlSigner
{
    public function sign(string $mediaPublicId, array $actor, int $orderId, string $entityType = 'order', ?int $entityId = null, ?string $shareHash = null): string;
}

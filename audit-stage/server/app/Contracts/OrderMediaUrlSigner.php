<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderMediaUrlSigner
{
    /** @param array<string,mixed> $actor */
    public function sign(string $mediaPublicId, array $actor, int $orderId, string $entityType = 'order', ?int $entityId = null, ?string $shareHash = null): string;
}
<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface AdminOrderAssistanceMediaRepository
{
    public function prepareMedia(array $operator, array $command): ?array;
    public function storeMedia(array $operator, array $command, array $optimized): array;
    public function markMediaStored(string $mediaId, string $key): bool;
}

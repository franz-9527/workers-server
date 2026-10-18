<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface NotificationInboxRepository
{
    public function summary(array $actor): array;
    public function list(array $actor, int $page): array;
    public function read(array $actor, string $publicId): void;
    public function readBatch(array $actor, array $ids): int;
    public function readAll(array $actor): void;
}

<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

/** Snapshot pagination is independent of the legacy single-notification API. */
interface NotificationThreadRepository
{
    public function threads(array $actor,string $cursor=''): array;
    public function threadMessages(array $actor,string $cursor): array;
    public function readRange(array $actor,string $cursor): void;
}

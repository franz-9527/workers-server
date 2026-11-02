<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageOutboxRepository
{
    public function dashboard(string $status, string $q, int $page, string $tab = 'records'): array;
    public function retry(int $id): array;
    public function close(int $id): array;
    public function addSuppression(string $hmac, string $masked, string $reason, string $note, int $adminId): void;
    public function removeSuppression(int $id): array;
}

<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface ServiceCategoryAdminRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array;

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array;

    public function exists(int $id): bool;

    public function codeExistsExcept(string $code, int $exceptId): bool;

    public function orderCodeExistsExcept(string $orderCode, int $exceptId): bool;

    public function parentId(int $id): ?int;

    /** @param array<string,mixed> $values */
    public function save(int $id, array $values): int;

    public function toggle(int $id): bool;
}

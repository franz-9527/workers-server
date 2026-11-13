<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;

interface MiniOrdinaryOrderCreationRepository
{
    public function customers(string $query): array;
    public function form(string $customerRef): array;
    public function create(array $input, int $operatorUserId, string $requestId): array;
    public function ready(string $orderNo, int $operatorUserId): array;
}

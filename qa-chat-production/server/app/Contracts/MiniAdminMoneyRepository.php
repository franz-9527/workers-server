<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface MiniAdminMoneyRepository
{
    /** @return array<string,mixed> */
    public function listWithdrawals(int $page, int $pageSize, string $timezone): array;

    /** @return array<string,mixed> */
    public function reviewWithdrawal(array $actor, string $publicNo, string $decision, string $note): array;
}

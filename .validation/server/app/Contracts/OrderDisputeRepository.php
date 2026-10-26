<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderDisputeRepository
{
    /** @param array<string,mixed> $actor @param string[] $evidenceIds @return array<string,mixed> */
    public function startNegotiation(array $actor, string $orderNo, string $description, array $evidenceIds): array;

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function escalate(array $actor, string $disputeNo, string $note): array;

    /** @param array<string,mixed> $actor @return array<string,mixed> */
    public function resolveByAgreement(array $actor, string $disputeNo, string $note): array;

    /**
     * @param array<string,mixed> $operator 带 channel 的管理员描述符；mini_admin 只能零金额结案，web_admin 可做资金裁定。
     * @return array<string,mixed>
     */
    public function resolveByAdmin(
        array $operator,
        string $orderNo,
        string $resolution,
        string $responsibility,
        int $refundCents = 0,
        int $runEmptyCents = 0
    ): array;
}

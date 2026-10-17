<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderDisputeRepository
{
    public function startNegotiation(array $actor, string $orderNo, string $description, array $evidenceIds): array;
    public function escalate(array $actor, string $disputeNo, string $note): array;
    public function resolveByAgreement(array $actor, string $disputeNo, string $note): array;
    public function resolveByAdmin(array $operator,string $orderNo,string $resolution,string $responsibility,int $refundCents = 0,int $runEmptyCents = 0): array;
}

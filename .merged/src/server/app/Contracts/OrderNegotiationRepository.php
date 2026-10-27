<?php

declare(strict_types=1);

namespace WorkerAdmin\Contracts;

interface OrderNegotiationRepository
{
    public function respond(array $actor, string $orderNo, string $kind, string $reference, string $decision, string $note): array;
    public function escalate(array $actor, string $orderNo, string $kind, string $reference, string $note): array;
    public function proposeRefund(array $actor, string $orderNo, string $reference, int $amount, string $note): array;
    public function withdrawRefund(array $actor, string $orderNo, string $reference, string $note): array;
}

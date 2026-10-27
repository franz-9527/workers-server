<?php

declare(strict_types=1);

namespace WorkerAdmin\Services;

use WorkerAdmin\Core\Database;

final class PaidSmsDispatchService
{
    private \WorkerAdmin\Application\Messaging\PaidSmsDelivery $delivery;
    public function __construct(Database $db) { $this->delivery=(new \WorkerAdmin\Foundation\MessagingApplicationContainer($db))->paidSms(); }
    public function dispatchPending(int $limit = 50): array { return $this->delivery->dispatchPending($limit); }
    public function dispatchOutbox(int $outboxId,int $inviteId): bool { return $this->delivery->dispatchOutbox($outboxId,$inviteId); }
    public function dispatchInvite(int $inviteId, ?int $outboxId = null): void { $this->delivery->dispatchInvite($inviteId,$outboxId); }
    public function processReceipts(int $limit = 100): array { return $this->delivery->processReceipts($limit); }
    public function checkReceipt(int $inviteId): void { $this->delivery->checkReceipt($inviteId); }
    public function refreshBatch(int $batchId): void { $this->delivery->refreshBatch($batchId); }
}

<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface PaidSmsDeliveryRepository
{
    public function pending(int $limit):array;
    public function receiptPending(int $limit):array;
    public function claimOutbox(int $outboxId,int $inviteId):bool;
    public function failDispatch(int $outboxId,int $inviteId,\Throwable $exception):void;
    public function inviteForSend(int $inviteId):?array;
    public function markUncertain(int $inviteId):void;
    public function recordOutcome(array $invite,array $outcome,?int $outboxId):void;
    public function prepareReceipt(int $inviteId):?array;
    public function applyReceipt(array $invite,array $matched):void;
    public function cleanupTerminalMobileCiphertexts(): int;
    public function claimSendAttempt(array $invite,int $receiptHours): string;
    public function preSendValid(array $invite): bool;
    public function cancelBeforeSend(array $invite,string $reason): void;
    public function scheduleReceipt(int $inviteId,int $attempt,string $message): void;
    public function markOutboxProcessed(int $outboxId): void;
    public function refreshBatch(int $batchId): void;
    public function templateCode():string;
    public function assertSendBoundary():void;
}

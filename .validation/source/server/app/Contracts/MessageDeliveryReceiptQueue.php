<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageDeliveryReceiptQueue
{
    public function completeWithReceipt(int $id,array $receipt):void;
    public function completeWithReceiptAndConsumeSubscription(int $id,array $receipt,int $userId,string $scene):void;
    public function markUnknown(int $id,string $error):void;
    public function recordAcceptedWarning(int $id,string $warning):void;
}

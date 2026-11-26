<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageDeliveryReceiptQueue
{
    public function completeWithReceipt(int $id,array $receipt):void;
    public function completeWithReceiptAndConsumeSubscription(int $id,array $receipt,int $userId,string $scene):void;
    public function acquireReviewSendLock(string $cloudId):?string;
    public function releaseReviewSendLock(string $lock):void;
    public function reviewSubscriptionSkipReason(array $payload,?int &$authorizationCount=null):string;
    public function skipReviewSubscription(int $id,string $reason):void;
    public function invalidateSubscriptionForTemplate(int $userId,string $scene,string $templateId,?int $authorizationCount=null):bool;
    public function markUnknown(int $id,string $error):void;
    public function recordAcceptedWarning(int $id,string $warning):void;
}

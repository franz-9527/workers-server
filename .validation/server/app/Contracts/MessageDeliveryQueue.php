<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface MessageDeliveryQueue
{
    public function assertSendBoundary():void;
    public function recoverExpiredClaims():void;
    public function pending(int $limit):array;
    public function claim(int $id):bool;
    public function complete(int $id):void;
    public function fail(int $id,string $error,?int $delayMinutes):void;
    public function consumeSubscription(int $userId,string $scene):void;
    public function invalidateSubscription(int $userId,string $scene):void;
    public function enqueueFallback(array $payload):bool;
}

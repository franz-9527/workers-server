<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface SubscriptionQuota
{
    public function preferences(int $userId):array;
    public function consume(int $userId,string $scene):void;
    public function invalidate(int $userId,string $scene):void;
}

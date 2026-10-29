<?php
declare(strict_types=1);
namespace WorkerAdmin\Contracts;
interface NotificationProducer
{
    public function queue(int $orderId,string $key,string $type,array $payload=[]):void;
    public function adminTask(string $key,string $type,string $title,array $lines=[]):void;
    public function withdrawalResult(int $workerId,string $no,string $status,string $note='',int $amount=0):void;
    public function workerReviewResult(int $workerId,string $cloudId,string $decision,string $reason,string $name=''):void;
}

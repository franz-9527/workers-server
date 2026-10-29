<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\NotificationProducer;

final class DatabaseNotificationProducer implements NotificationProducer
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function queue(int $orderId,string $key,string $type,array $payload=[]):void { OrderNotificationProjector::queue($this->db,$orderId,$key,$type,$payload); }
    public function adminTask(string $key,string $type,string $title,array $lines=[]):void { OrderNotificationProjector::queueAdminTask($this->db,$key,$type,$title,$lines); }
    public function withdrawalResult(int $workerId,string $no,string $status,string $note='',int $amount=0):void { OrderNotificationProjector::queueWorkerWithdrawalResult($this->db,$workerId,$no,$status,$note,$amount); }
    public function workerReviewResult(int $workerId,string $cloudId,string $decision,string $reason,string $name=''):void { OrderNotificationProjector::queueWorkerReviewResult($this->db,$workerId,$cloudId,$decision,$reason,$name); }
}

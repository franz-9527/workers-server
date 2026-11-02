<?php

declare(strict_types=1);

namespace WorkerAdmin\Application\Messaging;

use InvalidArgumentException;
use WorkerAdmin\Contracts\NotificationProducer;
use WorkerAdmin\Contracts\OrderNotificationPort;

final class OrderNotificationPublisher implements OrderNotificationPort
{
    private NotificationProducer $producer;
    public function __construct(NotificationProducer $producer) { $this->producer=$producer; }
    public function queue(int $orderId,string $idempotencyKey,string $eventType,array $payload=[]):void { $this->producer->queue($orderId,$idempotencyKey,$eventType,$payload); }
    public function queuePersistedEvent(int $orderId,int $eventId,string $type,array $payload=[]):void
    { if($eventId<1)throw new InvalidArgumentException('通知必须关联已落库的订单事件');$this->queue($orderId,'order-event:'.$eventId,$type,$payload); }
    public function adminTask(string $key,string $type,string $title,array $lines=[]):void { $this->producer->adminTask($key,$type,$title,$lines); }
    public function withdrawalResult(int $workerId,string $no,string $status,string $note='',int $amount=0):void { $this->producer->withdrawalResult($workerId,$no,$status,$note,$amount); }
    public function workerReviewResult(int $workerId,string $cloudId,string $decision,string $reason,string $name=''):void { $this->producer->workerReviewResult($workerId,$cloudId,$decision,$reason,$name); }
}

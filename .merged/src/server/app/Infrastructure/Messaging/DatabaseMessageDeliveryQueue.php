<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Messaging;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\MessageDeliveryQueue;
use WorkerAdmin\Contracts\MessageDeliveryReceiptQueue;

final class DatabaseMessageDeliveryQueue implements MessageDeliveryQueue, MessageDeliveryReceiptQueue
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function assertSendBoundary():void { if($this->db->pdo()->inTransaction())throw new RuntimeException('外部消息发送不能在数据库事务中执行。'); }
    public function recoverExpiredClaims():void { $this->db->execute("UPDATE message_outbox SET status='failed',next_attempt_at=UTC_TIMESTAMP(),last_error=CONCAT(COALESCE(NULLIF(last_error,''),''),IF(COALESCE(NULLIF(last_error,''),'')='','','；'),'发送进程超时，系统已自动回收') WHERE topic IN ('aliyun_sms','wecom_webhook','wechat_subscribe') AND status='processing' AND ((next_attempt_at IS NOT NULL AND next_attempt_at<=UTC_TIMESTAMP()) OR (next_attempt_at IS NULL AND created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)))"); }
    public function pending(int $limit):array { return $this->db->fetchAll("SELECT id,topic,business_key,payload_json,attempt_count FROM message_outbox WHERE topic IN ('aliyun_sms','wecom_webhook','wechat_subscribe') AND status IN ('pending','failed') AND ((status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())) OR (status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=UTC_TIMESTAMP())) AND attempt_count<5 ORDER BY id LIMIT ".$limit); }
    public function claim(int $id):bool { return $this->db->execute("UPDATE message_outbox SET status='processing',attempt_count=attempt_count+1,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=:id AND attempt_count<5 AND ((status='pending' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP())) OR (status='failed' AND next_attempt_at IS NOT NULL AND next_attempt_at<=UTC_TIMESTAMP()))",['id'=>$id])===1; }
    public function complete(int $id):void { $this->db->execute("UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP(),next_attempt_at=NULL,last_error=NULL WHERE id=:id",['id'=>$id]); }
    public function completeWithReceipt(int $id, array $receipt):void
    {
        $json=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($json))throw new RuntimeException('消息受理回执编码失败。');
        $this->db->execute("UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP(),next_attempt_at=NULL,last_error=NULL,payload_json=JSON_SET(payload_json,'$.delivery_receipt',JSON_EXTRACT(:receipt,'$')) WHERE id=:id",['receipt'=>$json,'id'=>$id]);
    }
    public function fail(int $id,string $error,?int $delayMinutes):void
    {
        if($delayMinutes===null){ $this->db->execute("UPDATE message_outbox SET status='failed',next_attempt_at=NULL,last_error=:error WHERE id=:id",['error'=>$error,'id'=>$id]);return; }
        $minutes=max(1,min(60,$delayMinutes));$this->db->execute("UPDATE message_outbox SET status='failed',next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ".$minutes." MINUTE),last_error=:error WHERE id=:id",['error'=>$error,'id'=>$id]);
    }
    public function consumeSubscription(int $userId,string $scene):void { SubscriptionQuotaStore::markSubscriptionConsumed($this->db,$userId,$scene); }
    public function invalidateSubscription(int $userId,string $scene):void { SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$userId,$scene); }
    public function enqueueFallback(array $payload):bool { return OrderSubscriptionQueue::enqueueSmsFallback($this->db,$payload); }
}

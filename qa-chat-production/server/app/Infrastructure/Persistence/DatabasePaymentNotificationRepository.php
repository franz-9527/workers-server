<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use Throwable;
use WorkerAdmin\Contracts\PaymentNotificationRepository;
use WorkerAdmin\Core\Database;

final class DatabasePaymentNotificationRepository implements PaymentNotificationRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function register(string $notificationId, string $eventType, string $resourceType, array $headers, string $payloadCiphertext, string $payloadDigest): bool
    {
        try {
            $this->db->insert(
                "INSERT INTO payment_notifications(notification_id,event_type,resource_type,headers_json,payload_ciphertext,payload_digest,signature_verified,process_status,received_at)
                 VALUES(:id,:event,:resource,:headers,:cipher,:digest,1,'received',UTC_TIMESTAMP())",
                [
                    'id'=>$notificationId,
                    'event'=>mb_substr($eventType,0,64),
                    'resource'=>mb_substr($resourceType,0,64),
                    'headers'=>json_encode($headers,JSON_UNESCAPED_SLASHES),
                    'cipher'=>$payloadCiphertext,
                    'digest'=>$payloadDigest,
                ]
            );
            return true;
        } catch (Throwable $exception) {
            $existing = $this->db->fetch(
                'SELECT id,process_status,payload_digest FROM payment_notifications WHERE notification_id=:id',
                ['id'=>$notificationId]
            );
            if (!$existing) throw $exception;
            if (!hash_equals((string)$existing['payload_digest'],$payloadDigest)) {
                throw new RuntimeException('相同微信通知 ID 的正文摘要不一致');
            }
            return (string)$existing['process_status'] !== 'processed';
        }
    }

    public function markProcessing(string $notificationId): void
    {
        $this->db->execute(
            "UPDATE payment_notifications SET process_status='processing',error_message=NULL WHERE notification_id=:id",
            ['id'=>$notificationId]
        );
    }

    public function markProcessed(string $notificationId, ?int $paymentOrderId): void
    {
        $this->db->execute(
            "UPDATE payment_notifications SET payment_order_id=:payment_id,process_status='processed',processed_at=UTC_TIMESTAMP(),error_message=NULL WHERE notification_id=:id",
            ['payment_id'=>$paymentOrderId ?: null,'id'=>$notificationId]
        );
    }

    public function markFailed(string $notificationId, string $error): void
    {
        $this->db->execute(
            "UPDATE payment_notifications SET process_status='failed',error_message=:error WHERE notification_id=:id AND process_status<>'processed'",
            ['error'=>mb_substr($error,0,2000),'id'=>$notificationId]
        );
    }
}

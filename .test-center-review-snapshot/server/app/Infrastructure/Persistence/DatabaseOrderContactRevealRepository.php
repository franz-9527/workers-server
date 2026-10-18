<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderContactRevealRepository;
use WorkerAdmin\Core\Database;

final class DatabaseOrderContactRevealRepository implements OrderContactRevealRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findRequestForUpdateById(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM order_worker_contact_reveal_requests WHERE id=:value LIMIT 1 FOR UPDATE',
            ['value' => $id]
        );
        return $row ?: null;
    }

    public function findRequestForUpdateByPublicNo(string $publicNo): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM order_worker_contact_reveal_requests WHERE BINARY public_no=BINARY :value LIMIT 1 FOR UPDATE',
            ['value' => $publicNo]
        );
        return $row ?: null;
    }

    public function findOrderForUpdate(int $orderId): ?array
    {
        $row = $this->db->fetch('SELECT * FROM orders WHERE id=:id LIMIT 1 FOR UPDATE', ['id' => $orderId]);
        return $row ?: null;
    }

    public function cancelRequest(int $requestId, ?int $reviewedBy, string $reviewNote): void
    {
        $this->db->execute(
            "UPDATE order_worker_contact_reveal_requests
             SET status='cancelled',reviewed_by=:reviewed_by,review_note=:review_note,
                 reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=:id AND status='pending'",
            ['reviewed_by' => $reviewedBy, 'review_note' => $reviewNote, 'id' => $requestId]
        );
    }

    public function findRepair(int $afterSaleId, int $orderId): ?array
    {
        $row = $this->db->fetch(
            "SELECT id,status FROM after_sales
             WHERE id=:id AND order_id=:order_id AND type='warranty_repair' LIMIT 1",
            ['id' => $afterSaleId, 'order_id' => $orderId]
        );
        return $row ?: null;
    }

    public function hasAppointmentResponse(
        int $orderId,
        int $workerId,
        string $contextType,
        ?int $afterSaleId
    ): bool {
        $sql = "SELECT id FROM appointment_records
                WHERE order_id=:order_id AND worker_id=:worker_id AND context_type=:context";
        $params = [
            'order_id' => $orderId,
            'worker_id' => $workerId,
            'context' => $contextType,
        ];
        if ($contextType === 'repair') {
            $sql .= ' AND after_sale_id=:after_sale_id';
            $params['after_sale_id'] = $afterSaleId ?: 0;
        } else {
            $sql .= ' AND after_sale_id IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        return (bool) $this->db->fetchValue($sql, $params);
    }

    public function decideRequest(
        int $requestId,
        string $status,
        ?int $reviewedBy,
        ?string $reviewNote
    ): void {
        $this->db->execute(
            "UPDATE order_worker_contact_reveal_requests
             SET status=:status,reviewed_by=:reviewed_by,review_note=:note,
                 reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
             WHERE id=:id",
            [
                'status' => $status,
                'reviewed_by' => $reviewedBy,
                'note' => $reviewNote,
                'id' => $requestId,
            ]
        );
    }

    public function appendEvent(
        int $orderId,
        string $eventType,
        string $actorType,
        int $actorId,
        array $payload
    ): void {
        $this->db->insert(
            "INSERT INTO order_events(order_id,event_type,actor_type,actor_id,payload_json,created_at)
             VALUES(:order_id,:event_type,:actor_type,:actor_id,:payload,UTC_TIMESTAMP())",
            [
                'order_id' => $orderId,
                'event_type' => $eventType,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }
}

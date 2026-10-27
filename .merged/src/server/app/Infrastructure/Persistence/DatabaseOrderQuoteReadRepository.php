<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\OrderQuoteReadRepository;
use WorkerAdmin\Core\Database;

/**
 * 报价资格查询的 MySQL 适配器。
 *
 * SQL 与表结构集中在 Infrastructure，Application/Domain 不直接感知数据库。
 */
final class DatabaseOrderQuoteReadRepository implements OrderQuoteReadRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function restrictedMeasurementWorkerId(int $orderId): ?int
    {
        $row = $this->db->fetch(
            "SELECT worker_id FROM order_relations
             WHERE child_order_id=:order_id AND relation_type='measurement_construction' AND status='active'
             LIMIT 1",
            ['order_id' => $orderId]
        );
        if (!$row) {
            return null;
        }
        return (int) ($row['worker_id'] ?? 0);
    }

    public function activeWorkerQuoteCount(int $orderId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT worker_id) FROM quotes
             WHERE order_id=:order_id AND quote_type='worker' AND worker_id IS NOT NULL AND status='active'",
            ['order_id' => $orderId]
        );
    }

    public function hasOverdueCommission(int $workerId): bool
    {
        if ($workerId < 1) {
            return false;
        }
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM commission_receivables
             WHERE worker_id=:worker_id
               AND status IN ('pending','overdue')
               AND amount_paid_cents<amount_due_cents
               AND due_at<UTC_TIMESTAMP()",
            ['worker_id' => $workerId]
        ) > 0;
    }

    public function findExistingActiveOrSelectedQuote(int $orderId, int $workerId): ?array
    {
        $row = $this->db->fetch(
            "SELECT id,status FROM quotes
             WHERE order_id=:order_id AND worker_id=:worker_id AND status IN ('active','selected')
             ORDER BY id DESC LIMIT 1",
            ['order_id' => $orderId, 'worker_id' => $workerId]
        );
        return $row ?: null;
    }
}

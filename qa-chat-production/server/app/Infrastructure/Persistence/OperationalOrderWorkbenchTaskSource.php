<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;

/**
 * 管理员完整任务事实中的正常运营来源。
 *
 * 普通审核、平台报价成交后的安排师傅等属于正常运营待办；异常只是工作台的一个子集。
 * 支持按单订单和按一页订单 ID 批量读取，避免“全部订单”卡片逐单查询产生 N+1。
 */
final class OperationalOrderWorkbenchTaskSource
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function tasks(?int $onlyOrderId = null): array
    {
        return $this->tasksByOrderIds($onlyOrderId === null ? null : [$onlyOrderId]);
    }

    /** @param array<int,int> $orderIds @return array<int,array<string,mixed>> */
    public function tasksForOrderIds(array $orderIds): array
    {
        $ids = $this->normalizeOrderIds($orderIds);
        if (!$ids) return [];
        return $this->tasksByOrderIds($ids);
    }

    /** @param array<int,int>|null $orderIds @return array<int,array<string,mixed>> */
    private function tasksByOrderIds(?array $orderIds): array
    {
        $tasks = [];
        $filter = $this->orderFilter($orderIds);

        $rows = $this->db->fetchAll(
            "SELECT o.id order_id,o.public_no order_public_no,o.version order_version,o.created_at task_created_at,o.title
             FROM orders o
             WHERE o.audit_status='pending'
               AND o.payment_status='paid'
               AND o.lifecycle_status='active'
               AND COALESCE(o.order_source_type,'consumer')<>'platform'
               AND COALESCE(o.refund_status,'none') IN ('','none')" . $filter
        );
        foreach ($rows as $row) {
            $tasks[] = $this->task(
                $row,
                'audit_pending',
                'order',
                (string)$row['order_public_no'],
                '新订单待审核',
                (string)($row['title'] ?: '订单资料等待平台审核'),
                'P2',
                false,
                'decision',
                ['audit_approve','audit_supplement','audit_reject']
            );
        }

        $rows = $this->db->fetchAll(
            "SELECT o.id order_id,o.public_no order_public_no,o.version order_version,
                    COALESCE(qs.selected_at,o.updated_at,o.created_at) task_created_at,q.amount_cents
             FROM orders o
             JOIN quotes q ON q.id=o.selected_quote_id AND q.quote_type='platform'
             LEFT JOIN quote_selections qs ON qs.order_id=o.id AND qs.quote_id=q.id AND qs.status='confirmed'
             WHERE COALESCE(o.workflow_profile,'construction')='construction'
               AND o.audit_status='approved'
               AND o.payment_status='paid'
               AND o.lifecycle_status='active'
               AND COALESCE(o.refund_status,'none') IN ('','none')
               AND NOT EXISTS(SELECT 1 FROM assignments a WHERE a.order_id=o.id AND a.status IN ('offered','accepted','completed'))
               AND NOT EXISTS(SELECT 1 FROM order_cancel_requests cr WHERE cr.order_id=o.id AND cr.status IN ('pending','processing'))
               AND NOT EXISTS(SELECT 1 FROM disputes d WHERE d.order_id=o.id AND d.status IN ('open','processing'))
               AND NOT EXISTS(SELECT 1 FROM order_relations r WHERE r.child_order_id=o.id AND r.relation_type='measurement_construction' AND r.status='active')" . $filter
        );
        foreach ($rows as $row) {
            $tasks[] = $this->task(
                $row,
                'assignment_required',
                'order',
                (string)$row['order_public_no'],
                '平台报价已成交，待安排师傅',
                '预约费已到账，当前没有有效师傅指派',
                'P1',
                true,
                'decision',
                ['create_worker_invite']
            );
        }

        return $tasks;
    }

    /** @param array<int,int> $orderIds @return array<int,int> */
    private function normalizeOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static function (int $id): bool {
            return $id > 0;
        })));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<int,int>|null $orderIds */
    private function orderFilter(?array $orderIds): string
    {
        if ($orderIds === null) return '';
        if (!$orderIds) return ' AND 1=0';
        return ' AND o.id IN (' . implode(',', array_map('intval', $orderIds)) . ')';
    }

    /** @return array<string,mixed> */
    private function task(
        array $row,
        string $type,
        string $sourceType,
        string $sourceId,
        string $title,
        string $summary,
        string $priority,
        bool $blocking,
        string $mode,
        array $allowedActions
    ): array {
        $createdAt = (string)($row['task_created_at'] ?? '');
        $createdTs = $createdAt !== '' ? strtotime($createdAt . ' UTC') : false;
        return [
            '_order_id'=>(int)$row['order_id'],
            'task_id'=>$type . ':' . $sourceId,
            'task_type'=>$type,
            'source_type'=>$sourceType,
            'source_id'=>$sourceId,
            'title'=>$title,
            'summary'=>$summary,
            'priority'=>$priority,
            'tone'=>$priority === 'P0' ? 'danger' : ($priority === 'P1' ? 'warning' : 'info'),
            'created_at'=>$createdAt,
            'waiting_seconds'=>$createdTs === false ? 0 : max(0, time() - $createdTs),
            'blocking'=>$blocking,
            'mode'=>$mode,
            'allowed_actions'=>array_values($allowedActions),
            'order_version'=>(int)($row['order_version'] ?? 0),
        ];
    }
}

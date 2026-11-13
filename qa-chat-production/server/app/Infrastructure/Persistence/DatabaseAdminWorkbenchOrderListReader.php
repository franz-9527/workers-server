<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

/**
 * 管理员工作台订单卡片只读适配器。
 *
 * 待办视图消费已聚合 task groups；全部订单先分页 orders，再只为当前页批量补任务角标。
 * 不负责任务规则，不写订单，不参与权限判断。
 */
final class DatabaseAdminWorkbenchOrderListReader
{
    private Database $db;
    private AdminWorkbenchTaskGroupReader $groups;
    private bool $includeDemo;

    public function __construct(Database $db, AdminWorkbenchTaskGroupReader $groups, bool $includeDemo)
    {
        $this->db = $db;
        $this->groups = $groups;
        $this->includeDemo = $includeDemo;
    }

    public function countAllOrders(): int
    {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM orders' . ($this->includeDemo ? '' : ' WHERE COALESCE(is_demo,0)=0')
        );
    }

    /** @param array<int,array<string,mixed>> $groups */
    public function taskList(string $scope, int $page, int $pageSize, string $keyword, array $groups): array
    {
        $page = max(1, min(100000, $page));
        $pageSize = max(1, min(100, $pageSize));
        $keyword = trim(mb_substr($keyword, 0, 80, 'UTF-8'));
        $orderIds = array_map('intval', array_keys($groups));

        if ($keyword !== '' && $orderIds) {
            $marks = implode(',', array_fill(0, count($orderIds), '?'));
            $searchParams = array_merge($orderIds, [$keyword, $keyword, $keyword, $keyword]);
            $matching = $this->db->fetchAll(
                "SELECT o.id FROM orders o
                 WHERE o.id IN (" . $marks . ")
                   AND (
                     LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.public_no USING utf8mb4) COLLATE utf8mb4_general_ci)>0
                     OR COALESCE(LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.title USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                     OR COALESCE(LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.category_names_snapshot USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                     OR COALESCE(LOCATE(CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.category_name_snapshot USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                   )",
                $searchParams
            );
            $allowed = [];
            foreach ($matching as $row) $allowed[(int)$row['id']] = true;
            $orderIds = array_values(array_filter($orderIds, static function (int $id) use ($allowed): bool {
                return isset($allowed[$id]);
            }));
        }

        $total = count($orderIds);
        $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        if ($totalPages === 0) $page = 1;
        elseif ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;
        $pageIds = array_slice($orderIds, $offset, $pageSize);
        $items = [];

        if ($pageIds) {
            $safeIds = array_map('intval', $pageIds);
            $idSql = implode(',', $safeIds);
            $rows = $this->db->fetchAll(
                "SELECT o.id,o.public_no,o.title,o.workflow_profile,o.order_source_type,
                        o.category_names_snapshot,o.category_name_snapshot,o.city,o.district,
                        o.version,o.created_at,u.nickname user_name,m.business_name merchant_name,
                        w.display_name assigned_worker_name
                 FROM orders o
                 LEFT JOIN users u ON u.id=o.user_id
                 LEFT JOIN merchant_profiles m ON m.user_id=o.user_id
                 LEFT JOIN worker_profiles w ON w.id=o.assigned_worker_id
                 WHERE o.id IN (" . $idSql . ")
                 ORDER BY FIELD(o.id," . $idSql . ")"
            );
            foreach ($rows as $row) {
                $orderId = (int)$row['id'];
                if (!isset($groups[$orderId])) continue;
                $group = $groups[$orderId];
                $primary = $group['tasks'][0] ?? [];
                $operationCount = count(WorkbenchTaskProjection::operationTasks($group['tasks']));
                $exceptionCount = count(WorkbenchTaskProjection::exceptionTasks($group['tasks']));
                $items[] = [
                    'public_no'=>(string)$row['public_no'],
                    'title'=>(string)($row['title'] ?: ''),
                    'workflow_profile'=>(string)($row['workflow_profile'] ?? 'construction'),
                    'workflow_label'=>$this->workflowLabel($row),
                    'party_name'=>(string)(($row['order_source_type'] === 'merchant' && $row['merchant_name']) ? $row['merchant_name'] : $row['user_name']),
                    'category_name'=>(string)($row['category_names_snapshot'] ?: $row['category_name_snapshot']),
                    'city'=>(string)$row['city'],
                    'district'=>(string)$row['district'],
                    'phase_text'=>(string)($primary['title'] ?? '待处理'),
                    'assigned_worker_name'=>(string)($row['assigned_worker_name'] ?? ''),
                    'created_at'=>(string)$row['created_at'],
                    'order_version'=>(int)$row['version'],
                    'task_count'=>(int)$group['task_count'],
                    'operation_count'=>$operationCount,
                    'exception_count'=>$exceptionCount,
                    'highest_priority'=>(string)($primary['priority'] ?? 'P2'),
                    'waiting_seconds'=>(int)$group['waiting_seconds'],
                    'primary_task'=>$primary ?: null,
                    'tasks'=>$group['tasks'],
                    'task_overflow_count'=>0,
                ];
            }
        }

        return $this->result($items, $scope, $keyword, $page, $pageSize, $total, $totalPages);
    }

    public function allOrders(int $page, int $pageSize, string $keyword): array
    {
        $page = max(1, min(100000, $page));
        $pageSize = max(1, min(100, $pageSize));
        $keyword = trim(mb_substr($keyword, 0, 80, 'UTF-8'));
        $where = [];
        $params = [];

        if (!$this->includeDemo) $where[] = 'COALESCE(o.is_demo,0)=0';
        if ($keyword !== '') {
            $where[] = "(
                LOCATE(CONVERT(:q1 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.public_no USING utf8mb4) COLLATE utf8mb4_general_ci)>0
                OR COALESCE(LOCATE(CONVERT(:q2 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.title USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                OR COALESCE(LOCATE(CONVERT(:q3 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.category_names_snapshot USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                OR COALESCE(LOCATE(CONVERT(:q4 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(o.category_name_snapshot USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                OR COALESCE(LOCATE(CONVERT(:q5 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(u.nickname USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                OR COALESCE(LOCATE(CONVERT(:q6 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(m.business_name USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
                OR COALESCE(LOCATE(CONVERT(:q7 USING utf8mb4) COLLATE utf8mb4_general_ci,CONVERT(w.display_name USING utf8mb4) COLLATE utf8mb4_general_ci),0)>0
            )";
            for ($i = 1; $i <= 7; $i++) $params['q' . $i] = $keyword;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $fromSql = " FROM orders o
                     LEFT JOIN users u ON u.id=o.user_id
                     LEFT JOIN merchant_profiles m ON m.user_id=o.user_id
                     LEFT JOIN worker_profiles w ON w.id=o.assigned_worker_id";
        $total = (int)$this->db->fetchValue('SELECT COUNT(*)' . $fromSql . $whereSql, $params);
        $totalPages = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        if ($totalPages === 0) $page = 1;
        elseif ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;

        $rows = $this->db->fetchAll(
            "SELECT o.id,o.public_no,o.title,o.workflow_profile,o.order_source_type,
                    o.category_names_snapshot,o.category_name_snapshot,o.city,o.district,o.version,
                    o.audit_status,o.payment_status,o.publish_status,o.assignment_status,o.fulfillment_status,
                    o.lifecycle_status,o.refund_status,o.created_at,o.updated_at,
                    u.nickname user_name,m.business_name merchant_name,w.display_name assigned_worker_name" .
            $fromSql . $whereSql .
            ' ORDER BY o.updated_at DESC,o.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            $params
        );

        $pageIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int)($row['id'] ?? 0);
        }, $rows), static function (int $id): bool {
            return $id > 0;
        })));
        $groups = $this->groups->groupsForOrderIds($pageIds);

        $items = [];
        foreach ($rows as $row) {
            $orderId = (int)$row['id'];
            $group = $groups[$orderId] ?? null;
            $tasks = $group ? $group['tasks'] : [];
            $primary = $tasks[0] ?? [];
            $operationCount = count(WorkbenchTaskProjection::operationTasks($tasks));
            $exceptionCount = count(WorkbenchTaskProjection::exceptionTasks($tasks));
            $displayTasks = array_slice($tasks, 0, 3);
            $items[] = [
                'public_no'=>(string)$row['public_no'],
                'title'=>(string)($row['title'] ?: ''),
                'workflow_profile'=>(string)($row['workflow_profile'] ?? 'construction'),
                'workflow_label'=>$this->workflowLabel($row),
                'party_name'=>(string)(($row['order_source_type'] === 'merchant' && $row['merchant_name']) ? $row['merchant_name'] : $row['user_name']),
                'category_name'=>(string)($row['category_names_snapshot'] ?: $row['category_name_snapshot']),
                'city'=>(string)$row['city'],
                'district'=>(string)$row['district'],
                'phase_text'=>$this->phaseText($row),
                'assigned_worker_name'=>(string)($row['assigned_worker_name'] ?? ''),
                'created_at'=>(string)$row['created_at'],
                'updated_at'=>(string)$row['updated_at'],
                'order_version'=>(int)$row['version'],
                'task_count'=>count($tasks),
                'operation_count'=>$operationCount,
                'exception_count'=>$exceptionCount,
                'highest_priority'=>(string)($primary['priority'] ?? ''),
                'waiting_seconds'=>$group ? (int)$group['waiting_seconds'] : 0,
                'primary_task'=>$primary ?: null,
                'tasks'=>$displayTasks,
                'task_overflow_count'=>max(0, count($tasks) - count($displayTasks)),
            ];
        }

        return $this->result($items, 'all', $keyword, $page, $pageSize, $total, $totalPages);
    }

    /** @param array<int,array<string,mixed>> $items */
    private function result(array $items, string $scope, string $keyword, int $page, int $pageSize, int $total, int $totalPages): array
    {
        return [
            'items'=>$items,
            'scope'=>$scope,
            'search'=>$keyword,
            'pagination'=>[
                'page'=>$page,
                'per_page'=>$pageSize,
                'total'=>$total,
                'total_pages'=>$totalPages,
                'has_more'=>$page < $totalPages,
            ],
        ];
    }

    /** @param array<string,mixed> $row */
    private function workflowLabel(array $row): string
    {
        $measurement = (string)($row['workflow_profile'] ?? 'construction') === 'measurement';
        if ((string)($row['order_source_type'] ?? '') === 'platform') return $measurement ? '平台测量' : '平台施工';
        return $measurement ? '测量' : '施工';
    }

    /** @param array<string,mixed> $order */
    private function phaseText(array $order): string
    {
        if ((string)($order['refund_status'] ?? '') === 'succeeded') return '已退款';
        if (in_array((string)($order['refund_status'] ?? ''), ['pending_review','approved','processing'], true)) return '退款处理中';
        if ((string)($order['lifecycle_status'] ?? '') === 'cancelled') return '已取消';
        if ((string)($order['lifecycle_status'] ?? '') === 'completed' || (string)($order['fulfillment_status'] ?? '') === 'accepted') return '已完成';
        if ((string)($order['fulfillment_status'] ?? '') === 'submitted') return '待验收';
        if ((string)($order['fulfillment_status'] ?? '') === 'in_progress') return '履约中';
        if ((string)($order['fulfillment_status'] ?? '') === 'scheduled') return '已预约';
        if ((string)($order['assignment_status'] ?? '') === 'offered') return '待师傅接单';
        if ((string)($order['assignment_status'] ?? '') === 'accepted') return '已安排师傅';
        if ((string)($order['audit_status'] ?? '') === 'supplement_required') return '等待补资料';
        if ((string)($order['audit_status'] ?? '') === 'pending') return '待审核';
        if ((string)($order['payment_status'] ?? '') === 'awaiting_payment') return '待付款';
        if ((string)($order['audit_status'] ?? '') === 'approved' && (string)($order['publish_status'] ?? '') === 'published') return '报价/接单中';
        if ((string)($order['audit_status'] ?? '') === 'approved') return '已审核';
        return '订单处理中';
    }
}

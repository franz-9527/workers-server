<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Contracts\AdminWorkbenchTaskRepository;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

/**
 * 管理员订单工作台 Contract 的数据库适配器。
 *
 * 这里只保留 Contract 聚合与专项内部 ID 投影：
 * - task 规则/聚合交给 AdminWorkbenchTaskGroupReader；该读取器统一组合
 *   OperationalOrderWorkbenchTaskSource / PlatformOrderWorkbenchTaskSource / OrderExceptionWorkbenchTaskSource；
 * - 列表/全部订单卡片交给 DatabaseAdminWorkbenchOrderListReader；
 *   分页、当前页批量任务角标和溢出计数由专用读取器负责；
 * - 本类不再继续增长成新的“大仓储”。
 */
final class DatabaseAdminWorkbenchTaskRepository implements AdminWorkbenchTaskRepository
{
    private Database $db;
    private AdminWorkbenchTaskGroupReader $groups;
    private DatabaseAdminWorkbenchOrderListReader $lists;

    public function __construct(Database $db, ?bool $includeDemo = null)
    {
        $this->db = $db;
        $includeDemo = $includeDemo !== null
            ? $includeDemo
            : (function_exists('setting') ? (bool)\setting('demo.data_enabled', false) : false);
        $this->groups = new AdminWorkbenchTaskGroupReader($db, $includeDemo);
        $this->lists = new DatabaseAdminWorkbenchOrderListReader($db, $this->groups, $includeDemo);
    }

    public function summary(): array
    {
        $counts = WorkbenchTaskProjection::summary($this->groups->groups(null));
        $counts['all_orders'] = $this->lists->countAllOrders();
        return $counts;
    }

    public function groupsForScope(string $scope): array
    {
        return $this->groups->groupsForScope($scope);
    }

    public function tasksForOrder(int $orderId): array
    {
        return $this->groups->tasksForOrder($orderId);
    }

    public function exceptionTasksForOrder(int $orderId): array
    {
        return WorkbenchTaskProjection::exceptionTasks($this->tasksForOrder($orderId));
    }

    public function orderProjection(string $publicNo): ?array
    {
        $order = $this->db->fetch(
            "SELECT id,public_no,version FROM orders WHERE BINARY public_no=BINARY :public_no LIMIT 1",
            ['public_no'=>$publicNo]
        );
        return $order ? $this->projectionFromOrder($order) : null;
    }

    public function orderProjectionById(int $orderId): ?array
    {
        if ($orderId < 1) return null;
        $order = $this->db->fetch(
            'SELECT id,public_no,version FROM orders WHERE id=:id LIMIT 1',
            ['id'=>$orderId]
        );
        return $order ? $this->projectionFromOrder($order) : null;
    }

    public function disputeProjectionById(int $disputeId): ?array
    {
        if ($disputeId < 1) return null;
        $row = $this->db->fetch(
            "SELECT d.id dispute_internal_id,d.public_no dispute_id,o.id order_internal_id,o.public_no order_id,o.version
             FROM disputes d
             JOIN orders o ON o.id=d.order_id
             WHERE d.id=:id
             LIMIT 1",
            ['id'=>$disputeId]
        );
        if (!$row) return null;
        $projection = $this->projectionFromOrder([
            'id'=>$row['order_internal_id'],
            'public_no'=>$row['order_id'],
            'version'=>$row['version'],
        ]);
        $task = null;
        foreach (($projection['tasks'] ?? []) as $candidate) {
            if ((string)($candidate['task_type'] ?? '') === 'dispute_open'
                && (string)($candidate['source_id'] ?? '') === (string)$row['dispute_id']) {
                $task = $candidate;
                break;
            }
        }
        if (!$task) return null;
        return [
            'order_id'=>(string)$row['order_id'],
            'order_version'=>(int)$row['version'],
            'dispute_internal_id'=>(int)$row['dispute_internal_id'],
            'dispute_id'=>(string)$row['dispute_id'],
            'task'=>$task,
        ];
    }

    public function contactRevealProjectionById(int $requestId): ?array
    {
        if ($requestId < 1) return null;
        $row = $this->db->fetch(
            "SELECT r.id request_internal_id,r.public_no request_id,o.id order_internal_id,o.public_no order_id,o.version
             FROM order_worker_contact_reveal_requests r
             JOIN orders o ON o.id=r.order_id
             WHERE r.id=:id
             LIMIT 1",
            ['id'=>$requestId]
        );
        if (!$row) return null;
        $projection = $this->projectionFromOrder([
            'id'=>$row['order_internal_id'],
            'public_no'=>$row['order_id'],
            'version'=>$row['version'],
        ]);
        foreach (($projection['tasks'] ?? []) as $task) {
            if ((string)($task['task_type'] ?? '') === 'contact_reveal_review'
                && (string)($task['source_id'] ?? '') === (string)$row['request_id']) {
                return [
                    'order_id'=>(string)$row['order_id'],
                    'order_version'=>(int)$row['version'],
                    'request_internal_id'=>(int)$row['request_internal_id'],
                    'request_id'=>(string)$row['request_id'],
                    'task'=>$task,
                ];
            }
        }
        return null;
    }

    public function list(string $scope, int $page, int $pageSize, string $keyword): array
    {
        return $this->lists->taskList(
            $scope,
            $page,
            $pageSize,
            $keyword,
            $this->groupsForScope($scope)
        );
    }

    public function allOrders(int $page, int $pageSize, string $keyword): array
    {
        return $this->lists->allOrders($page, $pageSize, $keyword);
    }

    /** @param array<string,mixed> $order @return array<string,mixed> */
    private function projectionFromOrder(array $order): array
    {
        $tasks = $this->tasksForOrder((int)$order['id']);
        $operationTasks = WorkbenchTaskProjection::operationTasks($tasks);
        $exceptionTasks = WorkbenchTaskProjection::exceptionTasks($tasks);
        return [
            'order_id'=>(string)$order['public_no'],
            'order_version'=>(int)$order['version'],
            'tasks'=>$tasks,
            'operation_tasks'=>$operationTasks,
            'exception_tasks'=>$exceptionTasks,
            'task_count'=>count($tasks),
            'operation_count'=>count($operationTasks),
            'exception_count'=>count($exceptionTasks),
        ];
    }
}

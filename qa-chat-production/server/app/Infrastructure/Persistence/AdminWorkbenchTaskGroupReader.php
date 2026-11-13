<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Admin\WorkbenchTaskProjection;

/** 管理员工作台任务聚合读取器；任务均从现有业务事实实时派生。 */
final class AdminWorkbenchTaskGroupReader
{
    private Database $db;
    private OperationalOrderWorkbenchTaskSource $operationalSource;
    private PlatformOrderWorkbenchTaskSource $platformSource;
    private OrderExceptionWorkbenchTaskSource $exceptionSource;
    private AdminFollowupWorkbenchTaskSource $followupSource;
    private bool $includeDemo;

    public function __construct(Database $db, bool $includeDemo)
    {
        $this->db = $db;
        $this->operationalSource = new OperationalOrderWorkbenchTaskSource($db);
        $this->platformSource = new PlatformOrderWorkbenchTaskSource($db);
        $this->exceptionSource = new OrderExceptionWorkbenchTaskSource($db);
        $this->followupSource = new AdminFollowupWorkbenchTaskSource($db);
        $this->includeDemo = $includeDemo;
    }

    public function groups(?int $onlyOrderId = null): array
    {
        if ($onlyOrderId !== null) return $this->groupsForOrderIds([$onlyOrderId]);
        return $this->groupTasks(array_merge(
            $this->operationalSource->tasks(null),
            $this->platformSource->tasks(null),
            $this->exceptionSource->tasks(null),
            $this->followupSource->tasks(null)
        ));
    }

    public function groupsForOrderIds(array $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$ids) return [];

        return $this->groupTasks(array_merge(
            $this->operationalSource->tasksForOrderIds($ids),
            $this->platformSource->tasksForOrderIds($ids),
            $this->exceptionSource->tasksForOrderIds($ids),
            $this->followupSource->tasksForOrderIds($ids)
        ));
    }

    public function groupsForScope(string $scope): array
    {
        $filtered = [];
        foreach ($this->groups(null) as $orderId => $group) {
            $tasks = array_values(array_filter($group['tasks'], static function (array $task) use ($scope): bool {
                return WorkbenchTaskProjection::matchesScope($task, $scope);
            }));
            if (!$tasks) continue;
            $filtered[$orderId] = $this->finalizeGroup($tasks);
        }

        uasort($filtered, static function (array $left, array $right): int {
            if ($left['priority_rank'] !== $right['priority_rank']) return $left['priority_rank'] <=> $right['priority_rank'];
            if ($left['oldest_created_at'] !== $right['oldest_created_at']) return strcmp($left['oldest_created_at'], $right['oldest_created_at']);
            return $right['order_id'] <=> $left['order_id'];
        });
        return $filtered;
    }

    public function tasksForOrder(int $orderId): array
    {
        if ($orderId < 1) return [];
        $groups = $this->groups($orderId);
        return isset($groups[$orderId]) ? $groups[$orderId]['tasks'] : [];
    }

    private function groupTasks(array $tasks): array
    {
        if (!$this->includeDemo && $tasks) {
            $orderIds = array_values(array_unique(array_filter(array_map(static function (array $task): int {
                return (int)($task['_order_id'] ?? 0);
            }, $tasks), static function (int $id): bool {
                return $id > 0;
            })));
            if ($orderIds) {
                $marks = implode(',', array_fill(0, count($orderIds), '?'));
                $demoRows = $this->db->fetchAll("SELECT id FROM orders WHERE id IN (".$marks.") AND COALESCE(is_demo,0)=1", $orderIds);
                $demoIds = [];
                foreach ($demoRows as $row) $demoIds[(int)$row['id']] = true;
                if ($demoIds) {
                    $tasks = array_values(array_filter($tasks, static function (array $task) use ($demoIds): bool {
                        return !isset($demoIds[(int)($task['_order_id'] ?? 0)]);
                    }));
                }
            }
        }

        $groups = [];
        foreach ($tasks as $task) {
            $orderId = (int)($task['_order_id'] ?? 0);
            if ($orderId < 1) continue;
            if (!isset($groups[$orderId])) $groups[$orderId] = [];
            $groups[$orderId][] = $task;
        }
        foreach ($groups as $orderId => $orderTasks) $groups[$orderId] = $this->finalizeGroup($orderTasks);
        return $groups;
    }

    private function finalizeGroup(array $tasks): array
    {
        usort($tasks, static function (array $left, array $right): int {
            $rank = ['P0'=>0,'P1'=>1,'P2'=>2];
            $leftRank = $rank[$left['priority'] ?? ''] ?? 9;
            $rightRank = $rank[$right['priority'] ?? ''] ?? 9;
            if ($leftRank !== $rightRank) return $leftRank <=> $rightRank;
            $dateCompare = strcmp((string)($left['created_at'] ?? ''), (string)($right['created_at'] ?? ''));
            if ($dateCompare !== 0) return $dateCompare;
            return strcmp((string)($left['task_id'] ?? ''), (string)($right['task_id'] ?? ''));
        });

        $orderId = (int)($tasks[0]['_order_id'] ?? 0);
        $publicTasks = [];
        foreach ($tasks as $task) {
            unset($task['_order_id']);
            $task['task_kind'] = WorkbenchTaskProjection::taskKind($task);
            $publicTasks[] = $task;
        }
        $rank = ['P0'=>0,'P1'=>1,'P2'=>2];
        $created = array_values(array_filter(array_map(static function (array $task): string {
            return trim((string)($task['created_at'] ?? ''));
        }, $publicTasks), static function (string $value): bool { return $value !== ''; }));

        return [
            'order_id'=>$orderId,'tasks'=>$publicTasks,'task_count'=>count($publicTasks),
            'priority_rank'=>$rank[$publicTasks[0]['priority'] ?? ''] ?? 9,
            'oldest_created_at'=>$created ? (string)min($created) : '',
            'waiting_seconds'=>$publicTasks ? max(array_map(static function (array $task): int {
                return (int)($task['waiting_seconds'] ?? 0);
            }, $publicTasks)) : 0,
        ];
    }
}

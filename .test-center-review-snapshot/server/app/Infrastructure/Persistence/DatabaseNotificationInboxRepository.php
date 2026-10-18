<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\NotificationInboxRepository;
use WorkerAdmin\Domain\Messaging\MessageOperationException;

final class DatabaseNotificationInboxRepository implements NotificationInboxRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function summary(array $actor): array
    {
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        $unreadCount = (int) $this->db->fetchValue("SELECT COUNT(*) FROM notifications n WHERE n.channel='in_app' AND n.read_at IS NULL AND ((n.recipient_type='user' AND n.recipient_id=:user_id) OR (n.recipient_type='worker' AND :worker_enabled>0 AND n.recipient_id=:worker_id))", [
            'user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId,
        ]);

        $userWhere = "o.user_id=:user_id AND o.lifecycle_status='active' AND o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND (o.audit_status='supplement_required' OR (o.audit_status='draft' AND o.payment_status='awaiting_payment') OR (o.fulfillment_status='submitted' AND o.dispute_status NOT IN ('open','processing')) OR (o.audit_status='approved' AND o.selected_quote_id IS NULL AND EXISTS(SELECT 1 FROM quotes q WHERE q.order_id=o.id AND q.status='active')) OR EXISTS(SELECT 1 FROM change_requests c WHERE c.order_id=o.id AND (c.status='pending' OR (c.status='accepted' AND c.payment_status='awaiting_payment'))) OR EXISTS(SELECT 1 FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment')))";
        $userParams = ['user_id' => (int) $actor['user_id']];
        $userTodoCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders o WHERE ' . $userWhere, $userParams);
        $userTop = $this->db->fetch("SELECT o.public_no,o.audit_status,o.payment_status,o.fulfillment_status,(SELECT c.public_id FROM change_requests c WHERE c.order_id=o.id AND (c.status='pending' OR (c.status='accepted' AND c.payment_status='awaiting_payment')) ORDER BY c.id DESC LIMIT 1) pending_change_id,(SELECT rc.status FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment') ORDER BY rc.id DESC LIMIT 1) pending_run_empty_status,CASE WHEN o.audit_status='draft' AND o.payment_status='awaiting_payment' THEN 95 WHEN EXISTS(SELECT 1 FROM run_empty_fee_claims rp WHERE rp.order_id=o.id AND rp.status='awaiting_payment') THEN 95 WHEN o.audit_status='supplement_required' THEN 85 WHEN EXISTS(SELECT 1 FROM change_requests cp WHERE cp.order_id=o.id AND (cp.status='pending' OR (cp.status='accepted' AND cp.payment_status='awaiting_payment'))) THEN 80 WHEN EXISTS(SELECT 1 FROM run_empty_fee_claims rc2 WHERE rc2.order_id=o.id AND rc2.status='pending_confirmation') THEN 80 WHEN o.fulfillment_status='submitted' THEN 75 ELSE 70 END todo_priority FROM orders o WHERE " . $userWhere . " ORDER BY todo_priority DESC,o.updated_at DESC,o.id DESC LIMIT 1", $userParams);
        $userTodo = null;
        if ($userTop) {
            if ($userTop['audit_status'] === 'supplement_required') $action = '补充订单资料';
            elseif ($userTop['audit_status'] === 'draft' && $userTop['payment_status'] === 'awaiting_payment') $action = '支付预约费';
            elseif (!empty($userTop['pending_change_id'])) $action = '处理费用调整';
            elseif (($userTop['pending_run_empty_status'] ?? '') === 'pending_confirmation') $action = '确认跑空费';
            elseif (($userTop['pending_run_empty_status'] ?? '') === 'awaiting_payment') $action = '支付跑空费';
            elseif ($userTop['fulfillment_status'] === 'submitted') $action = '确认完工';
            else $action = '选择报价';
            $userTodo = ['order_id' => (string) $userTop['public_no'], 'action' => $action, 'role' => '我的下单', 'detail_page' => 'user', 'priority' => (int) $userTop['todo_priority']];
        }

        $workerTodoCount = 0;
        $workerTodo = null;
        if ($workerId > 0) {
            $workerWhere = "o.refund_status NOT IN ('pending_review','approved','processing','refunding') AND (((o.lifecycle_status='active') AND o.current_assignment_id IS NOT NULL AND EXISTS(SELECT 1 FROM assignments atodo WHERE atodo.id=o.current_assignment_id AND atodo.worker_id=:todo_worker_id AND atodo.status='offered')) OR (o.assigned_worker_id=:todo_repair_worker_id AND EXISTS(SELECT 1 FROM after_sales astodo WHERE astodo.order_id=o.id AND astodo.type='warranty_repair' AND (astodo.status='open' OR (astodo.status='processing' AND (astodo.resolution IS NULL OR (astodo.resolution NOT LIKE '师傅拒绝维修%' AND astodo.resolution NOT LIKE '用户申请平台介入%')))))))";
            $workerParams = ['todo_worker_id' => $workerId, 'todo_repair_worker_id' => $workerId];
            $workerTodoCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders o WHERE ' . $workerWhere, $workerParams);
            $workerTop = $this->db->fetch("SELECT o.public_no,o.assignment_status,(SELECT COUNT(*) FROM after_sales ast WHERE ast.order_id=o.id AND ast.type='warranty_repair' AND (ast.status='open' OR (ast.status='processing' AND (ast.resolution IS NULL OR (ast.resolution NOT LIKE '师傅拒绝维修%' AND ast.resolution NOT LIKE '用户申请平台介入%'))))) pending_repair_count FROM orders o WHERE " . $workerWhere . " ORDER BY pending_repair_count DESC,o.updated_at DESC,o.id DESC LIMIT 1", $workerParams);
            if ($workerTop) {
                $repair = (int) ($workerTop['pending_repair_count'] ?? 0) > 0;
                $workerTodo = ['order_id' => (string) $workerTop['public_no'], 'action' => $repair ? '处理待维修' : '确认接单', 'role' => '我的接单', 'detail_page' => 'worker', 'priority' => $repair ? 100 : 90];
            }
        }

        $topTodo = $userTodo;
        if ($workerTodo && (!$topTodo || (int) $workerTodo['priority'] > (int) $topTodo['priority'])) $topTodo = $workerTodo;
        return [
            'unread_count' => $unreadCount,
            'todo_count' => $userTodoCount + $workerTodoCount,
            'user_todo_count' => $userTodoCount,
            'worker_todo_count' => $workerTodoCount,
            'top_todo' => $topTodo,
        ];

    }

    public function list(array $actor, int $page): array
    {
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        $where = "n.channel='in_app' AND ( (n.recipient_type='user' AND n.recipient_id=:user_id) OR (n.recipient_type='worker' AND :worker_enabled>0 AND n.recipient_id=:worker_id) )";
        $params = ['user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId];
        $unreadCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications n WHERE ' . $where . ' AND n.read_at IS NULL', $params);
        $rows = $this->db->fetchAll('SELECT n.public_id,n.recipient_type,n.template_key,n.title,n.content,n.data_json,n.read_at,n.created_at FROM notifications n WHERE ' . $where . ' ORDER BY n.created_at DESC,n.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $params);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        // V2.2.3：先解析本页关联订单，再一次性批量取订单号，避免每条通知额外执行一次 SQL。
        $preparedRows = []; $orderIds = [];
        foreach ($rows as $row) {
            $data = (json_decode((string)($row['data_json'] ?? ''), true) ?: []);
            $orderId = is_array($data) ? (int) ($data['order_id'] ?? 0) : 0;
            if ($orderId > 0) $orderIds[$orderId] = true;
            $preparedRows[] = ['row' => $row, 'order_id' => $orderId];
        }
        $orderNoById = [];
        if ($orderIds) {
            $safeIds = array_map('intval', array_keys($orderIds));
            foreach ($this->db->fetchAll('SELECT id,public_no FROM orders WHERE id IN (' . implode(',', $safeIds) . ')') as $orderRow) {
                $orderNoById[(int) $orderRow['id']] = (string) $orderRow['public_no'];
            }
        }
        $items = [];
        foreach ($preparedRows as $prepared) {
            $row = $prepared['row']; $orderId = (int) $prepared['order_id'];
            $items[] = [
                'id' => (string) $row['public_id'], 'recipient_type' => (string) $row['recipient_type'],
                'template_key' => (string) $row['template_key'], 'title' => (string) $row['title'],
                'content' => (string) $row['content'], 'read' => !empty($row['read_at']),
                'created_at' => $this->localTime($row['created_at']), 'order_id' => $orderId,
                'order_no' => $orderNoById[$orderId] ?? '', 'detail_page' => $row['recipient_type'] === 'worker' ? 'worker' : 'user',
            ];
        }
        return ['items' => $items, 'page' => $page, 'has_more' => $hasMore, 'unread_count' => $unreadCount];

    }

    public function read(array $actor, string $publicId): void
    {
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        $params = ['public_id' => $publicId, 'user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId];
        $owned = $this->db->fetchValue("SELECT id FROM notifications WHERE public_id=:public_id AND channel='in_app' AND ((recipient_type='user' AND recipient_id=:user_id) OR (recipient_type='worker' AND :worker_enabled>0 AND recipient_id=:worker_id)) LIMIT 1", $params);
        if (!$owned) throw new MessageOperationException('消息不存在或不属于当前账号', 'NOTIFICATION_NOT_FOUND', 404);
        $this->db->execute("UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE id=:id", ['id' => (int) $owned]);

    }

    public function readBatch(array $actor, array $ids): int
    {
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        $marks = [];
        $params = ['user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId];
        foreach ($ids as $index => $publicId) {
            $key = 'notification_' . $index;
            $marks[] = ':' . $key;
            $params[$key] = $publicId;
        }
        $changed = $this->db->execute("UPDATE notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP()) WHERE public_id IN (" . implode(',', $marks) . ") AND channel='in_app' AND ((recipient_type='user' AND recipient_id=:user_id) OR (recipient_type='worker' AND :worker_enabled>0 AND recipient_id=:worker_id))", $params);
        return $changed;

    }

    public function readAll(array $actor): void
    {
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        $this->db->execute("UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE channel='in_app' AND read_at IS NULL AND ((recipient_type='user' AND recipient_id=:user_id) OR (recipient_type='worker' AND :worker_enabled>0 AND recipient_id=:worker_id))", ['user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId]);

    }

    private function localTime($value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string) \app_config('app.timezone', 'Asia/Shanghai')))->format('Y-m-d H:i');
        } catch (Throwable $exception) { return (string) $value; }
    }
}

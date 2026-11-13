<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\NotificationInboxRepository;
use WorkerAdmin\Domain\Messaging\MessageOperationException;

final class DatabaseNotificationInboxRepository implements NotificationInboxRepository, \WorkerAdmin\Contracts\NotificationThreadRepository
{
    private Database $db;
    public function __construct(Database $db) { $this->db = $db; }
    public function summary(array $actor): array
    {
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        // One bounded projection for the normal-notification conversation; never load bodies.
        $latest = $this->db->fetch("SELECT n.public_id,n.title,n.created_at FROM notifications n WHERE n.channel='in_app' AND ((n.recipient_type='user' AND n.recipient_id=:user_id) OR (n.recipient_type='worker' AND :worker_enabled>0 AND n.recipient_id=:worker_id)) ORDER BY n.created_at DESC,n.id DESC LIMIT 1", [
            'user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId,
        ]);
        $unreadCount = (int) $this->db->fetchValue("SELECT COUNT(*) FROM notifications n WHERE n.channel='in_app' AND n.read_at IS NULL AND ((n.recipient_type='user' AND n.recipient_id=:user_id) OR (n.recipient_type='worker' AND :worker_enabled>0 AND n.recipient_id=:worker_id))", [
            'user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId,
        ]);

        [$userWhere,$userParams] = DatabaseOrderCenterRepository::scope($actor,'mine','todo');
        $userTodoCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders o WHERE ' . $userWhere, $userParams);
        $userTop = $this->db->fetch("SELECT o.public_no,o.audit_status,o.payment_status,o.fulfillment_status,(o.order_source_type='platform' AND EXISTS(SELECT 1 FROM order_events apr WHERE apr.order_id=o.id AND apr.event_type='platform_measurement_pricing_reviewed') AND NOT EXISTS(SELECT 1 FROM order_events apc WHERE apc.order_id=o.id AND apc.event_type='platform_measurement_pricing_confirmed')) pending_pricing,(SELECT c.public_id FROM change_requests c WHERE c.order_id=o.id AND (c.status='pending' OR (c.status='accepted' AND c.payment_status='awaiting_payment')) ORDER BY c.id DESC LIMIT 1) pending_change_id,(SELECT rc.status FROM run_empty_fee_claims rc WHERE rc.order_id=o.id AND rc.status IN ('pending_confirmation','awaiting_payment') ORDER BY rc.id DESC LIMIT 1) pending_run_empty_status,CASE WHEN o.audit_status='draft' AND o.payment_status='awaiting_payment' THEN 95 WHEN EXISTS(SELECT 1 FROM run_empty_fee_claims rp WHERE rp.order_id=o.id AND rp.status='awaiting_payment') THEN 95 WHEN o.audit_status='supplement_required' THEN 85 WHEN EXISTS(SELECT 1 FROM change_requests cp WHERE cp.order_id=o.id AND (cp.status='pending' OR (cp.status='accepted' AND cp.payment_status='awaiting_payment'))) THEN 80 WHEN EXISTS(SELECT 1 FROM run_empty_fee_claims rc2 WHERE rc2.order_id=o.id AND rc2.status='pending_confirmation') THEN 80 WHEN o.fulfillment_status='submitted' THEN 75 ELSE 70 END todo_priority FROM orders o WHERE " . $userWhere . " ORDER BY todo_priority DESC,o.updated_at DESC,o.id DESC LIMIT 1", $userParams);
        $userTodo = null;
        if ($userTop) {
            if ($userTop['audit_status'] === 'supplement_required') $action = '补充订单资料';
            elseif ($userTop['audit_status'] === 'draft' && $userTop['payment_status'] === 'awaiting_payment') $action = '支付预约费';
            elseif (!empty($userTop['pending_pricing'])) $action = '确认平台报价';
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
            [$workerWhere,$workerParams] = DatabaseOrderCenterRepository::scope($actor,'worker','todo');
            $workerTodoCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM orders o WHERE ' . $workerWhere, $workerParams);
            $workerTop = $this->db->fetch("SELECT o.public_no,o.assignment_status,o.dispatch_mode,(SELECT COUNT(*) FROM after_sales ast WHERE ast.order_id=o.id AND ast.type='warranty_repair' AND (ast.status='open' OR (ast.status='processing' AND (ast.resolution IS NULL OR (ast.resolution NOT LIKE '师傅拒绝维修%' AND ast.resolution NOT LIKE '用户申请平台介入%'))))) pending_repair_count FROM orders o WHERE " . $workerWhere . " ORDER BY pending_repair_count DESC,o.updated_at DESC,o.id DESC LIMIT 1", $workerParams);
            if ($workerTop) {
                $repair = (int) ($workerTop['pending_repair_count'] ?? 0) > 0;
                $workerTodo = ['order_id' => (string) $workerTop['public_no'], 'action' => $repair ? '处理待维修' : ($workerTop['assignment_status']==='accepted'?'预约施工':($workerTop['dispatch_mode']==='multi_quote'?'提交报价':'确认接单')), 'role' => '我的接单', 'detail_page' => 'worker', 'priority' => $repair ? 100 : 90];
            }
        }

        $topTodo = $userTodo;
        if ($workerTodo && (!$topTodo || (int) $workerTodo['priority'] > (int) $topTodo['priority'])) $topTodo = $workerTodo;
        $attention = $this->attention($actor);
        return [
            'attention_schema' => 1,
            'order_attention_count' => $attention['order_attention_count'],
            'standalone_unread_count' => $attention['standalone_unread_count'],
            'unread_count' => $unreadCount,
            'todo_count' => $userTodoCount + $workerTodoCount,
            'user_todo_count' => $userTodoCount,
            'worker_todo_count' => $workerTodoCount,
            'top_todo' => $topTodo,
            'latest_notification' => $latest ? [
                'id' => (string) $latest['public_id'],
                'title' => (string) $latest['title'],
                'created_at' => (new DateTimeImmutable((string) $latest['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM),
            ] : null,
        ];

    }


    private function attention(array $actor): array
    {
        [$userTodo,$userParams] = DatabaseOrderCenterRepository::scope($actor,'mine','todo');
        [$workerTodo,$workerParams] = DatabaseOrderCenterRepository::scope($actor,'worker','todo');
        [$userRead,$userReadParams] = DatabaseOrderCenterRepository::scope($actor,'mine','all');
        [$workerRead,$workerReadParams] = DatabaseOrderCenterRepository::scope($actor,'worker','all');
        $workerId=(int)($actor['worker_id']??0);
        // Only a canonical internal order ID and the current receiver's order
        // scope can merge a notice into B. Invalid/missing/unowned links stay S.
        $orderId="JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(n.data_json),n.data_json,'{}'),'$.order_id'))";
        $orderType="JSON_TYPE(JSON_EXTRACT(IF(JSON_VALID(n.data_json),n.data_json,'{}'),'$.order_id'))";
        $match="{$orderType} IN ('INTEGER','STRING') AND {$orderId} REGEXP '^[1-9][0-9]{0,19}$' AND o.id=CAST({$orderId} AS UNSIGNED) AND BINARY CAST(o.id AS CHAR)=BINARY {$orderId}
            AND ((n.recipient_type='user' AND ({$userRead})) OR (n.recipient_type='worker' AND ({$workerRead})))";
        $owned="n.channel='in_app' AND n.read_at IS NULL AND ((n.recipient_type='user' AND n.recipient_id=:attention_user) OR (n.recipient_type='worker' AND :attention_worker_enabled>0 AND n.recipient_id=:attention_worker))";
        // UNION deduplicates identity+order across todos and any number of
        // order notices. Kind separates unrelated notification IDs from orders.
        $sql="SELECT COALESCE(SUM(kind='order'),0) order_attention_count,COALESCE(SUM(kind='standalone'),0) standalone_unread_count FROM (
            SELECT 'order' kind,'user' identity_kind,o.id item_id FROM orders o WHERE {$userTodo}
            UNION SELECT 'order','worker',o.id FROM orders o WHERE {$workerTodo}
            UNION SELECT IF(o.id IS NULL,'standalone','order'),n.recipient_type,IF(o.id IS NULL,n.id,o.id)
                FROM notifications n LEFT JOIN orders o ON {$match} WHERE {$owned}
        ) attention_items";
        $params=$userParams+$workerParams+$userReadParams+$workerReadParams+['attention_user'=>(int)$actor['user_id'],'attention_worker_enabled'=>$workerId,'attention_worker'=>$workerId];
        $row=$this->db->fetch($sql,$params);
        return ['order_attention_count'=>(int)$row['order_attention_count'],'standalone_unread_count'=>(int)$row['standalone_unread_count']];
    }

    public function list(array $actor, int $page, string $throughId = ''): array
    {
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $workerId = !empty($actor['worker_id']) ? (int) $actor['worker_id'] : 0;
        $where = "n.channel='in_app' AND ( (n.recipient_type='user' AND n.recipient_id=:user_id) OR (n.recipient_type='worker' AND :worker_enabled>0 AND n.recipient_id=:worker_id) )";
        $params = ['user_id' => (int) $actor['user_id'], 'worker_enabled' => $workerId, 'worker_id' => $workerId];
        // Capture the range before loading the list, not after it. Later pages
        // retain the same owned public ID so arrivals cannot shift offsets.
        $boundary=$this->db->fetch('SELECT n.id,n.public_id FROM notifications n WHERE '.$where.($throughId!==''?' AND n.public_id=:through_id':'').' ORDER BY n.id DESC LIMIT 1',$throughId!==''?$params+['through_id'=>$throughId]:$params);
        if($throughId!==''&&!$boundary)throw new MessageOperationException('通知范围已失效，请刷新后重试','NOTIFICATION_RANGE_INVALID',404);
        $throughId=$boundary?(string)$boundary['public_id']:'';
        $unreadCount = (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications n WHERE ' . $where . ' AND n.read_at IS NULL', $params);
        $rows = $this->db->fetchAll('SELECT n.public_id,n.recipient_type,n.template_key,n.title,n.content,n.data_json,n.read_at,n.created_at FROM notifications n WHERE ' . $where . ' AND n.id<=:range_cutoff ORDER BY n.created_at DESC,n.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, $params+['range_cutoff'=>$boundary?(int)$boundary['id']:0]);
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
        return ['items' => $items, 'page' => $page, 'has_more' => $hasMore, 'unread_count' => $unreadCount, 'read_through_id'=>$throughId];

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

    public function readAll(array $actor, string $throughId = ''): void
    {
        $workerId=!empty($actor['worker_id'])?(int)$actor['worker_id']:0;
        $params=['user_id'=>(int)$actor['user_id'],'worker_enabled'=>$workerId,'worker_id'=>$workerId];
        $where="channel='in_app' AND ((recipient_type='user' AND recipient_id=:user_id) OR (recipient_type='worker' AND :worker_enabled>0 AND recipient_id=:worker_id))";
        if($throughId!==''){
            $cutoff=$this->db->fetchValue('SELECT id FROM notifications WHERE '.$where.' AND public_id=:through_id LIMIT 1',$params+['through_id'=>$throughId]);
            if(!$cutoff)throw new MessageOperationException('通知范围已失效，请刷新后重试','NOTIFICATION_RANGE_INVALID',404);
        }else{
            // Old clients also receive a server-side cutoff before the UPDATE.
            $cutoff=$this->db->fetchValue('SELECT COALESCE(MAX(id),0) FROM notifications WHERE '.$where,$params);
        }
        $this->db->execute('UPDATE notifications SET read_at=UTC_TIMESTAMP() WHERE '.$where.' AND read_at IS NULL AND id<=:cutoff',$params+['cutoff'=>(int)$cutoff]);
    }


    private function threadExpression(): string
    {
        $json="IF(JSON_VALID(n.data_json),n.data_json,'{}')";
        $id="JSON_UNQUOTE(JSON_EXTRACT({$json},'$.order_id'))";
        $type="JSON_TYPE(JSON_EXTRACT({$json},'$.order_id'))";
        // Real recipient/public-ID columns are ascii_bin; JSON extraction is utf8mb4_bin.
        // Normalize every CONCAT operand explicitly, without changing persisted columns.
        // Binary keys also keep differently cased standalone public IDs distinct.
        // Identity remains part of the key. An association is never an order access grant.
        return "CASE WHEN {$type} IN ('INTEGER','STRING') AND {$id} REGEXP '^[1-9][0-9]{0,19}$'
            AND BINARY CAST(CAST({$id} AS UNSIGNED) AS CHAR)=BINARY {$id}
            THEN CONCAT(CONVERT(n.recipient_type USING utf8mb4) COLLATE utf8mb4_bin,_utf8mb4':' COLLATE utf8mb4_bin,CONVERT({$id} USING utf8mb4) COLLATE utf8mb4_bin)
            ELSE CONCAT(_utf8mb4'single:' COLLATE utf8mb4_bin,CONVERT(n.public_id USING utf8mb4) COLLATE utf8mb4_bin) END";
    }
    private function notificationScope(array $actor): array
    {
        return ["n.channel='in_app' AND ((n.recipient_type='user' AND n.recipient_id=:thread_user)
            OR (n.recipient_type='worker' AND :thread_enabled>0 AND n.recipient_id=:thread_worker))",
            ['thread_user'=>(int)$actor['user_id'],'thread_enabled'=>(int)($actor['worker_id']??0),'thread_worker'=>(int)($actor['worker_id']??0)]];
    }
    private function threadRange(array $actor,string $cursor,string $kind): array
    {
        $signer=new \WorkerAdmin\Infrastructure\Messaging\NotificationInboxCursor();
        [$where,$params]=$this->notificationScope($actor);
        if($cursor===''&&$kind!=='threads')throw new MessageOperationException('请刷新通知列表','NOTIFICATION_RESYNC_REQUIRED',409);
        if($cursor===''){
            $anchor=$this->db->fetch('SELECT COALESCE(MAX(n.id),0) max_id,COUNT(*) total FROM notifications n WHERE '.$where,$params);
            $state=$signer->begin($actor,(string)$anchor['max_id'],(string)$anchor['total']);
        }else $state=$signer->open($actor,$cursor,$kind);
        $params['thread_max']=$state['max'];$where.=' AND n.id<=:thread_max';
        $count=(string)$this->db->fetchValue('SELECT COUNT(*) FROM notifications n WHERE '.$where,$params);
        // Read flags may change without invalidation; deleted members require an explicit resync.
        if($count!==$state['count'])throw new MessageOperationException('通知浏览范围已变化，请下拉刷新','NOTIFICATION_RESYNC_REQUIRED',409);
        return [$signer,$state,$where,$params];
    }
    public function threads(array $actor,string $cursor=''): array
    {
        return $this->db->transaction(function()use($actor,$cursor):array{
            [$signer,$state,$where,$params]=$this->threadRange($actor,$cursor,'threads');
            $expr=$this->threadExpression();
            $sort="CONCAT(DATE_FORMAT(n.created_at,'%Y-%m-%d %H:%i:%s'),LPAD(CAST(n.id AS CHAR),20,'0'))";
            $after='';
            if($state['before']!==''){$after=' WHERE g.sort_key<:thread_before';$params['thread_before']=$state['before'];}
            $rows=$this->db->fetchAll("SELECT g.thread_key,g.thread_count,g.unread_count,g.sort_key,n.public_id,n.recipient_type,n.template_key,n.title,n.content,n.created_at,n.read_at
                FROM (SELECT {$expr} thread_key,COUNT(*) thread_count,SUM(n.read_at IS NULL) unread_count,MAX({$sort}) sort_key
                    FROM notifications n WHERE {$where} GROUP BY thread_key) g
                JOIN notifications n ON n.id=CAST(SUBSTRING(g.sort_key,20) AS UNSIGNED){$after} ORDER BY g.sort_key DESC LIMIT 21",$params);
            $more=count($rows)>20;$rows=array_slice($rows,0,20);$items=[];$orders=$this->threadOrders($actor,$rows);
            foreach($rows as $row){
                $item=$this->threadItem($row,$orders);
                $item['thread_cursor']=$signer->seal($state,'messages',(string)$row['thread_key']);
                $items[]=$item;
            }
            return ['schema'=>2,'items'=>$items,'has_more'=>$more,
                'next_cursor'=>$more?$signer->seal($state,'threads','',(string)end($rows)['sort_key']):'',
                'range_cursor'=>$signer->seal($state,'range'),'unread_count'=>$this->notificationUnread($actor)];
        });
    }
    public function threadMessages(array $actor,string $cursor): array
    {
        return $this->db->transaction(function()use($actor,$cursor):array{
            [$signer,$state,$where,$params]=$this->threadRange($actor,$cursor,'messages');
            $expr=$this->threadExpression();
            $sort="CONCAT(DATE_FORMAT(n.created_at,'%Y-%m-%d %H:%i:%s'),LPAD(CAST(n.id AS CHAR),20,'0'))";
            $params['thread_key']=$state['thread'];$where.=" AND ({$expr})=:thread_key";
            if($state['before']!==''){$where.=" AND {$sort}<:thread_before";$params['thread_before']=$state['before'];}
            $rows=$this->db->fetchAll("SELECT n.public_id,n.recipient_type,n.template_key,n.title,n.content,n.read_at,n.created_at,{$sort} sort_key
                FROM notifications n WHERE {$where} ORDER BY n.created_at DESC,n.id DESC LIMIT 21",$params);
            $more=count($rows)>20;$rows=array_slice($rows,0,20);
            $items=array_map(fn(array $row):array=>$this->notificationItem($row),$rows);
            return ['schema'=>2,'thread_key'=>$state['thread'],'items'=>$items,'has_more'=>$more,
                'next_cursor'=>$more?$signer->seal($state,'messages',$state['thread'],(string)end($rows)['sort_key']):''];
        });
    }
    public function readRange(array $actor,string $cursor): void
    {
        $this->db->transaction(function()use($actor,$cursor):void{
            [,,$where,$params]=$this->threadRange($actor,$cursor,'range');
            $this->db->execute('UPDATE notifications n SET n.read_at=UTC_TIMESTAMP() WHERE '.$where.' AND n.read_at IS NULL',$params);
        });
    }
    private function notificationUnread(array $actor): int
    {
        [$where,$params]=$this->notificationScope($actor);
        return (int)$this->db->fetchValue('SELECT COUNT(*) FROM notifications n WHERE '.$where.' AND n.read_at IS NULL',$params);
    }
    private function notificationItem(array $row): array
    {
        return ['id'=>(string)$row['public_id'],'recipient_type'=>(string)$row['recipient_type'],
            'template_key'=>(string)$row['template_key'],'title'=>(string)$row['title'],'content'=>(string)$row['content'],
            'created_at'=>$this->localTime($row['created_at']),'read'=>!empty($row['read_at'])];
    }
    private function threadOrders(array $actor,array $rows): array
    {
        $ids=[];$result=[];
        foreach($rows as $row)if(strpos((string)$row['thread_key'],'single:')!==0){
            [$role,$id]=explode(':',(string)$row['thread_key'],2);$ids[$role][$id]=true;
        }
        foreach($ids as $role=>$values){
            [$scope,$params]=DatabaseOrderCenterRepository::scope($actor,$role==='worker'?'worker':'mine','all');
            $marks=[];foreach(array_keys($values) as $i=>$id){$name='thread_order_'.$i;$marks[]=':'.$name;$params[$name]=(string)$id;}
            foreach($this->db->fetchAll('SELECT o.id,o.public_no FROM orders o WHERE o.id IN ('.implode(',',$marks).') AND ('.$scope.')',$params) as $order)$result[$role.':'.$order['id']]=(string)$order['public_no'];
        }
        return $result;
    }
    private function threadItem(array $row,array $orders): array
    {
        $item=$this->notificationItem($row);$key=(string)$row['thread_key'];
        return $item+['thread_key'=>$key,'is_thread'=>strpos($key,'single:')!==0,'thread_count'=>(int)$row['thread_count'],
            'unread_count'=>(int)$row['unread_count'],'order_no'=>$orders[$key]??'',
            'detail_page'=>$row['recipient_type']==='worker'?'worker':'user'];
    }

    private function localTime($value): ?string
    {
        if (!$value) return null;
        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string) \app_config('app.timezone', 'Asia/Shanghai')))->format('Y-m-d H:i');
        } catch (Throwable $exception) { return (string) $value; }
    }
}

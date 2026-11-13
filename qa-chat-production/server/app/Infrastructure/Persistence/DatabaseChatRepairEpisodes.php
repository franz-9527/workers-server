<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;

/** Called only after the owning PHP repair command has authorized its actor. */
final class DatabaseChatRepairEpisodes
{
    private Database $db;
    private bool $enabled;
    public function __construct(Database $db,bool $enabled=false){$this->db=$db;$this->enabled=$enabled;}

    /** Record the worker's explicit acceptance, never infer it from processing. */
    public function acceptWorker(int $orderId,int $afterSaleId,int $workerId): ?string
    {
        if(!$this->enabled)return null;
        $order=$this->lockOrder($orderId);
        // Unsupported order profiles retain their existing repair workflow.
        // They never gain a chat episode until their own S04 policy is defined.
        if((int)$order['is_demo']!==0 || (string)$order['workflow_profile']!=='construction'
            || !in_array((string)$order['order_source_type'],['consumer','merchant','platform'],true))return null;
        $assignment=$this->db->fetch(
            'SELECT a.*,w.user_id worker_user_id,u.status worker_user_status FROM assignments a
             JOIN worker_profiles w ON w.id=a.worker_id JOIN users u ON u.id=w.user_id
             WHERE a.id=:id AND a.order_id=:order_id FOR UPDATE',
            ['id'=>$order['current_assignment_id']??0,'order_id'=>$orderId]
        );
        $repair=$this->db->fetch('SELECT * FROM after_sales WHERE id=:id AND order_id=:order_id FOR UPDATE',['id'=>$afterSaleId,'order_id'=>$orderId]);
        $customer=$this->db->fetch('SELECT status FROM users WHERE id=:id LOCK IN SHARE MODE',['id'=>$order['user_id']]);
        $platform=(string)$order['order_source_type']==='platform';
        $terms=$platform?$this->db->fetch('SELECT customer_flow_mode FROM order_platform_terms WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$orderId]):null;
        if((int)$order['is_demo']!==0 || (string)$order['lifecycle_status']!=='completed'
            || (string)$order['payment_status']!==($platform?'not_required':'paid') || (string)$order['workflow_profile']!=='construction'
            || ($platform && (($terms['customer_flow_mode']??'')!=='platform_managed' || (int)$order['booking_fee_cents']!==0))
            || !$assignment || (int)$assignment['worker_id']!==$workerId || (int)($order['assigned_worker_id']??0)!==$workerId
            || !in_array((string)$assignment['status'],['accepted','completed'],true) || empty($assignment['accepted_at'])
            || (string)$assignment['worker_user_status']!=='active'
            || !in_array($customer['status']??'', $platform?['active','platform_unbound']:['active'],true)
            || (string)$assignment['worker_user_id']===(string)$order['user_id']
            || !$repair || (string)$repair['type']!=='warranty_repair' || (string)$repair['status']!=='processing'
            || !empty($repair['closed_at']) || (string)$repair['user_id']!==(string)$order['user_id']) {
            throw new RuntimeException('售后聊天受理事实不满足真实订单与指派边界');
        }
        $current=$this->db->fetch('SELECT * FROM chat_repair_episodes WHERE active_order_id=:id FOR UPDATE',['id'=>$orderId]);
        if($current){
            if((string)$current['assignment_id']!==(string)$assignment['id'] || (int)$current['after_sale_id']!==$afterSaleId
                || (int)$current['worker_id']!==$workerId || (string)$current['accepted_user_id']!==(string)$assignment['worker_user_id']) {
                throw new RuntimeException('订单已有其他有效售后受理区间');
            }
            (new DatabaseOrderConversationBridge($this->db,true))->schedule($orderId);
            return (string)$current['id'];
        }
        $id=$this->db->insert("INSERT INTO chat_repair_episodes(order_id,assignment_id,after_sale_id,worker_id,accepted_user_id,state,accepted_at)
            VALUES(:order_id,:assignment_id,:after_sale_id,:worker_id,:user_id,'active',UTC_TIMESTAMP())",
            ['order_id'=>$orderId,'assignment_id'=>$assignment['id'],'after_sale_id'=>$afterSaleId,'worker_id'=>$workerId,'user_id'=>$assignment['worker_user_id']]);
        (new DatabaseOrderConversationBridge($this->db,true))->schedule($orderId);
        return (string)$id;
    }

    /** A future acceptance must INSERT a new immutable episode, never reopen this row. */
    public function close(int $orderId,int $afterSaleId): void
    {
        if(!$this->enabled)return;
        $this->lockOrder($orderId);
        $this->db->execute("UPDATE chat_repair_episodes SET state='closed',closed_at=UTC_TIMESTAMP()
            WHERE order_id=:order_id AND after_sale_id=:after_sale_id AND state='active'",['order_id'=>$orderId,'after_sale_id'=>$afterSaleId]);
        (new DatabaseOrderConversationBridge($this->db,true))->schedule($orderId);
    }

    private function lockOrder(int $id): array
    {
        if($id<1 || !$this->db->pdo()->inTransaction())throw new RuntimeException('售后聊天事实必须加入原订单事务');
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$id]);
        if(!$order)throw new RuntimeException('售后订单不存在');
        return $order;
    }
}

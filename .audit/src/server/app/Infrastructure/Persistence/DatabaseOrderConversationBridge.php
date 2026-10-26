<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Domain\Chat\OrderConversationPolicy;

/** Called inside the owning order transaction; never opens an independent transaction. */
final class DatabaseOrderConversationBridge
{
    private Database $db;
    private OrderConversationPolicy $policy;
    private bool $enabled;
    public function __construct(Database $db, bool $enabled=false)
    { $this->db=$db;$this->enabled=$enabled;$this->policy=new OrderConversationPolicy(); }

    /** Coalesce every event for this order until the original transaction's final facts exist. */
    public function schedule(int $orderID): void
    {
        if (!$this->enabled) return;
        if ($orderID<1) throw new RuntimeException('聊天授权订单编号无效');
        $this->db->beforeCommit('chat-policy:'.$orderID,function()use($orderID):void{$this->synchronize($orderID);});
    }

    /** @return string[] changed scope keys; no messages/Go tables/notifications are written. */
    public function synchronize(int|string $orderID,bool $apply=true): array
    {
        if ($apply && !$this->enabled) return [];
        if ($orderID<1 || !$this->db->pdo()->inTransaction()) throw new RuntimeException('聊天授权必须加入原订单事务');
        // Lock order first, then current authority reads, then the guard. Plain
        // reads could reuse an earlier REPEATABLE READ snapshot and grant stale accounts.
        $order=$this->db->fetch('SELECT * FROM orders WHERE id=:id FOR UPDATE',['id'=>$orderID]);
        if (!$order) throw new RuntimeException('聊天授权订单不存在');
        $assignment=$this->db->fetch(
            'SELECT a.*,CAST(w.user_id AS CHAR) worker_user_id,u.status worker_user_status
             FROM assignments a JOIN worker_profiles w ON w.id=a.worker_id
             JOIN users u ON u.id=w.user_id WHERE a.id=:id AND a.order_id=:order_id LIMIT 1 LOCK IN SHARE MODE',
            ['id'=>$order['current_assignment_id'] ?? 0,'order_id'=>$orderID]
        );
        $customer=$this->db->fetch('SELECT status FROM users WHERE id=:id LIMIT 1 LOCK IN SHARE MODE',['id'=>$order['user_id']]);
        $facts=[
            'order_id'=>(string)$orderID,'customer_id'=>(string)$order['user_id'],
            'customer_status'=>(string)($customer['status'] ?? ''),'is_demo'=>(int)$order['is_demo'],
            'workflow_profile'=>(string)$order['workflow_profile'],'order_source_type'=>(string)$order['order_source_type'],
            'lifecycle_status'=>(string)$order['lifecycle_status'],'payment_status'=>(string)$order['payment_status'],
            'assignment_status'=>(string)$order['assignment_status'],
            'current_assignment_id'=>(string)($order['current_assignment_id'] ?? ''),
            'assigned_worker_id'=>(string)($order['assigned_worker_id'] ?? ''),
            'assignment_id'=>(string)($assignment['id'] ?? ''),'assignment_order_id'=>(string)($assignment['order_id'] ?? ''),
            'worker_id'=>(string)($assignment['worker_id'] ?? ''),'worker_user_id'=>(string)($assignment['worker_user_id'] ?? ''),
            'worker_user_status'=>(string)($assignment['worker_user_status'] ?? ''),
            'assignment_record_status'=>(string)($assignment['status'] ?? ''),'assignment_accepted_at'=>$assignment['accepted_at'] ?? null,
        ];
        if ($facts['order_source_type']==='platform') {
            $terms=$this->db->fetch('SELECT customer_flow_mode FROM order_platform_terms WHERE order_id=:id LOCK IN SHARE MODE',['id'=>$orderID]);
            $facts['customer_flow_mode']=(string)($terms['customer_flow_mode']??'');
            $facts['booking_fee_cents']=(int)($order['booking_fee_cents']??-1);
            $facts=array_merge($facts,(new DatabaseChatPlatformStaff($this->db))->readAuthorityLocked());
        }
        if($facts['lifecycle_status']==='completed' && $facts['workflow_profile']==='construction'
            && in_array($facts['order_source_type'],['consumer','merchant','platform'],true)) {
            $episode=$this->db->fetch(
                'SELECT e.*,a.order_id repair_order_id,a.user_id repair_user_id,a.type repair_type,
                    a.status repair_status,a.closed_at repair_closed_at
                 FROM chat_repair_episodes e JOIN after_sales a ON a.id=e.after_sale_id
                 WHERE e.active_order_id=:id LIMIT 1 LOCK IN SHARE MODE',['id'=>$orderID]
            );
            if($episode)$facts['repair_episode']=$episode;
        }
        $snapshot=$this->policy->evaluate($facts);
        if($facts['order_source_type']==='merchant'&&$facts['is_demo']===0&&\app_config('chat.collaboration_enabled',false)===true)
            $snapshot=(new DatabaseCollaborationAuthority($this->db))->resolve($order,$facts,$snapshot,$apply);
        // Bounded display facts travel with the same immutable PHP authority hash.
        $context=['public_no'=>(string)$order['public_no'],
            'title'=>mb_substr((string)preg_replace('/[\\p{Cc}\\p{Cf}]+/u',' ',(string)($order['title']??'')),0,120,'UTF-8'),
            'workflow'=>(string)$order['workflow_profile']];
        $open=$this->db->fetch('SELECT * FROM chat_policy_guards WHERE active_order_id=:id FOR UPDATE',['id'=>$orderID]);
        $changed=[];
        if ($open && (!$snapshot || $open['scope_key'] !== $snapshot['scope_key'])) {
            $closed=json_decode((string)$open['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
            $closed['state']='readonly';
            $closed['order_context']=$context;
            foreach ($closed['members'] as &$member) $member['can_send']=false;
            unset($member);
            if ($this->persist($closed,$open,$apply)) $changed[]=$closed['scope_key'];
        }
        if ($snapshot) {
            $existing=$this->db->fetch('SELECT * FROM chat_policy_guards WHERE scope_key=:scope FOR UPDATE',['scope'=>$snapshot['scope_key']]);
            $previous=$existing?json_decode((string)$existing['snapshot_json'],true,32,JSON_THROW_ON_ERROR):null;
            // Never manufacture historical membership during a backfill/late event.
            if (!$existing && $snapshot['state']==='readonly') return $changed;
            // Keep the closing repair identity, rather than silently replacing it
            // with the old service episode. S05 needs that precise boundary.
            if($previous && $snapshot['state']==='readonly' && ($snapshot['schema_version']??1)!==3) {
                $snapshot=$previous;$snapshot['state']='readonly';
                foreach($snapshot['members'] as &$member)$member['can_send']=false;unset($member);
            }
            // Only an explicit, different accepted repair generation can reopen.
            if ($existing && $existing['state']==='readonly' && $snapshot['state']==='open' && ($snapshot['schema_version']??1)!==3) {
                $newRepair=isset($facts['repair_episode']) && $snapshot['episode_key']!==$previous['episode_key'];
                if(!$newRepair){$snapshot=$previous;$snapshot['state']='readonly';foreach ($snapshot['members'] as &$member)$member['can_send']=false;unset($member);}
            }
            $snapshot['order_context']=$context;
            if ($this->persist($snapshot,$existing,$apply)) $changed[]=$snapshot['scope_key'];
        }
        if($apply && in_array($facts['order_source_type'],['platform','merchant'],true)) {
            $this->db->execute("INSERT INTO chat_policy_refresh(order_id,checked_at,next_check_at,status)
                VALUES(:id,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 60 SECOND,'success')
                ON DUPLICATE KEY UPDATE checked_at=VALUES(checked_at),next_check_at=VALUES(next_check_at),status='success'",['id'=>$orderID]);
        }
        return $changed;
    }

    private function persist(array $snapshot, ?array $existing,bool $apply): bool
    {
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json)>32768) throw new RuntimeException('聊天授权快照超过上限');
        $hash=hash('sha256',$json);
        if ($existing && hash_equals((string)$existing['snapshot_hash'],$hash)) return false;
        if (!$apply) return true;
        $params=['scope'=>$snapshot['scope_key'],'order_id'=>$snapshot['order_id'],'assignment_id'=>$snapshot['assignment_id'],
            'state'=>$snapshot['state'],'json'=>$json,'hash'=>$hash];
        if (!$existing) {
            $this->db->execute('INSERT INTO chat_policy_guards(scope_key,order_id,assignment_id,policy_version,state,snapshot_json,snapshot_hash,updated_at)
                VALUES(:scope,:order_id,:assignment_id,1,:state,:json,:hash,UTC_TIMESTAMP())',$params);
        } else {
            unset($params['order_id'],$params['assignment_id']);$params['expected']=(string)$existing['policy_version'];
            $count=$this->db->execute('UPDATE chat_policy_guards SET state=:state,snapshot_json=:json,snapshot_hash=:hash,policy_version=policy_version+1,updated_at=UTC_TIMESTAMP()
                WHERE scope_key=:scope AND policy_version=:expected',$params);
            if ($count!==1) throw new RuntimeException('聊天授权版本已变化');
        }
        $version=(string)$this->db->fetchValue('SELECT CAST(policy_version AS CHAR) FROM chat_policy_guards WHERE scope_key=:scope',['scope'=>$snapshot['scope_key']]);
        $payload=json_encode(['schema_version'=>1,'policy_version'=>$version,'snapshot'=>$snapshot],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->db->execute("INSERT INTO chat_business_events(event_id,aggregate_key,aggregate_version,schema_version,event_type,payload_json,created_at)
            VALUES(:event_id,:scope,:version,1,'chat.policy.changed',:payload,UTC_TIMESTAMP())",
            ['event_id'=>bin2hex(random_bytes(16)),'scope'=>$snapshot['scope_key'],'version'=>$version,'payload'=>$payload]);
        return true;
    }
}

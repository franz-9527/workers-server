<?php

declare(strict_types=1);

namespace WorkerAdmin\Infrastructure\Persistence;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WorkerAdmin\Core\Database;

/** Bounded, resumable PHP projection only; never creates a Go message or interval. */
final class DatabaseChatPolicyMaintenance
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }

    /** Read-only enablement gate; it neither toggles features nor changes epochs. */
    public function preflight(): array
    {
        $expected=['chat_staff_settings_insert'=>'system_settings','chat_staff_settings_update'=>'system_settings','chat_staff_settings_delete'=>'system_settings',
            'chat_staff_user_insert'=>'users','chat_staff_user_update'=>'users','chat_staff_user_delete'=>'users',
            'chat_staff_wechat_insert'=>'wechat_identities','chat_staff_wechat_update'=>'wechat_identities','chat_staff_wechat_delete'=>'wechat_identities'];
        $rows=$this->db->fetchAll("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'chat_staff_%'");
        $actual=[];
        foreach($rows as $row){
            $name=(string)$row['TRIGGER_NAME'];
            if(!isset($expected[$name]))continue;
            if($row['ACTION_TIMING']!=='AFTER' || strtolower((string)$row['EVENT_MANIPULATION'])!==substr($name,strrpos($name,'_')+1)
                || $row['EVENT_OBJECT_TABLE']!==$expected[$name])throw new RuntimeException('工作人员撤权触发器定义不匹配');
            $actual[$name]=true;
        }
        if(count($actual)!==count($expected))throw new RuntimeException('工作人员撤权触发器缺失或不可核验');
        if(\app_config('chat.collaboration_enabled',false)===true){
            $names=['chat_collaboration_order_update','chat_collaboration_address_insert','chat_collaboration_address_update','chat_collaboration_address_delete','chat_collaboration_user_update'];
            $triggers=$this->db->fetchAll("SELECT TRIGGER_NAME,ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'chat_collaboration_%'");
            $valid=[];foreach($triggers as $trigger)if(in_array($trigger['TRIGGER_NAME'],$names,true)&&$trigger['ACTION_TIMING']==='AFTER')$valid[$trigger['TRIGGER_NAME']]=true;
            if(count($valid)!==5)throw new RuntimeException('协作群即时撤权触发器缺失');
            $nullable=(int)$this->db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('chat_policy_guards','chat_conversations') AND COLUMN_NAME='assignment_id' AND IS_NULLABLE='YES'");
            $identities=(int)$this->db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_messages' AND COLUMN_NAME IN ('sender_role_source','sender_display_role','sender_display_role_version')");
            $unique=(int)$this->db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chat_conversations' AND INDEX_NAME='uq_chat_collaboration_order' AND NON_UNIQUE=0");
            if($nullable!==2||$identities!==3||$unique!==2)throw new RuntimeException('协作群绑定或消息身份结构不完整');
            foreach(['chat_collaboration_groups','chat_collaboration_members','chat_collaboration_invites','chat_collaboration_settings','chat_collaboration_events'] as $table)
                if(!$this->db->tableExists($table))throw new RuntimeException('协作群结构未完成升级');
        }
        $tables=['chat_policy_guards','chat_business_events','chat_repair_episodes','chat_staff_authority_versions','chat_policy_refresh',
            'order_platform_terms','order_pricing_items','platform_order_refund_reviews','platform_order_manual_receipts'];
        foreach($tables as $table)if(!$this->db->tableExists($table))throw new RuntimeException('聊天授权依赖表缺失');
        $config=$this->db->fetchValue('SELECT CAST(authority_version AS CHAR) FROM chat_staff_authority_versions WHERE user_id=0');
        $missing=(int)$this->db->fetchValue('SELECT COUNT(*) FROM users u LEFT JOIN chat_staff_authority_versions a ON a.user_id=u.id WHERE a.user_id IS NULL OR a.authority_version=0');
        if(!$config || $config==='0' || $missing>0)throw new RuntimeException('聊天权限代次种子缺失');
        return ['ready'=>true,'schema_target'=>'2.7.0','revocation_triggers'=>count($actual),'policy_enabled'=>$this->enabled()];
    }

    public function backfill(int $after,int $limit=100,bool $apply=false): array
    {
        if($after<0 || $limit<1 || $limit>100) throw new InvalidArgumentException('回填游标无效或每批超过100笔');
        if($apply && !$this->enabled()) throw new RuntimeException('订单聊天授权开关未启用，不能执行回填');
        $this->preflight();
        $rows=$this->db->fetchAll("SELECT o.id FROM orders o WHERE o.id>:after AND o.is_demo=0
            AND ((o.lifecycle_status='active' AND o.current_assignment_id IS NOT NULL)
                OR EXISTS(SELECT 1 FROM chat_policy_guards g WHERE g.order_id=o.id)
                OR EXISTS(SELECT 1 FROM chat_repair_episodes e WHERE e.active_order_id=o.id))
            ORDER BY o.id LIMIT ".($limit+1),['after'=>$after]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
        $result=['mode'=>$apply?'apply':'dry_run','scanned'=>0,'changed'=>0,'next_after'=>$after,'has_more'=>$more];
        foreach($rows as $row){
            $id=(int)$row['id'];
            $scopes=$this->db->transaction(function()use($id,$apply):array{
                return (new DatabaseOrderConversationBridge($this->db,$apply))->synchronize($id,$apply);
            });
            $result['scanned']++;$result['changed']+=count($scopes);$result['next_after']=$id;
        }
        return $result;
    }

    /** Existing all scheduler calls once; LIMIT is a hard per-run budget. */
    public function refresh(int $limit=100): array
    {
        $limit=max(1,min(100,$limit));
        $result=['task'=>'chat_policy_refresh','status'=>'success','scanned'=>0,'success'=>0,'failure'=>0];
        if(!$this->enabled())return $result;
        if((int)$this->db->fetchValue("SELECT GET_LOCK(CONCAT('chat-policy:',SHA1(DATABASE())),0)")!==1){$result['status']='skipped';return $result;}
        try{
            $rows=$this->db->fetchAll("SELECT g.order_id FROM chat_policy_guards g
                JOIN orders o ON o.id=g.order_id AND o.order_source_type IN ('platform','merchant') AND o.is_demo=0
                LEFT JOIN chat_policy_refresh r ON r.order_id=g.order_id
                WHERE (g.state='open' OR o.order_source_type='merchant') AND (r.order_id IS NULL OR r.next_check_at<=UTC_TIMESTAMP())
                ORDER BY COALESCE(r.next_check_at,'1970-01-01'),g.order_id LIMIT {$limit}");
            foreach($rows as $row){
                $id=(int)$row['order_id'];$result['scanned']++;
                try{
                    $this->db->transaction(function()use($id):void{(new DatabaseOrderConversationBridge($this->db,true))->synchronize($id);});
                    $result['success']++;
                    $this->db->execute("UPDATE scheduled_job_failures SET resolved_at=UTC_TIMESTAMP()
                        WHERE job_name='chat_policy_refresh' AND business_key=:key AND resolved_at IS NULL",['key'=>'order:'.$id]);
                }catch(Throwable $error){
                    $result['failure']++;$result['status']='failed';
                    // A broken first row cannot starve later work. No SQL, token,
                    // identity list or message content is copied to diagnostics.
                    $this->db->execute("INSERT INTO chat_policy_refresh(order_id,checked_at,next_check_at,status)
                        VALUES(:id,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 5 MINUTE,'failed')
                        ON DUPLICATE KEY UPDATE checked_at=VALUES(checked_at),next_check_at=VALUES(next_check_at),status='failed'",['id'=>$id]);
                    $this->db->execute("INSERT INTO scheduled_job_failures(job_name,business_key,business_version,error_class,error_message,next_retry_at,created_at)
                        VALUES('chat_policy_refresh',:key,1,'CHAT_POLICY_REFRESH_FAILED','聊天授权投影恢复失败，请检查事务与升级状态',UTC_TIMESTAMP()+INTERVAL 5 MINUTE,UTC_TIMESTAMP())",['key'=>'order:'.$id]);
                }
            }
        }finally{$this->db->fetchValue("SELECT RELEASE_LOCK(CONCAT('chat-policy:',SHA1(DATABASE())))");}
        return $result;
    }

    private function enabled(): bool
    {return function_exists('app_config') && \app_config('chat.order_policy_enabled',false)===true;}
}

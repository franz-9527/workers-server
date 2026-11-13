<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;

use WorkerAdmin\Core\Database;
use WorkerAdmin\Infrastructure\Messaging\DatabaseNotificationProducer;

/** Idempotently queues chat reports and verified call-pool alerts through the existing outbox. */
final class ChatOperationsNotifier
{
    public function __construct(private Database $db) {}
    public function project(int $limit=100):array
    {
        $limit=max(1,min(100,$limit));
        $result=['cases'=>0,'alerts'=>0];
        if(!\filter_var(\setting('wecom.webhook_enabled',false),FILTER_VALIDATE_BOOLEAN))return $result;
        $producer=new DatabaseNotificationProducer($this->db);
        if($this->db->tableExists('chat_cases')){
            $rows=$this->db->fetchAll("SELECT CAST(id AS CHAR) id,kind,CAST(conversation_id AS CHAR) conversation_id,created_at FROM chat_cases WHERE kind IN ('report','appeal') AND state='pending' AND created_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY ORDER BY id DESC LIMIT ".$limit);
            foreach($rows as $row){
                $id=(string)$row['id'];$label=$row['kind']==='appeal'?'申诉':'举报';
                $producer->adminTask('chat-case:'.$id,'chat_report_submitted','聊天'.$label.'待处理',[
                    '案件编号'=>$id,'事项'=>$label,'会话编号'=>(string)$row['conversation_id'],
                    '提交时间'=>(string)$row['created_at'],
                ]);
                $result['cases']++;
            }
        }
        if($this->db->tableExists('chat_call_resource_pools')&&$this->db->tableExists('chat_call_alerts')){
            // Refresh the same 20/10/0 threshold alerts used by call admission.
            (new ChatCallResources($this->db))->availability();
            $rows=$this->db->fetchAll("SELECT CAST(a.id AS CHAR) id,a.threshold_percent,p.provider,p.cycle_key FROM chat_call_alerts a JOIN chat_call_resource_pools p ON p.id=a.pool_id WHERE a.created_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY ORDER BY a.id DESC LIMIT ".$limit);
            foreach($rows as $row){
                $id=(string)$row['id'];$level=(int)$row['threshold_percent'];
                $producer->adminTask('chat-call-alert:'.$id,'chat_call_quota_low',
                    $level===0?'聊天通话资源不足':'聊天通话额度预警',[
                        '告警编号'=>$id,'供应商'=>$row['provider']==='shengwang'?'声网':'微信',
                        '权益周期'=>(string)$row['cycle_key'],'阈值'=>(string)$level.'%',
                    ]);
                $result['alerts']++;
            }
        }
        return $result;
    }
}

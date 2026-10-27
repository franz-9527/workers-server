<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;
use Throwable;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\SubscribeMessageGateway;
use WorkerAdmin\Infrastructure\Messaging\ChatReminderTemplate;
use WorkerAdmin\Infrastructure\Messaging\SubscriptionQuotaStore;
/** Reuses the existing gateway/quota; no copied channel, SMS or billing stack. */
final class ChatReminderDispatch
{
    private Database $db;private SubscribeMessageGateway $gateway;private $eligible;
    public function __construct(Database $db,SubscribeMessageGateway $gateway,callable $eligible){$this->db=$db;$this->gateway=$gateway;$this->eligible=$eligible;}
    public function dispatch(int $limit=20):array
    {
        if($this->db->pdo()->inTransaction())throw new RuntimeException('CHAT_SEND_TRANSACTION');
        $limit=max(1,min(50,$limit));
        // A dead process after entering the channel has an unknown result. It
        // cannot re-enter generic failed retries or trigger an SMS fallback.
        $this->db->execute("UPDATE chat_reminder_receipts SET state='provider_unknown',diagnostic='provider_unknown',updated_at=UTC_TIMESTAMP(6) WHERE state='sending' AND updated_at<UTC_TIMESTAMP(6)-INTERVAL 5 MINUTE");
        $rows=$this->db->fetchAll("SELECT o.id,o.payload_json FROM message_outbox o JOIN chat_reminder_receipts r ON r.outbox_id=o.id WHERE o.topic='chat_reminder' AND o.status IN ('pending','failed','processing') AND r.state='accepted' AND o.attempt_count<8 AND (o.next_attempt_at IS NULL OR o.next_attempt_at<=UTC_TIMESTAMP()) ORDER BY o.id LIMIT ".$limit);
        $stats=['scanned'=>count($rows),'sent'=>0,'suppressed'=>0,'unknown'=>0,'retry'=>0];
        foreach($rows as $row){
            $id=(int)$row['id'];$q=json_decode((string)$row['payload_json'],true);$op=(string)($q['operation_id']??'');
            if(preg_match('/^[a-f0-9]{64}$/D',$op)!==1)continue;
            $claimed=$this->db->execute("UPDATE message_outbox SET status='processing',attempt_count=attempt_count+1,next_attempt_at=UTC_TIMESTAMP()+INTERVAL 1 MINUTE WHERE id=:id AND topic='chat_reminder' AND (status IN ('pending','failed') OR (status='processing' AND next_attempt_at<=UTC_TIMESTAMP()))",['id'=>$id]);
            if($claimed!==1)continue;
            $entered=false;$payload=null;
            try{
                if(!(($this->eligible)($op))){$this->finish($op,$id,'suppressed','read_or_permission_changed');$stats['suppressed']++;continue;}
                $built=ChatReminderTemplate::build($this->db,$q);
                if(!isset($built['payload'])){$this->finish($op,$id,'channel_limited',(string)$built['diagnostic']);$stats['suppressed']++;continue;}
                // Final check is after metadata/permissions and immediately
                // before recording the irreversible provider boundary.
                if(!(($this->eligible)($op))){$this->finish($op,$id,'suppressed','read_or_permission_changed');$stats['suppressed']++;continue;}
                $entered=$this->db->transaction(function()use($op,$q,&$payload):bool{
                    $receipt=$this->db->fetch('SELECT state FROM chat_reminder_receipts WHERE operation_id=:op FOR UPDATE',['op'=>$op]);
                    if(!$receipt||$receipt['state']!=='accepted')return false;
                    $this->db->fetch("SELECT setting_key FROM system_settings WHERE setting_key=:key FOR UPDATE",['key'=>'wechat.subscription.user.'.(int)$q['recipient_id']]);
                    $current=ChatReminderTemplate::build($this->db,$q);if(!isset($current['payload']))return false;
                    $payload=$current['payload'];
                    // Reserve once, including provider-unknown outcomes.
                    SubscriptionQuotaStore::markSubscriptionConsumed($this->db,(int)$q['recipient_id'],'chat_unread');
                    $this->db->execute("UPDATE chat_reminder_receipts SET state='sending',diagnostic='channel_entered',updated_at=UTC_TIMESTAMP(6) WHERE operation_id=:op",['op'=>$op]);return true;
                });
                if(!$entered){$this->finish($op,$id,'suppressed','subscription_changed');$stats['suppressed']++;continue;}
                $response=$this->gateway->send($payload);
                if(!array_key_exists('errcode',$response)||!is_int($response['errcode'])||$response['errcode']!==0)throw new RuntimeException('CHAT_PROVIDER_RESULT_UNKNOWN');
                $this->finish($op,$id,'sent','sent');$stats['sent']++;
            }catch(Throwable $error){
                if($entered&&in_array($error->getCode(),[-1,40001,40003,40014,40037,41030,42001,43101,45009,47003],true)){
                    $this->db->transaction(function()use($q,$payload,$error,$op,$id):void{
                        $uid=(int)$q['recipient_id'];
                        $this->db->fetch('SELECT setting_key FROM system_settings WHERE setting_key=:key FOR UPDATE',['key'=>'wechat.subscription.user.'.$uid]);
                        if($error->getCode()===43101){
                            $pref=SubscriptionQuotaStore::subscriptionPreferences($this->db,$uid)['chat_unread']??[];
                            if((string)($pref['template_id']??'')===(string)$payload['template_id'])SubscriptionQuotaStore::markSubscriptionPermissionUnavailable($this->db,$uid,'chat_unread');
                        }else SubscriptionQuotaStore::restoreSubscriptionConsumed($this->db,$uid,'chat_unread',(string)$payload['template_id']);
                        $this->finish($op,$id,'channel_limited','wechat_rejected_'.(string)$error->getCode());
                    });
                    $stats['suppressed']++;
                }elseif($entered){$this->finish($op,$id,'provider_unknown','provider_unknown');$stats['unknown']++;}
                else{$this->db->execute("UPDATE message_outbox SET status='failed',next_attempt_at=UTC_TIMESTAMP()+INTERVAL 1 MINUTE,last_error='chat_eligibility_unavailable' WHERE id=:id",['id'=>$id]);$stats['retry']++;}
            }
        }
        return $stats;
    }
    private function finish(string $op,int $id,string $state,string $diagnostic):void
    {
        $this->db->transaction(function()use($op,$id,$state,$diagnostic):void{
            $this->db->execute("UPDATE chat_reminder_receipts SET state=:state,diagnostic=:diagnostic,updated_at=UTC_TIMESTAMP(6) WHERE operation_id=:op AND state IN ('accepted','sending')",['state'=>$state,'diagnostic'=>$diagnostic,'op'=>$op]);
            $this->db->execute("UPDATE message_outbox SET status='processed',processed_at=UTC_TIMESTAMP(),next_attempt_at=NULL,last_error=:diagnostic WHERE id=:id AND topic='chat_reminder'",['diagnostic'=>$diagnostic,'id'=>$id]);
        });
    }
}


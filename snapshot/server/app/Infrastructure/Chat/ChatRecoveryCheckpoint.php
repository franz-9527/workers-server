<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Chat;

use PDO;
use RuntimeException;

/**
 * Compare safety facts after operator-controlled, continuous binlog replay.
 * Never replay messages, enqueue notification, charge wallets or invent missing increments.
 * Capture/verify require stopped chat processes and a separately protected control directory.
 */
final class ChatRecoveryCheckpoint
{
    private PDO $db;private string $environment;private string $key;private string $directory;private string $epoch;
    public function __construct(PDO $db,string $environment,string $key,string $directory,string $epoch)
    {
        if(!in_array($environment,['test','development','production'],true)||strlen($key)!==32
            ||!ChatRecoveryGate::path($directory)||!is_dir($directory)||is_link($directory)||(fileperms($directory)&0027)!==0
            ||preg_match('/^[a-f0-9]{64}$/D',$epoch)!==1)throw new RuntimeException('CHAT_RECOVERY_CONFIG');
        $this->db=$db;$this->environment=$environment;$this->key=$key;$this->directory=rtrim($directory,'/\\');$this->epoch=$epoch;
    }
    public function block(): void
    {$this->write('gate.json',['schema'=>1,'environment'=>$this->environment,'epoch'=>$this->epoch,'state'=>'blocked']);}
    private function blocked(): void
    {
        $m=$this->read('gate.json',1024);
        if($m!==['schema'=>1,'environment'=>$this->environment,'epoch'=>$this->epoch,'state'=>'blocked'])throw new RuntimeException('CHAT_RECOVERY_MAINTENANCE_REQUIRED');
    }
    /** Fingerprints only; no message bodies, source identities, media keys or channel payloads leave the DB. */
    public static function queries(): array
    {
        return [
            'chat_collaboration_groups'=>'SELECT * FROM chat_collaboration_groups ORDER BY order_id',
            'chat_collaboration_members'=>'SELECT * FROM chat_collaboration_members ORDER BY order_id,actor_id',
            'chat_collaboration_invites'=>'SELECT * FROM chat_collaboration_invites ORDER BY order_id,inviter_actor_id',
            'chat_collaboration_settings'=>'SELECT * FROM chat_collaboration_settings ORDER BY id',
            'chat_collaboration_events'=>'SELECT * FROM chat_collaboration_events ORDER BY id',
            'chat_storage_policy'=>'SELECT * FROM chat_storage_policy ORDER BY id',
            'chat_storage_policy_audit'=>'SELECT * FROM chat_storage_policy_audit ORDER BY id',
            'chat_storage_applications'=>'SELECT * FROM chat_storage_applications ORDER BY revision',
            'chat_storage_application_errors'=>'SELECT * FROM chat_storage_application_errors ORDER BY revision,message_id',
            'chat_av_uploads'=>'SELECT * FROM chat_av_uploads ORDER BY id',
            'chat_av_parts'=>'SELECT * FROM chat_av_parts ORDER BY upload_id,ordinal',
            'chat_av_jobs'=>'SELECT * FROM chat_av_jobs ORDER BY intent_id',
            'chat_av_objects'=>'SELECT * FROM chat_av_objects ORDER BY object_key',
            'chat_av_items'=>'SELECT * FROM chat_av_items ORDER BY message_id',
            'chat_av_listens'=>'SELECT * FROM chat_av_listens ORDER BY actor_id,message_id',
            'chat_av_raw_receipts'=>'SELECT * FROM chat_av_raw_receipts ORDER BY trace_id',
            'chat_av_asr_requests'=>'SELECT * FROM chat_av_asr_requests ORDER BY intent_id,ordinal',
            'chat_av_budgets'=>'SELECT * FROM chat_av_budgets ORDER BY actor_id',
            'chat_policy_guards'=>'SELECT id,order_id,assignment_id,policy_version,SHA2(snapshot_json,256) snapshot_hash FROM chat_policy_guards ORDER BY id',
            'chat_repair_episodes'=>'SELECT * FROM chat_repair_episodes ORDER BY id',
            'chat_policy_refresh'=>'SELECT * FROM chat_policy_refresh ORDER BY order_id',
            'chat_auditor_grant_audit'=>'SELECT * FROM chat_auditor_grant_audit ORDER BY id',
            'chat_audit_accounts'=>'SELECT * FROM chat_audit_accounts ORDER BY 1',
            'chat_audit_snapshots'=>'SELECT * FROM chat_audit_snapshots ORDER BY 1',
            'chat_audit_snapshot_items'=>'SELECT * FROM chat_audit_snapshot_items ORDER BY 1,2',
            'admin_authority'=>'SELECT id,role,status,must_change_password,SHA2(password_hash,256) password_version FROM admin_users ORDER BY id',
            'chat_staff_authority_versions'=>'SELECT user_id,authority_version FROM chat_staff_authority_versions ORDER BY user_id',
            'chat_admin_sessions'=>'SELECT id,session_version,authority_hash,scopes_json,expires_at,revoked_at FROM chat_admin_sessions ORDER BY id',
            'chat_mini_auditors'=>'SELECT user_id,enabled,authority_version FROM chat_mini_auditors ORDER BY user_id',
            'login_sessions'=>'SELECT id,user_id,status,expires_at,SHA2(token_hash,256) credential_hash,demo_actor_user_id,test_actor_user_id,test_instance_id,test_actor_expires_at FROM login_sessions ORDER BY id',
            'users'=>'SELECT id,status FROM users ORDER BY id',
            'wechat_identities'=>'SELECT id,user_id,SHA2(CONCAT(app_id,CHAR(10),openid),256) identity_hash,status FROM wechat_identities ORDER BY id',
            'staff_settings'=>"SELECT setting_key,SHA2(setting_value,256) value_hash FROM system_settings WHERE setting_key IN ('wechat.miniapp_appid','wechat.admin_openids') ORDER BY setting_key",
            'chat_business_events'=>'SELECT id,event_id,aggregate_key,aggregate_version,SHA2(payload_json,256) payload_hash FROM chat_business_events ORDER BY id',
            'chat_event_receipts'=>'SELECT * FROM chat_event_receipts ORDER BY event_id',
            'chat_conversations'=>'SELECT * FROM chat_conversations ORDER BY id',
            'chat_members'=>'SELECT * FROM chat_members ORDER BY conversation_id,actor_id',
            'chat_member_intervals'=>'SELECT * FROM chat_member_intervals ORDER BY conversation_id,actor_id,start_version',
            'chat_restrictions'=>'SELECT * FROM chat_restrictions ORDER BY actor_id',
            'chat_messages'=>'SELECT id,conversation_id,seq,sender_id,client_message_id,policy_version,message_type,state,state_version,retention_policy_version,retention_mode,expires_at,SHA2(COALESCE(text_body,""),256) body_hash,reply_to_message_id,edited_from_recalled_message_id,reply_to_item_id,recalled_at,sender_role_source,sender_display_role,sender_display_role_version FROM chat_messages ORDER BY id',
            'chat_message_changes'=>'SELECT * FROM chat_message_changes ORDER BY conversation_id,change_seq',
            'chat_send_intents'=>'SELECT * FROM chat_send_intents ORDER BY id',
            'chat_member_reads'=>'SELECT * FROM chat_member_reads ORDER BY conversation_id,actor_id',
            'chat_user_preferences'=>'SELECT * FROM chat_user_preferences ORDER BY actor_id,conversation_id',
            'chat_preference_accounts'=>'SELECT * FROM chat_preference_accounts ORDER BY actor_id',
            'chat_risk_events'=>'SELECT id,intent_id,confirmed,review_state,retain_until,SHA2(text_evidence,256) text_hash,SHA2(context_evidence,256) context_hash FROM chat_risk_events ORDER BY id',
            'chat_cases'=>'SELECT * FROM chat_cases ORDER BY id',
            'chat_image_uploads'=>'SELECT * FROM chat_image_uploads ORDER BY id',
            'chat_image_bundles'=>'SELECT * FROM chat_image_bundles ORDER BY intent_id',
            'chat_image_items'=>'SELECT * FROM chat_image_items ORDER BY message_id,item_id',
            'chat_image_objects'=>'SELECT * FROM chat_image_objects ORDER BY object_key',
            'chat_image_reports'=>'SELECT * FROM chat_image_reports ORDER BY id',
            'chat_image_audit'=>'SELECT * FROM chat_image_audit ORDER BY id',
            'chat_governance_audit'=>'SELECT * FROM chat_governance_audit ORDER BY id',
            'chat_reminder_events'=>'SELECT * FROM chat_reminder_events ORDER BY id',
            'chat_reminder_clusters'=>'SELECT * FROM chat_reminder_clusters ORDER BY id',
            'chat_reminder_budgets'=>'SELECT * FROM chat_reminder_budgets ORDER BY recipient_id',
            'chat_reminder_intents'=>'SELECT * FROM chat_reminder_intents ORDER BY id',
            'chat_reminder_receipts'=>'SELECT * FROM chat_reminder_receipts ORDER BY operation_id',
            'chat_reminder_outbox'=>"SELECT id,status,attempt_count,next_attempt_at,processed_at,SHA2(payload_json,256) payload_hash FROM message_outbox WHERE topic='chat_reminder' ORDER BY id",
        ];
    }
    public function facts(): array
    {
        if($this->db->inTransaction())throw new RuntimeException('CHAT_RECOVERY_TRANSACTION');
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try{
            $result=[];
            foreach(self::queries() as $name=>$query){
                // PDO unbuffered scan, one row in memory. JSON numeric values are
                // normalized to strings for driver/PHP patch-version stability.
                $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                $rows=$this->db->query($query);$hash=hash_init('sha256');$count=0;
                while($row=$rows->fetch(PDO::FETCH_ASSOC)){
                    foreach($row as &$value)if($value!==null)$value=(string)$value;unset($value);
                    hash_update($hash,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");$count++;
                }
                $rows->closeCursor();
                $result[$name]=['count'=>$count,'sha256'=>hash_final($hash)];
            }
            $this->db->commit();return$result;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw new RuntimeException('CHAT_RECOVERY_FACTS_UNAVAILABLE');}
        finally{$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
    }
    private function coordinate(): array
    {
        $row=$this->db->query('SHOW MASTER STATUS')->fetch(PDO::FETCH_ASSOC);
        $uuid=(string)$this->db->query('SELECT @@server_uuid')->fetchColumn();
        if(!$row||!isset($row['File'],$row['Position'])||$uuid==='')throw new RuntimeException('CHAT_RECOVERY_BINLOG_REQUIRED');
        return ['server_uuid'=>$uuid,'file'=>(string)$row['File'],'position'=>(string)$row['Position']];
    }
    public function capture(): array
    {
        $this->blocked();$before=$this->coordinate();$facts=$this->facts();$after=$this->coordinate();
        if($before!==$after)throw new RuntimeException('CHAT_RECOVERY_SOURCE_NOT_QUIESCENT');
        $payload=['schema'=>1,'environment'=>$this->environment,'epoch'=>$this->epoch,
            'captured_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),'watermark'=>$after,'facts'=>$facts];
        $json=self::encode($payload);$sealed=['payload'=>$payload,'mac'=>hash_hmac('sha256',$json,$this->key)];
        $this->write('checkpoint.json',$sealed);
        // Latest anchor is independent of backup/restore. Never accept any older signed file.
        $this->write('latest.json',['sha256'=>hash('sha256',self::encode($sealed)),'watermark'=>$after,'epoch'=>$this->epoch]);
        return ['status'=>'captured','tables'=>count($facts),'watermark'=>$after];
    }
    public function verify(bool $release=false): array
    {
        $this->blocked();$sealed=$this->read('checkpoint.json',65536);$latest=$this->read('latest.json',4096);
        $p=$sealed['payload']??null;$mac=$sealed['mac']??'';
        if(!is_array($p)||!is_string($mac)||!hash_equals(hash_hmac('sha256',self::encode($p),$this->key),$mac)
            ||($p['schema']??0)!==1||($p['environment']??'')!==$this->environment||($p['epoch']??'')!==$this->epoch
            ||($latest['epoch']??'')!==$this->epoch||($latest['watermark']??null)!==($p['watermark']??null)
            ||!hash_equals((string)($latest['sha256']??''),hash('sha256',self::encode($sealed))))throw new RuntimeException('CHAT_RECOVERY_CHECKPOINT_INVALID');
        $current=$this->facts();$missing=[];
        if(array_keys((array)($p['facts']??[]))!==array_keys($current))throw new RuntimeException('CHAT_RECOVERY_CONTRACT_CHANGED');
        foreach($current as $table=>$fact)if($fact!==$p['facts'][$table])$missing[]=$table;
        if($missing)return['status'=>'blocked','mismatched_tables'=>$missing,'notification_replayed'=>false];
        if($release){
            $continuity=$this->read('continuity.json',8192);$proof=$continuity['payload']??null;
            if(!is_array($proof)||($proof['schema']??0)!==1||($proof['environment']??'')!==$this->environment
                ||($proof['epoch']??'')!==$this->epoch||($proof['watermark']??null)!==$p['watermark']
                ||($proof['complete']??false)!==true
                ||!hash_equals(hash_hmac('sha256',self::encode($proof),$this->key),(string)($continuity['mac']??'')))throw new RuntimeException('CHAT_RECOVERY_INCREMENT_GAP');
        }
        if($release)$this->write('gate.json',['schema'=>1,'environment'=>$this->environment,'epoch'=>$this->epoch,'state'=>'ready']);
        return['status'=>$release?'ready':'verified','tables'=>count($current),'notification_replayed'=>false];
    }
    /** Explicit maintenance-account purge; normal Go audit grants remain INSERT/SELECT only. */
    public function pruneAudit(): array
    {
        $this->blocked();
        $progress=is_file($this->directory.'/audit-progress.json')?$this->read('audit-progress.json',4096):[];
        $rules=[
            'chat_governance_audit'=>"NOT EXISTS(SELECT 1 FROM chat_cases c WHERE c.retain_until>UTC_TIMESTAMP(6) AND (c.conversation_id=chat_governance_audit.conversation_id OR c.subject_id=chat_governance_audit.actor_id OR c.reporter_id=chat_governance_audit.actor_id))",
            'chat_image_audit'=>"NOT EXISTS(SELECT 1 FROM chat_cases c JOIN chat_send_intents i ON i.id=chat_image_audit.intent_id WHERE c.retain_until>UTC_TIMESTAMP(6) AND ((c.target_type='image_intent' AND c.target_id=i.id) OR (c.target_type='message' AND c.target_id=i.message_id)))",
            'chat_auditor_grant_audit'=>"NOT EXISTS(SELECT 1 FROM chat_cases c WHERE c.retain_until>UTC_TIMESTAMP(6) AND (c.subject_id=chat_auditor_grant_audit.user_id OR c.reporter_id=chat_auditor_grant_audit.user_id))",
        ];
        $counts=[];
        $this->journal(['action'=>'audit.prune.begin','epoch'=>$this->epoch,'days'=>365,'at'=>gmdate('c')]);
        foreach($rules as $table=>$hold){
            $after=(string)($progress[$table]??'0');
            if(preg_match('/^[0-9]{1,20}$/D',$after)!==1)throw new RuntimeException('CHAT_AUDIT_CURSOR');
            $stmt=$this->db->prepare('SELECT id FROM '.$table.' WHERE id>? ORDER BY id LIMIT 100');$stmt->execute([$after]);
            $ids=$stmt->fetchAll(PDO::FETCH_COLUMN);$removed=0;
            foreach($ids as $id){
                $delete=$this->db->prepare('DELETE FROM '.$table.' WHERE id=? AND created_at<UTC_TIMESTAMP(6)-INTERVAL 365 DAY AND '.$hold);
                $delete->execute([(string)$id]);$removed+=$delete->rowCount();
            }
            $progress[$table]=count($ids)<100?'0':(string)end($ids);
            $counts[$table]=['scanned'=>count($ids),'deleted'=>$removed,'next_after'=>$progress[$table]];
        }
        $this->write('audit-progress.json',$progress);
        $this->journal(['action'=>'audit.prune.complete','epoch'=>$this->epoch,'counts'=>$counts,'at'=>gmdate('c')]);
        return ['status'=>'maintenance_blocked','audit'=>$counts,'recapture_required'=>true];
    }
    private function journal(array $v): void
    {
        $file=$this->directory.'/maintenance.jsonl';
        if(is_link($file))throw new RuntimeException('CHAT_MAINTENANCE_LOG');
        $h=fopen($file,'ab');if(!$h)throw new RuntimeException('CHAT_MAINTENANCE_LOG');
        try{
            if(!chmod($file,0640)||!flock($h,LOCK_EX))throw new RuntimeException('CHAT_MAINTENANCE_LOG');
            $raw=self::encode($v)."\n";
            if(fwrite($h,$raw)!==strlen($raw)||!fflush($h)||!fsync($h))throw new RuntimeException('CHAT_MAINTENANCE_LOG');
        }finally{flock($h,LOCK_UN);fclose($h);}
        $this->syncDirectory();
    }
    private function read(string $file,int $limit): array
    {
        $path=$this->directory.'/'.$file;
        if(!is_file($path)||is_link($path)||filesize($path)>$limit||(fileperms($path)&0022)!==0)throw new RuntimeException('CHAT_RECOVERY_FILE_INVALID');
        $raw=file_get_contents($path);$v=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($v))throw new RuntimeException('CHAT_RECOVERY_FILE_INVALID');return$v;
    }
    private function write(string $name,array $value): void
    {
        $path=$this->directory.'/'.$name;$temporary=$path.'.'.bin2hex(random_bytes(8));
        $handle=fopen($temporary,'x');if(!$handle)throw new RuntimeException('CHAT_RECOVERY_WRITE');
        try{$raw=self::encode($value);if(!chmod($temporary,0640)||fwrite($handle,$raw)!==strlen($raw)||!fflush($handle)||!fsync($handle))throw new RuntimeException('CHAT_RECOVERY_WRITE');}
        finally{fclose($handle);}
        if(!rename($temporary,$path)){unlink($temporary);throw new RuntimeException('CHAT_RECOVERY_WRITE');}
        $this->syncDirectory();
    }
    private function syncDirectory(): void
    {
        $handle=fopen($this->directory,'r');
        if(!$handle)throw new RuntimeException('CHAT_RECOVERY_DURABILITY');
        try{if(!fsync($handle))throw new RuntimeException('CHAT_RECOVERY_DURABILITY');}finally{fclose($handle);}
    }
    private static function encode(array $v): string{return(string)json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
}

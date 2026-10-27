<?php
declare(strict_types=1);
namespace WorkerAdmin\Application\Chat;
use RuntimeException;
use WorkerAdmin\Core\Database;
final class ChatReminderReceipts
{
    private Database $db;
    public function __construct(Database $db){$this->db=$db;}
    public function receive(string $raw):array
    {
        $q=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(is_array($q)&&array_keys($q)===['operation_id']&&json_encode($q,JSON_THROW_ON_ERROR)===$raw)return $this->status((string)$q['operation_id']);
        $fields=['cluster_version','conversation_id','intent_id','operation_id','recipient_id','target_seq'];
        if(!is_array($q)||array_keys($q)!==$fields||json_encode($q,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)!==$raw)throw new RuntimeException('CHAT_REMINDER_INVALID');
        foreach($q as $key=>$value){
            if(!is_string($value)||preg_match($key==='operation_id'?'/^[a-f0-9]{64}$/D':'/^[1-9][0-9]{0,19}$/D',$value)!==1)throw new RuntimeException('CHAT_REMINDER_INVALID');
            if($key!=='operation_id'&&strlen($value)===20&&strcmp($value,'18446744073709551615')>0)throw new RuntimeException('CHAT_REMINDER_INVALID');
        }
        return $this->db->transaction(function()use($q,$raw):array{
            $hash=hash('sha256',$raw);
            $this->db->execute("INSERT IGNORE INTO chat_reminder_receipts(operation_id,payload_hash,payload_json,state,diagnostic,created_at,updated_at) VALUES(:op,:hash,:body,'accepted','queued',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",['op'=>$q['operation_id'],'hash'=>$hash,'body'=>$raw]);
            $row=$this->db->fetch('SELECT payload_hash,state,diagnostic,outbox_id FROM chat_reminder_receipts WHERE operation_id=:op FOR UPDATE',['op'=>$q['operation_id']]);
            if(!$row||!hash_equals((string)$row['payload_hash'],$hash))throw new RuntimeException('CHAT_REMINDER_CONFLICT');
            if($row['outbox_id']===null){
                $id=$this->db->insert("INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES('chat_reminder',:key,:payload,'pending',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())",['key'=>'chat:'.$q['operation_id'],'payload'=>$raw]);
                $this->db->execute('UPDATE chat_reminder_receipts SET outbox_id=:id WHERE operation_id=:op',['id'=>$id,'op'=>$q['operation_id']]);
            }
            return $this->result($q['operation_id'],$row);
        });
    }
    public function status(string $operation):array
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$operation)!==1)throw new RuntimeException('CHAT_REMINDER_INVALID');
        $row=$this->db->fetch('SELECT state,diagnostic FROM chat_reminder_receipts WHERE operation_id=:op',['op'=>$operation]);
        if(!$row)throw new RuntimeException('CHAT_REMINDER_NOT_FOUND');
        return $this->result($operation,$row);
    }
    private function result(string $operation,array $row):array
    {
        $state=in_array($row['state'],['sending','provider_unknown'],true)?'provider_unknown':(string)$row['state'];
        return ['operation_id'=>$operation,'state'=>$state,'diagnostic'=>(string)$row['diagnostic']];
    }
}

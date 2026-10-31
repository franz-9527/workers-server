<?php
declare(strict_types=1);
namespace WorkerAdmin\Infrastructure\Messaging;
use Throwable;
use RuntimeException;
use WorkerAdmin\Core\Database;
use WorkerAdmin\Contracts\MessageTestJournal;
/** 直接测试请求也进入发送记录；失败后不自动重发测试消息。 */
final class DatabaseMessageTestJournal implements MessageTestJournal
{
    private Database $db;
    public function __construct(Database $db) { $this->db=$db; }
    public function track(string $topic, array $metadata, callable $send): array
    {
        if($this->db->pdo()->inTransaction())throw new RuntimeException('外部消息不能在事务中发送。');
        if(!in_array($topic,['wecom_webhook','wechat_subscribe','aliyun_sms'],true))throw new RuntimeException('消息渠道无效。');
        $metadata['is_test']=true;
        $id=$this->db->insert("INSERT INTO message_outbox(topic,business_key,payload_json,status,attempt_count,next_attempt_at,created_at) VALUES(:topic,:key,:payload,'processing',1,NULL,UTC_TIMESTAMP())",[
            'topic'=>$topic,'key'=>'admin-test:'.bin2hex(random_bytes(12)),
            'payload'=>json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
        ]);
        try {
            $result=$send();
            if($topic==='aliyun_sms'){
                if(($result['Code']??'')!=='OK')throw new RuntimeException('短信服务未返回有效成功结果。');
                $receipt=['provider'=>'aliyun','provider_code'=>'OK','biz_id'=>(string)($result['BizId']??''),'request_id'=>(string)($result['RequestId']??'')];
            } else {
                if(!in_array($result['errcode']??null,[0,'0'],true))throw new RuntimeException('通知渠道未返回有效成功结果。');
                $receipt=(array)($result['delivery_receipt']??[]);
                $receipt['provider']=$topic==='wecom_webhook'?'wecom':'wechat';$receipt['errcode']=0;
            }
            $receipt['accepted_at']=gmdate('c');$receipt['delivery_status']='unconfirmed';
            (new DatabaseMessageDeliveryQueue($this->db))->completeWithReceipt((int)$id,$receipt);
            return $result;
        } catch(Throwable $e) {
            $error=preg_replace('#https?://qyapi\\.weixin\\.qq\\.com/\\S+#i','企业微信地址已隐藏',$e->getMessage());
            $this->db->execute("UPDATE message_outbox SET status='failed',next_attempt_at=NULL,last_error=:error WHERE id=:id",['id'=>$id,'error'=>mb_substr((string)$error,0,900)]);
            throw $e;
        }
    }
}
